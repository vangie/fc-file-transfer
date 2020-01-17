# -*- coding: utf-8 -*-

from os import listdir, makedirs
from os.path import isfile, join, exists
import json
import cgi
import logging

UPLOADED_DIR = '/tmp/uploaded'

def handler(environ, start_response):
    context = environ['fc.context']
    request_uri = environ['fc.request_uri']
    request_method = environ['REQUEST_METHOD']
    path_info = environ['PATH_INFO']
    params = cgi.parse_qs(environ.get('QUERY_STRING', ''),
                          keep_blank_values=True)

    if path_info == '/' and not request_uri.endswith('/'):
        status = '301'
        response_headers = [('Location', request_uri + '/')]
        start_response(status, response_headers)
        return []
    elif path_info == '/list':
        status = '200 OK'
        response_headers = [('Content-type', 'application/json')]
        start_response(status, response_headers)
        return [json.dumps(list_files()).encode()]
    elif path_info == '/upload' and request_method == 'POST':
        logging.info(request_uri)
        form = cgi.FieldStorage(
            fp=environ['wsgi.input'],
            environ=environ,
            keep_blank_values=True
        )
        upload_file(form)

        status = '200 OK'
        response_headers = [('Content-type', 'application/json')]
        start_response(status, response_headers)
        return [json.dumps({"code": 200, "msg": "upload success."}).encode()]
    elif path_info == '/download' and 'filename' in params:
        filename = params.get('filename')[0]
        logging.info(filename)
        status = '200 OK'
        response_headers = [
            ('context-type', 'application/octet-stream'),
            ('Content-Disposition', f'attachment; filename="{filename}"')
        ]
        start_response(status, response_headers)
        f = open(join(UPLOADED_DIR, filename), 'rb')
        return iter(lambda: f.read(128), ''.encode())

    else:
        status = '200 OK'
        response_headers = [('Content-type', 'text/html')]
        start_response(status, response_headers)
        f = open("index.html", "r")
        return iter(lambda: f.readline().encode(), ''.encode())


def upload_file(form):
    fileItem = form['fileContent']
    if fileItem.file:
        filename = fileItem.filename.replace('\\', '/').split('/')[-1].strip()
        if not filename:
            raise Exception('No valid filename specified')
        file_path = join(UPLOADED_DIR, filename)
        with open(file_path, 'wb') as output_file:
            while 1:
                data = fileItem.file.read(1024)
                if not data:
                    break
                output_file.write(data)


def list_files():
    if not exists(UPLOADED_DIR):
        makedirs(UPLOADED_DIR)
    return [f for f in listdir(UPLOADED_DIR) if isfile(join(UPLOADED_DIR, f))]
