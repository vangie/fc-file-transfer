# 轻松实现函数计算文件上传下载

这是一个包含了[函数计算](https://statistics.functioncompute.com/?title=%E8%BD%BB%E6%9D%BE%E5%AE%9E%E7%8E%B0%E5%87%BD%E6%95%B0%E8%AE%A1%E7%AE%97%E6%96%87%E4%BB%B6%E4%B8%8A%E4%BC%A0%E4%B8%8B%E8%BD%BD&author=%E5%80%9A%E8%B4%A4&src=article&url=http%3A%2F%2Ffc.console.aliyun.com%2F%3Ffctraceid%3DYXV0aG9yJTNEJUU1JTgwJTlBJUU4JUI0JUE0JTI2dGl0bGUlM0QlRTglQkQlQkIlRTYlOUQlQkUlRTUlQUUlOUUlRTclOEUlQjAlRTUlODclQkQlRTYlOTUlQjAlRTglQUUlQTElRTclQUUlOTclRTYlOTYlODclRTQlQkIlQjYlRTQlQjglOEElRTQlQkMlQTAlRTQlQjglOEIlRTglQkQlQkQ%3D)每种 Runtime 结合 HTTP Trigger 实现文件上传和文件下载的示例集。每个示例包括:

* 一个公共 HTML 页面，该页面有一个文件选择框和上传按钮，会列出已经上传的文件，点击某个已上传的文件可以把文件下载下来。
* 支持文件上传、下载和列举的函数。

我们知道不同语言在处理 HTTP 协议上传下载时都有很多中方法和社区库，特别是结合函数计算的场景，开发人员往往需要耗费不少精力去学习和尝试。本示例集编撰的目的就是节省开发者甄别的精力和时间，为每种语言提供一种有效且符合社区最佳实践的方法，可以拿来即用。

![](https://img.alicdn.com/tfs/TB1b4IzubY1gK0jSZTEXXXDQVXa-773-593.png)
![](https://data-analysis.cn-shanghai.log.aliyuncs.com/logstores/article-logs/track_ua.gif?APIVersion=0.6.0&title=%E8%BD%BB%E6%9D%BE%E5%AE%9E%E7%8E%B0%E5%87%BD%E6%95%B0%E8%AE%A1%E7%AE%97%E6%96%87%E4%BB%B6%E4%B8%8A%E4%BC%A0%E4%B8%8B%E8%BD%BD&author=%E5%80%9A%E8%B4%A4&src=article)

当前已支持的 Runtime 包括

* nodejs
* python
* php
* java

计划支持的 Runtime 包括

* dotnetcore

不打算支持的 Runtime 包括

* custom

## 使用限制

由于函数计算对于 HTTP 的 Request 和 Response 的 Body 大小限制均为 6M，所以该示例集只适用于借助函数计算上传和下载文件小于 6M 的场景。对于大于 6M 的情况，可以考虑如下方法:

1. **分片上传**，把文件切分成小块，上传以后再拼接起来。
2. **借助于 OSS**，将文件先上传 OSS，函数从 OSS 上下载文件，处理完以后回传 OSS。
3. **借助于 NAS**，将大文件放在 NAS 网盘上，函数可以像读写普通文件系统一样访问 NAS 网盘的文件。

## 快速开始

### 安装依赖

在开始之前请确保开发环境已经安装了如下工具：

* docker
* [funcraft](https://github.com/alibaba/funcraft/blob/master/docs/usage/installation-zh.md)
* make

### 构建并启动函数

```bash
$ make start
...
HttpTrigger httpTrigger of file-transfer/nodejs was registered
        url: http://localhost:8000/2016-08-15/proxy/file-transfer/nodejs
        methods: [ 'GET', 'POST' ]
        authType: ANONYMOUS
HttpTrigger httpTrigger of file-transfer/python was registered
        url: http://localhost:8000/2016-08-15/proxy/file-transfer/python
        methods: [ 'GET', 'POST' ]
        authType: ANONYMOUS
HttpTrigger httpTrigger of file-transfer/java was registered
        url: http://localhost:8000/2016-08-15/proxy/file-transfer/java
        methods: [ 'GET', 'POST' ]
        authType: ANONYMOUS
HttpTrigger httpTrigger of file-transfer/php was registered
        url: http://localhost:8000/2016-08-15/proxy/file-transfer/php
        methods: [ 'GET', 'POST' ]
        authType: ANONYMOUS


function compute app listening on port 8000!
```

`make start` 命令会调用 Makefile 文件中的指令，通过 `fun local` 在本地的 8000 端口开放 HTTP 服务，控制台会打印出每个 HTTP Trigger 的 URL 、支持的 HTTP 方法，以及认证方式。

### 效果演示

上面四个 URL 地址随便选一个在浏览器中打开示例页面

![](https://img.alicdn.com/tfs/TB1SCQxukT2gK0jSZFkXXcIQFXa-839-479.gif)

## 接口说明

所有示例都实现了下述四个 HTTP 接口：

* `GET /` 返回文件上传 Form 的 HTML 页面
* `GET /list` 以 JSON 数组形式返回文件列表
* `POST /upload` 以 `multipart/form-data` 格式上传文件
  * `fileContent` 作为文件字段
  * `fileName` 作为文件名字段
* `GET /download?filename=xxx` 以 `application/octet-stream` 格式返回文件内容。

此外为了能正确的计算相对路径，在访问根路径时如果不是以`/`结尾，都会触发一个 301 跳转，在 URL 末尾加上一个`/`。

## 不同语言的示例代码

* [nodejs](nodejs/index.js)
* [python](python/index.py)
* [php](php/index.php)
* [java](java/src/main/java/example/App.java)

## 已知问题

1. 文件大小[限制](#使用限制)
2. fun local 实现存在已知问题，上传过大的文件会自动退出，未来的版本会修复。
3. 部署到线上需要绑定自定义运行才能使用，否则 HTML 文件在浏览器中会被[强制下载](https://help.aliyun.com/knowledge_detail/56103.html#HTTP-Trigger-compulsory-header)而不是直接渲染。
