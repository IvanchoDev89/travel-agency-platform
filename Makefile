# Travel Agency Platform — developer workflow.
#
#   make install     composer install (PHPStan + PHPCS)
#   make analyse     PHPStan level 1 (must stay green)
#   make lint        PHPStan + PHPCS (informational)
#   make lint-fix    PHPCS autofix for the fixable subset
#   make test        E2E suites against the real WordPress install
#   make i18n        regenerate POT + refresh/compile es_ES & en_US
#   make i18n-check  fail if a translated string used in PHP is missing
#   make check       analyse + lint + test + i18n-check

SHELL := /bin/bash
COMPOSER ?= composer

.PHONY: install analyse lint lint-fix audit-security test i18n i18n-pot i18n-check ajax-nonce-check check help

help:
	@grep -E '^[a-zA-Z0-9-]+:.*?##' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  %-12s %s\n", $$1, $$2}'

install: ## install dev dependencies
	$(COMPOSER) install

analyse: ## PHPStan level 1
	vendor/bin/phpstan analyse --no-progress

lint: ## PHPStan + PHPCS report
	tools/lint.sh

lint-fix: ## PHPCS autofix (what it can fix automatically)
	vendor/bin/phpcbf || true

audit-security: ## security/DB-only PHPCS report (no formatting rules)
	vendor/bin/phpcs --standard=tools/phpcs-security.xml \
		app/public/wp-content/plugins/travel-agency-platform --report=source || true

test: ## run the E2E suite (mutates the local DB)
	TAP_TEST_ALLOW_DB_WRITES=1 ./tests/run.sh

i18n: ## regenerate POT and refresh/compile catalogs
	tools/i18n.sh

i18n-pot: ## regenerate the POT only
	tools/i18n.sh --pot-only

i18n-check: ## every translated string used in PHP must exist in the catalogs
	php tools/i18n-check.php

ajax-nonce-check: ## every wp_ajax_ handler must verify a nonce
	php tools/ajax-nonce-check.php

check: analyse lint test i18n-check ajax-nonce-check ## full local gate
