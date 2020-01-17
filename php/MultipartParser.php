<?php

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RingCentral\Psr7;

/**
 * [Internal] Parses a string body with "Content-Type: multipart/form-data" into structured data
 *
 * This is use internally to parse incoming request bodies into structured data
 * that resembles PHP's `$_POST` and `$_FILES` superglobals.
 *
 * @internal
 * @link https://tools.ietf.org/html/rfc7578
 * @link https://tools.ietf.org/html/rfc2046#section-5.1.1
 */
final class MultipartParser
{
    /**
     * @var ServerRequestInterface|null
     */
    private $request;

    /**
     * @var int|null
     */
    private $maxFileSize;

    /**
     * ini setting "max_input_vars"
     *
     * Does not exist in PHP < 5.3.9 or HHVM, so assume PHP's default 1000 here.
     *
     * @var int
     * @link http://php.net/manual/en/info.configuration.php#ini.max-input-vars
     */
    private $maxInputVars = 1000;

    /**
     * ini setting "max_input_nesting_level"
     *
     * Does not exist in HHVM, but assumes hard coded to 64 (PHP's default).
     *
     * @var int
     * @link http://php.net/manual/en/info.configuration.php#ini.max-input-nesting-level
     */
    private $maxInputNestingLevel = 64;

    /**
     * ini setting "upload_max_filesize"
     *
     * @var int
     */
    private $uploadMaxFilesize;

    /**
     * ini setting "max_file_uploads"
     *
     * Additionally, setting "file_uploads = off" effectively sets this to zero.
     *
     * @var int
     */
    private $maxFileUploads;

    private $postCount = 0;
    private $filesCount = 0;
    private $emptyCount = 0;

    /**
     * @param int|string|null $uploadMaxFilesize
     * @param int|null $maxFileUploads
     */
    public function __construct($uploadMaxFilesize = null, $maxFileUploads = null)
    {
        $var = \ini_get('max_input_vars');
        if ($var !== false) {
            $this->maxInputVars = (int) $var;
        }
        $var = \ini_get('max_input_nesting_level');
        if ($var !== false) {
            $this->maxInputNestingLevel = (int) $var;
        }

        if ($uploadMaxFilesize === null) {
            $uploadMaxFilesize = \ini_get('upload_max_filesize');
        }

        $this->uploadMaxFilesize = IniUtil::iniSizeToBytes($uploadMaxFilesize);
        $this->maxFileUploads = $maxFileUploads === null ? (\ini_get('file_uploads') === '' ? 0 : (int) \ini_get('max_file_uploads')) : (int) $maxFileUploads;
    }

    public function parse(ServerRequestInterface $request)
    {
        $contentType = $request->getHeaderLine('content-type');
        if (!\preg_match('/boundary="?(.*)"?$/', $contentType, $matches)) {
            return $request;
        }

        $this->request = $request;
        $this->parseBody('--' . $matches[1], (string) $request->getBody());

        $request = $this->request;
        $this->request = null;
        $this->postCount = 0;
        $this->filesCount = 0;
        $this->emptyCount = 0;
        $this->maxFileSize = null;

        return $request;
    }

    private function parseBody($boundary, $buffer)
    {
        $len = \strlen($boundary);

        // ignore everything before initial boundary (SHOULD be empty)
        $start = \strpos($buffer, $boundary . "\r\n");

        while ($start !== false) {
            // search following boundary (preceded by newline)
            // ignore last if not followed by boundary (SHOULD end with "--")
            $start += $len + 2;
            $end = \strpos($buffer, "\r\n" . $boundary, $start);
            if ($end === false) {
                break;
            }

            // parse one part and continue searching for next
            $this->parsePart(\substr($buffer, $start, $end - $start));
            $start = $end;
        }
    }

    private function parsePart($chunk)
    {
        $pos = \strpos($chunk, "\r\n\r\n");
        if ($pos === false) {
            return;
        }

        $headers = $this->parseHeaders((string) substr($chunk, 0, $pos));
        $body = (string) \substr($chunk, $pos + 4);

        if (!isset($headers['content-disposition'])) {
            return;
        }

        $name = $this->getParameterFromHeader($headers['content-disposition'], 'name');
        if ($name === null) {
            return;
        }

        $filename = $this->getParameterFromHeader($headers['content-disposition'], 'filename');
        if ($filename !== null) {
            $this->parseFile(
                $name,
                $filename,
                isset($headers['content-type'][0]) ? $headers['content-type'][0] : null,
                $body
            );
        } else {
            $this->parsePost($name, $body);
        }
    }

    private function parseFile($name, $filename, $contentType, $contents)
    {
        $file = $this->parseUploadedFile($filename, $contentType, $contents);
        if ($file === null) {
            return;
        }

        $this->request = $this->request->withUploadedFiles($this->extractPost(
            $this->request->getUploadedFiles(),
            $name,
            $file
        ));
    }

    private function parseUploadedFile($filename, $contentType, $contents)
    {
        $size = \strlen($contents);

        // no file selected (zero size and empty filename)
        if ($size === 0 && $filename === '') {
            // ignore excessive number of empty file uploads
            if (++$this->emptyCount + $this->filesCount > $this->maxInputVars) {
                return;
            }

            return new UploadedFile(
                Psr7\stream_for(),
                $size,
                \UPLOAD_ERR_NO_FILE,
                $filename,
                $contentType
            );
        }

        // ignore excessive number of file uploads
        if (++$this->filesCount > $this->maxFileUploads) {
            return;
        }

        // file exceeds "upload_max_filesize" ini setting
        if ($size > $this->uploadMaxFilesize) {
            return new UploadedFile(
                Psr7\stream_for(),
                $size,
                \UPLOAD_ERR_INI_SIZE,
                $filename,
                $contentType
            );
        }

        // file exceeds MAX_FILE_SIZE value
        if ($this->maxFileSize !== null && $size > $this->maxFileSize) {
            return new UploadedFile(
                Psr7\stream_for(),
                $size,
                \UPLOAD_ERR_FORM_SIZE,
                $filename,
                $contentType
            );
        }

        return new UploadedFile(
            Psr7\stream_for($contents),
            $size,
            \UPLOAD_ERR_OK,
            $filename,
            $contentType
        );
    }

    private function parsePost($name, $value)
    {
        // ignore excessive number of post fields
        if (++$this->postCount > $this->maxInputVars) {
            return;
        }

        $this->request = $this->request->withParsedBody($this->extractPost(
            $this->request->getParsedBody(),
            $name,
            $value
        ));

        if (\strtoupper($name) === 'MAX_FILE_SIZE') {
            $this->maxFileSize = (int) $value;

            if ($this->maxFileSize === 0) {
                $this->maxFileSize = null;
            }
        }
    }

    private function parseHeaders($header)
    {
        $headers = array();

        foreach (\explode("\r\n", \trim($header)) as $line) {
            $parts = \explode(':', $line, 2);
            if (!isset($parts[1])) {
                continue;
            }

            $key = \strtolower(trim($parts[0]));
            $values = \explode(';', $parts[1]);
            $values = \array_map('trim', $values);
            $headers[$key] = $values;
        }

        return $headers;
    }

    private function getParameterFromHeader(array $header, $parameter)
    {
        foreach ($header as $part) {
            if (\preg_match('/' . $parameter . '="?(.*)"$/', $part, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function extractPost($postFields, $key, $value)
    {
        $chunks = \explode('[', $key);
        if (\count($chunks) == 1) {
            $postFields[$key] = $value;
            return $postFields;
        }

        // ignore this key if maximum nesting level is exceeded
        if (isset($chunks[$this->maxInputNestingLevel])) {
            return $postFields;
        }

        $chunkKey = \rtrim($chunks[0], ']');
        $parent = &$postFields;
        for ($i = 1; isset($chunks[$i]); $i++) {
            $previousChunkKey = $chunkKey;

            if ($previousChunkKey === '') {
                $parent[] = array();
                \end($parent);
                $parent = &$parent[\key($parent)];
            } else {
                if (!isset($parent[$previousChunkKey]) || !\is_array($parent[$previousChunkKey])) {
                    $parent[$previousChunkKey] = array();
                }
                $parent = &$parent[$previousChunkKey];
            }

            $chunkKey = \rtrim($chunks[$i], ']');
        }

        if ($chunkKey === '') {
            $parent[] = $value;
        } else {
            $parent[$chunkKey] = $value;
        }

        return $postFields;
    }
}

/**
 * @internal
 */
final class IniUtil
{
    /**
     * Convert a ini like size to a numeric size in bytes.
     *
     * @param string $size
     * @return int
     */
    public static function iniSizeToBytes($size)
    {
        if (\is_numeric($size)) {
            return (int) $size;
        }

        $suffix = \strtoupper(\substr($size, -1));
        $strippedSize = \substr($size, 0, -1);

        if (!\is_numeric($strippedSize)) {
            throw new \InvalidArgumentException("$size is not a valid ini size");
        }

        if ($strippedSize <= 0) {
            throw new \InvalidArgumentException("Expect $size to be higher isn't zero or lower");
        }

        if ($suffix === 'K') {
            return $strippedSize * 1024;
        }
        if ($suffix === 'M') {
            return $strippedSize * 1024 * 1024;
        }
        if ($suffix === 'G') {
            return $strippedSize * 1024 * 1024 * 1024;
        }
        if ($suffix === 'T') {
            return $strippedSize * 1024  * 1024 * 1024 * 1024;
        }

        return (int) $size;
    }
}

final class UploadedFile implements UploadedFileInterface
{
    /**
     * @var StreamInterface
     */
    private $stream;

    /**
     * @var int
     */
    private $size;

    /**
     * @var int
     */
    private $error;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var string
     */
    private $mediaType;

    protected $moved = false;

    /**
     * @param StreamInterface $stream
     * @param int $size
     * @param int $error
     * @param string $filename
     * @param string $mediaType
     */
    public function __construct(StreamInterface $stream, $size, $error, $filename, $mediaType)
    {
        $this->stream = $stream;
        $this->size = $size;

        if (!\is_int($error) || !\in_array($error, array(
            \UPLOAD_ERR_OK,
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE,
            \UPLOAD_ERR_PARTIAL,
            \UPLOAD_ERR_NO_FILE,
            \UPLOAD_ERR_NO_TMP_DIR,
            \UPLOAD_ERR_CANT_WRITE,
            \UPLOAD_ERR_EXTENSION,
        ))) {
            throw new InvalidArgumentException(
                'Invalid error code, must be an UPLOAD_ERR_* constant'
            );
        }
        $this->error = $error;
        $this->filename = $filename;
        $this->mediaType = $mediaType;
    }

    /**
     * {@inheritdoc}
     */
    public function getStream()
    {
        if ($this->error !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot retrieve stream due to upload error');
        }

        if ($this->moved) {
            throw new \RuntimeException(sprintf('Uploaded file %1s has already been moved', $this->name));
        }

        return $this->stream;
    }

    /**
     * {@inheritdoc}
     */
    public function moveTo($targetPath)
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file already moved');
        }

        if (!is_writable(dirname($targetPath))) {
            throw new InvalidArgumentException('Upload target path is not writable');
        }

        file_put_contents($targetPath, $this->stream);

        $this->moved = true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * {@inheritdoc}
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientFilename()
    {
        return $this->filename;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientMediaType()
    {
        return $this->mediaType;
    }
}
