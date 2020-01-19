const multiparty = require('multiparty');
const path = require('path');

const fs = require('fs');
const util = require('util');
const [
    readdir,
    exists,
    mkdir,
    rename
] = [
    fs.readdir,
    fs.exists,
    fs.mkdir,
    fs.rename
].map(util.promisify);

const UPLOADED_DIR = '/tmp/uploaded'

module.exports.handler = async function (req, resp, context) {

    req.pipe = function(writeStream) {
        req.on('close', cleanup);
        req.on('data', onData);
        req.on('end', onEnd);
        req.on('error', onEnd);

        function onData(chunk) {
            writeStream.write(chunk);
        }

        function onEnd(err) {
            if(err){
                console.log(err);
            }
            writeStream.end();
        }

        function cleanup() {
            req.removeListener('data', onData)
            req.removeListener('end', onEnd)
            req.removeListener('error', onEnd)
            req.removeListener('close', cleanup)
        }
    }

    if (req.path === '/list' && req.method === 'GET') {
        resp.setHeader('Content-Type', 'application/json');
        resp.send(JSON.stringify(await listFiles()));
    } else if (req.path === "/" && !req.url.endsWith("/")) {
        resp.setStatusCode(301);
        resp.setHeader('Location', req.url + "/");
        resp.send("");
    } else if (req.path === "/download" && req.queries.filename) {
        const filename = req.queries.filename;
        resp.setHeader('Context-Type', 'application/octet-stream');
        resp.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
        resp.send(fs.createReadStream(UPLOADED_DIR + "/" + filename));
    } else if (req.path === '/upload' && req.method === 'POST') {
        const form = new multiparty.Form({
            autoFiles: true,
            uploadDir: UPLOADED_DIR
        });


        form.parse(req, async function(err, fields, files) {

            if(err){
                resp.setStatusCode(500);
                resp.setHeader('Content-Type', 'application/json');
                resp.send(JSON.stringify({code:500, error: err}));
            }

            Object.keys(files).forEach(async function(name) {
                file = files[name][0];
                await rename(file.path, path.join(UPLOADED_DIR, file.originalFilename));
            });

            resp.setStatusCode(200);
            resp.setHeader('Content-Type', 'application/json');
            resp.send(JSON.stringify({code:200, msg: "upload success."}));
        });


    } else {
        resp.setStatusCode(200);
        resp.setHeader('Content-Type', 'text/html');
        resp.send(fs.createReadStream('index.html'));
    }
}

async function listFiles() {
    if (!await exists(UPLOADED_DIR)) {
        await mkdir(UPLOADED_DIR)
    }

    return await readdir(UPLOADED_DIR);
}
