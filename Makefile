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

pre-commit-check: cs phpstan test

setup-git:
	git config branch.autosetuprebase always
