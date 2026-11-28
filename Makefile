start:
	php -S localhost:8080 -t public public/index.php

build:
	docker build -t hexlet-slim .