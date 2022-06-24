.PHONY: pre-commit-check

cs:
	vendor/bin/php-cs-fixer fix --verbose

cs-dry-run:
	vendor/bin/php-cs-fixer fix --verbose --dry-run

phpstan:
	vendor/bin/phpstan analyze

psalm:
	vendor/bin/psalm

test:
	vendor/bin/phpunit

pre-commit-check: cs phpstan psalm test

setup-git:
	git config branch.autosetuprebase always

setup: build composer-install

shell: build
	docker-compose run --rm php zsh

composer-install: start
	docker-compose exec php composer install

start:
	docker-compose up -d php

build:
	docker-compose build php
