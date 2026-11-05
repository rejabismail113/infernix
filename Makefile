# Infernix One — developer commands. `make setup` after `docker compose up -d` gives a running app
# at http://localhost:8000 with the seeded Owner login (see .env SEED_OWNER_*).
SHELL := /bin/bash
DC    := docker compose
APP   := $(DC) exec -T app

.PHONY: help up down setup fresh seed migrate test test-php test-js lint lint-php lint-js stan pint fix ci logs shell

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers (app, horizon, scheduler, vite, postgres, redis, minio, mailpit)
	$(DC) up -d --build

down: ## Stop containers
	$(DC) down

setup: ## First-time setup: env, deps, key, migrate, seed, build assets
	@test -f .env || cp .env.example .env
	$(DC) up -d --build
	$(APP) composer install --no-interaction --prefer-dist
	$(APP) php artisan key:generate --force --no-interaction
	$(APP) php artisan migrate --force --no-interaction
	$(APP) php artisan db:seed --force --no-interaction
	$(APP) php artisan storage:link || true
	@echo "✔ App: http://localhost:8000  (login: see SEED_OWNER_EMAIL / SEED_OWNER_PASSWORD in .env)"
	@echo "✔ Mailpit: http://localhost:8025   MinIO console: http://localhost:9001   Horizon: http://localhost:8000/horizon"

fresh: ## Drop & recreate DB with seed
	$(APP) php artisan migrate:fresh --seed --force --no-interaction

migrate: ## Run migrations
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
