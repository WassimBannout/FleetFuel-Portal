# FleetFuel Portal local command interface (docs/02-ARCHITECTURE.md).
# Everything runs inside Docker; the host only needs Docker, Compose and Make.
# No target here deletes database volumes, env files or the APP_KEY.

SHELL := /bin/sh
.DEFAULT_GOAL := help

# Build the PHP image with the host user's IDs so bind-mounted files stay yours.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose
# One-off containers. --no-deps skips MySQL for tools that do not need it.
PHP    := $(COMPOSE) run --rm --no-deps app
PHP_DB := $(COMPOSE) run --rm app
NODE   := $(COMPOSE) run --rm --no-deps node

# A real request through nginx -> PHP -> MySQL. Container health states can lag
# by up to a minute, so setup/up finish with this instead of trusting them.
CHECK_READY := $(COMPOSE) exec -T web wget -q -O /dev/null http://127.0.0.1/health \
	|| { echo "Readiness check failed: http://localhost/health did not return 200 (see make logs)"; exit 1; }

# Stops before a second checkout takes over this project's containers and volume.
CHECK_PROJECT := sh docker/bin/check-compose-project.sh

.PHONY: help setup up down test lint analyse build verify audit prod-image rehearse simulate logs shell

help: ## List the available commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  make %-10s %s\n", $$1, $$2}'

setup: ## Build, install, migrate and start; safe to repeat (keeps .env, key and data)
	sh docker/bin/prepare-env.sh
	@$(CHECK_PROJECT)
	$(COMPOSE) build app
	$(PHP) composer install --no-interaction --prefer-dist
	@grep -Eq '^APP_KEY=.+' .env || $(PHP) php artisan key:generate --no-interaction
	@grep -Eq '^APP_KEY=.+' .env.testing || $(PHP) php artisan key:generate --env=testing --no-interaction
	$(COMPOSE) up -d --wait mysql
	$(PHP_DB) php artisan migrate --no-interaction
	$(PHP_DB) php artisan db:seed --no-interaction
	$(MAKE) build
	$(COMPOSE) up -d --wait app web scheduler
	@$(CHECK_READY)
	@port=$$(sed -n 's/^APP_PORT=//p' .env | tail -n 1); \
		echo "FleetFuel Portal is running at http://localhost:$${port:-8080}"
	@echo "Demo accounts (e.g. admin@fleetfuel.test) use DEMO_PASSWORD from .env; see README."

up: ## Start the existing stack without changing data
	@$(CHECK_PROJECT)
	$(COMPOSE) up -d --wait app web scheduler
	@$(CHECK_READY)

down: ## Stop the stack; database volumes are kept
	$(COMPOSE) --profile mail down --remove-orphans

test: ## Run PHPUnit against the isolated fleetfuel_test MySQL database, then the JavaScript unit tests
	@$(CHECK_PROJECT)
	@# The Integration suite runs the standalone POS simulator, so install its locked dependencies.
	$(PHP) composer install --working-dir=tools/pos-simulator --no-interaction --quiet
	$(PHP_DB) php artisan test
	@# Node's built-in test runner; the tested modules import no packages, so no npm install is needed.
	$(NODE) npm test --silent

lint: ## Check code style with Pint (no changes are written)
	$(PHP) vendor/bin/pint --test

analyse: ## Run PHPStan/Larastan static analysis (the app, then the POS simulator)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(PHP) composer install --working-dir=tools/pos-simulator --no-interaction --quiet
	$(PHP) vendor/bin/phpstan analyse -c tools/pos-simulator/phpstan.neon --memory-limit=1G --no-progress

build: ## Install locked frontend dependencies and build production assets
	$(NODE) sh -c 'npm ci && npm run build'

verify: ## Run lint, analyse, test and build; stops at the first failure
	$(MAKE) lint
	$(MAKE) analyse
	$(MAKE) test
	$(MAKE) build

# Not part of verify: it needs the network, and its answer changes whenever a
# new advisory is published, even when the code does not.
audit: ## Check the locked Composer and npm dependencies against published security advisories
	$(PHP) composer audit --locked
	$(PHP) composer audit --locked --working-dir=tools/pos-simulator
	$(NODE) npm audit --audit-level=low

# Production (M11, docs/RUNBOOK.md). Neither target touches the development stack.
prod-image: ## Build the production image fleetfuel-portal:local (docker/production/Dockerfile)
	docker build -f docker/production/Dockerfile -t fleetfuel-portal:local .

rehearse: ## Rehearse a production deployment locally with disposable data, then remove it
	bash docker/production/rehearse.sh

# The simulator reaches nginx as http://web inside the Compose network. Its
# credentials are passed through from your shell environment only
# (POS_TOKEN, or POS_EMAIL and POS_PASSWORD); see tools/pos-simulator/README.md.
simulate: ## Run the POS simulator against the running stack (SCENARIO=all|success|replay|conflict|blocked|quota)
	$(PHP) composer install --working-dir=tools/pos-simulator --no-interaction --quiet
	$(COMPOSE) run --rm --no-deps -e POS_BASE_URL=$${POS_BASE_URL:-http://web/api/v1} \
		-e POS_TOKEN -e POS_EMAIL -e POS_PASSWORD -e POS_CARD -e POS_BLOCKED_CARD -e POS_TINY_CARD \
		-e POS_PRODUCT -e POS_LITERS -e POS_TIMEOUT \
		app php tools/pos-simulator/bin/pos-simulator $(or $(SCENARIO),all)

logs: ## Show recent service logs (the generated MySQL root password line is hidden)
	@$(COMPOSE) logs --no-color --tail=100 app web scheduler mysql | grep -v 'GENERATED ROOT PASSWORD'

shell: ## Open a shell in the running app container
	$(COMPOSE) exec app bash
