.PHONY: check
check:
	vendor/bin/php-cs-fixer check --diff
	bin/console cache:warmup --env=dev
	vendor/bin/phpstan analyse

.PHONY: fix
fix:
	vendor/bin/php-cs-fixer fix --diff

.PHONY: test
test: jwt-test-keys
	vendor/bin/phpunit

.PHONY: jwt-test-keys
jwt-test-keys:
	bin/console lexik:jwt:generate-keypair --skip-if-exists --env=test
