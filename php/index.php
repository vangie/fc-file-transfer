<?php

ini_set("enable_post_data_reading", "1");

use RingCentral\Psr7\Response;
use RingCentral\Psr7\Stream;


function handler($request, $context): Response
{
    $UPLOADED_DIR = '/tmp/uploaded';

    $path       = $request->getAttribute('path');
    $requestURI = $request->getAttribute('requestURI');
    $method     = $request->getMethod();
    $queries    = $request->getQueryParams();
    $type = \strtolower($request->getHeaderLine('Content-Type'));
    list($type) = \explode(';', $type);

    if ($path === "" && !endsWith($requestURI, "/")) {
        return new Response(
            301,
            array(
                'Location' => $requestURI . "/"
            ),
            null
        );
    } else if ($path === "/list" && $method === "GET") {
        if (!file_exists($UPLOADED_DIR)) {
            mkdir($UPLOADED_DIR, 0755, true);
        }
        $files = array_values(array_diff(scandir($UPLOADED_DIR), array('.', '..')));
        return new Response(
            200,
            array(
                'Content-Type' => 'application/json'
            ),
            json_encode($files)
        );
    } else if ($path === '/upload' && $method === "POST" && $type === 'multipart/form-data') {

        $file = $request->getUploadedFiles()["fileContent"];
        $filename = $file->getClientFilename();
        $file->moveTo("$UPLOADED_DIR/$filename");

        return new Response(
            200,
            array(
                'Content-Type' => 'application/json'
            ),
            json_encode(array(
                "code" => 200,
                "msg" => "upload success."
            ))
        );
    } else if ($path === '/download' && !$queries['filename']) {
        $filename = $queries['filename'];
        return new Response(
            200,
            array(
                'Content-Type' => 'application/octet-stream',
                "Content-Disposition" => "attachment; filename=\"$filename\""
            ),
            new Stream(fopen($UPLOADED_DIR . "/" . $filename, 'r'))
        );
    } else {
        return new Response(
            200,
            array(
                'Content-Type' => 'text/html'
            ),
            new Stream(fopen(__DIR__ . '/index.html', 'r'))
        );
    }
}

function endsWith($haystack, $needle, $case = true)
{
    $expectedPosition = strlen($haystack) - strlen($needle);

    if ($case)
        return strrpos($haystack, $needle, 0) === $expectedPosition;

    return strripos($haystack, $needle, 0) === $expectedPosition;
}
