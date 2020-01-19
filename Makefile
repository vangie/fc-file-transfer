clean:
	rm -rf ./.fun

build:
	fun build
	find .fun/build/artifacts/file-transfer/* -maxdepth 0 -exec cp html/* {} \;

start: build
	fun local start