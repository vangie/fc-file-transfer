build:
	fun build
	# cp html/* .fun/build/artifacts/file-transfer/nodejs/
	# cp html/* .fun/build/artifacts/file-transfer/python/
	# cp html/* .fun/build/artifacts/file-transfer/java/
	cp html/* .fun/build/artifacts/file-transfer/php/

start: build
	fun local start