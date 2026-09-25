export UID := $(shell id -u)
export GID := $(shell id -g)

COMPOSE = docker compose
PHP = $(COMPOSE) exec php
NODE = $(COMPOSE) exec node
TOOLS = phpstan php-cs-fixer rector infection deptrac

.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-zA-Z_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

## —— Environment ————————————————————————————————————————————————————————————
up: ## Build and start the containers
	$(COMPOSE) up -d --build

down: ## Stop the containers
	$(COMPOSE) down

install: up composer.install tools.install yarn.install ## Install every dependency

composer.install: ## Install PHP dependencies
	$(PHP) composer install

tools.install: ## Install the isolated quality tools (tools/*)
	@for tool in $(TOOLS); do $(PHP) composer install --working-dir=tools/$$tool; done

yarn.install: ## Install JS dependencies
	$(NODE) sh -c 'mkdir -p /tmp/corepack-bin && corepack enable --install-directory /tmp/corepack-bin'
	$(NODE) sh -c 'PATH=/tmp/corepack-bin:$$PATH yarn install --immutable'

## —— Tests ——————————————————————————————————————————————————————————————————
tests: phpunit vitest typecheck ## Run every test suite

phpunit: ## Run PHPUnit (unit, contract, security, integration)
	$(PHP) vendor/bin/phpunit

vitest: ## Run the JS tests
	$(NODE) sh -c 'PATH=/tmp/corepack-bin:$$PATH yarn test'

typecheck: ## Type-check the TypeScript sources
	$(NODE) sh -c 'PATH=/tmp/corepack-bin:$$PATH yarn typecheck'

js.build: ## Rebuild assets/dist
	$(NODE) sh -c 'PATH=/tmp/corepack-bin:$$PATH yarn build'

## —— Quality ————————————————————————————————————————————————————————————————
quality: cs phpstan rector deptrac ## Run static analysis and style checks

cs: ## Check coding style
	$(PHP) tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --diff --dry-run --allow-risky=yes

cs.fix: ## Fix coding style
	$(PHP) tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --allow-risky=yes

phpstan: ## Run PHPStan (level max)
	$(PHP) tools/phpstan/vendor/bin/phpstan analyse --memory-limit=1G

rector: ## Check Rector (dry-run)
	$(PHP) tools/rector/vendor/bin/rector process --dry-run

rector.fix: ## Apply Rector
	$(PHP) tools/rector/vendor/bin/rector process

deptrac: ## Check architectural layers
	$(PHP) tools/deptrac/vendor/bin/deptrac analyse --no-progress

infection: ## Run mutation testing on Domain + Application
	$(PHP) tools/infection/vendor/bin/infection --threads=4 --show-mutations

.PHONY: help up down install composer.install tools.install yarn.install tests phpunit vitest typecheck js.build quality cs cs.fix phpstan rector rector.fix deptrac infection
