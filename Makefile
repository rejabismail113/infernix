# Infernix Workspace — developer commands. `make setup` after `docker compose up -d` gives a running app
# at http://localhost:$APP_PORT (default 8010) with the seeded Owner login (see .env SEED_OWNER_*).
SHELL := /bin/bash
DC    := docker compose
APP   := $(DC) exec -T app
# One-off container: works before vendor/ exists (the long-running app container needs vendor/ to start).
RUN   := $(DC) run --rm --no-deps -T app

.PHONY: help up down setup fresh seed migrate test test-php test-js lint lint-php lint-js stan pint fix ci logs shell backup restore-test bench demo uat-screens openapi

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers (app, horizon, scheduler, vite, postgres, redis, objectstore, mailpit)
	$(DC) up -d --build

down: ## Stop containers
	$(DC) down

setup: ## First-time setup: env, deps, key, migrate, seed, build assets
	@test -f .env || cp .env.example .env
	$(DC) build app
	$(RUN) composer install --no-interaction --prefer-dist
	@grep -qE '^APP_KEY=.+' .env || $(RUN) php artisan key:generate --force --no-interaction
	$(DC) up -d
	$(DC) up -d --wait --wait-timeout 180 app
	$(APP) php artisan storage:ensure-bucket --no-interaction
	$(APP) php artisan migrate --force --no-interaction
	$(APP) php artisan db:seed --force --no-interaction
	$(APP) php artisan storage:link || true
	@echo "✔ App: http://localhost:$${APP_PORT:-8010}  (login: see SEED_OWNER_EMAIL / SEED_OWNER_PASSWORD in .env)"
	@echo "✔ Mailpit: http://localhost:8025   Object store console: http://localhost:9001   Horizon: http://localhost:$${APP_PORT:-8010}/horizon"

fresh: ## Drop & recreate DB with seed
	$(APP) php artisan migrate:fresh --seed --force --no-interaction

migrate: ## Run migrations
	$(APP) php artisan storage:ensure-bucket --no-interaction
	$(APP) php artisan migrate --force --no-interaction

seed: ## Run seeders
	$(APP) php artisan db:seed --force --no-interaction

test: test-php test-js ## Run all tests

test-php: ## Pest (against PostgreSQL)
	$(APP) php artisan test --parallel --compact

test-js: ## Vitest
	$(DC) exec -T vite npm run test

lint: lint-php lint-js ## All linters

lint-php: pint stan ## Pint (check) + Larastan

pint: ## Pint check
	$(APP) vendor/bin/pint --test

fix: ## Pint fix + eslint --fix
	$(APP) vendor/bin/pint
	$(DC) exec -T vite npm run lint:fix

stan: ## Larastan level 6
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

lint-js: ## ESLint + vue-tsc
	$(DC) exec -T vite npm run lint
	$(DC) exec -T vite npm run typecheck

ci: lint test ## Everything CI runs

logs: ## Tail app logs
	$(DC) logs -f app horizon scheduler

shell: ## Shell into app container
	$(DC) exec app bash

backup: ## Run an encrypted database backup now (NFR-06)
	docker compose exec -T app php artisan backup:run backup

restore-test: ## Restore the newest backup into a scratch DB and verify it (NFR-06, M12-06)
	scripts/restore-test.sh

bench: ## Seed a 50k-row dataset and measure p95 of common list/detail pages (NFR-05)
	docker compose exec -T app php artisan perf:benchmark --rows=50000

demo: ## Seed the UAT walk-through scenarios (dev / UAT only; users demo.*@infernix.local)
	docker compose exec -T app php artisan db:seed --class=UatDemoSeeder

uat-screens: ## Capture docs/uat/*.png evidence screenshots (needs `make demo` and the app running)
	node scripts/uat-screenshots.cjs http://localhost:8010

openapi: ## Regenerate docs/openapi.json from the routes + permission matrix
	docker compose exec -T app php artisan api:openapi
