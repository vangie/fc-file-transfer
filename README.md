# 函数计算上传下载示例集



## 使用限制

由于函数计算对于 HTTP 的 Request 和 Response 的 Body 大小限制均为 6M，所以该示例集只适用于借助函数计算上传和下载文件小于 6M 的场景。对于大于 6M 的情况，可以考虑如下方法

1. 分片上传。把文件切分成小块，上传以后再拼接起来。
2. 借助于 OSS。将文件先上传 OSS，函数从 OSS 上下载文件，处理完以后回传 OSS。
3. 借助于 NAS。将大文件放在 NAS 网盘上，函数可以像读写普通文件系统一样访问 NAS 网盘的文件。
