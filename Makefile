.PHONY: help build up down logs shell install cs stan psalm deptrac rector test test-unit test-integration clean fresh env

# ── Default target ──────────────────────────────────────────────────────────
help:
	@echo "SQLCraft — Common tasks via make(1)"
	@echo ""
	@echo "Environment setup:"
	@echo "  make env         copy .env.example → .env"
	@echo "  make build       build PHP image"
	@echo "  make install     composer install"
	@echo ""
	@echo "Container lifecycle:"
	@echo "  make up          start all database engines (MySQL, MariaDB, Postgres, MSSQL)"
	@echo "  make up-oracle   start engines + Oracle XE"
	@echo "  make down        stop all services (data persists)"
	@echo "  make logs        tail docker compose logs"
	@echo "  make shell       interactive PHP shell (bash)"
	@echo ""
	@echo "Code quality:"
	@echo "  make cs          PHP-CS-Fixer check (dry-run)"
	@echo "  make cs-fix      PHP-CS-Fixer auto-fix"
	@echo "  make stan        PHPStan (max level)"
	@echo "  make psalm       Psalm (max level)"
	@echo "  make deptrac     Deptrac (dependency rules)"
	@echo "  make rector      Rector (dry-run)"
	@echo ""
	@echo "Tests:"
	@echo "  make test        run full CI suite (static + unit tests)"
	@echo "  make test-unit   PHPUnit (unit tests only)"
	@echo "  make test-int    PHPUnit integration suite (requires: make up)"
	@echo ""
	@echo "Cleanup:"
	@echo "  make clean       remove caches/coverage reports"
	@echo "  make fresh       clean + rebuild containers (data lost)"

# ── Environment ─────────────────────────────────────────────────────────────
env:
	@test -f .env || (cp .env.example .env && echo ".env created from .env.example")

# ── Build ────────────────────────────────────────────────────────────────────
build: env
	docker compose build php
	@echo "✓ PHP 8.4 image built"

install: build
	docker compose run --rm php composer install
	@echo "✓ Composer dependencies installed"

# ── Containers ──────────────────────────────────────────────────────────────
up:
	docker compose up -d
	docker compose logs -f

up-oracle:
	docker compose --profile oracle up -d
	docker compose logs -f

down:
	docker compose down
	@echo "✓ Services stopped (data volumes preserved)"

logs:
	docker compose logs -f

shell:
	docker compose run --rm php bash

# ── Code Quality ────────────────────────────────────────────────────────────
cs:
	docker compose run --rm php composer run cs

cs-fix:
	docker compose run --rm php composer run cs:fix
	@echo "✓ Code formatted"

stan:
	docker compose run --rm php composer run stan

psalm:
	docker compose run --rm php composer run psalm

deptrac:
	docker compose run --rm php composer run deptrac

rector:
	docker compose run --rm php composer run rector

# ── Tests ────────────────────────────────────────────────────────────────────
test:
	docker compose run --rm php composer run ci
	@echo "✓ Full CI suite passed"

test-unit:
	docker compose run --rm php composer run test
	@echo "✓ Unit tests passed"

test-int:
	@if ! docker compose ps mssql | grep -q "Up"; then \
		echo "ERROR: Database services not running. Run 'make up' first."; \
		exit 1; \
	fi
	docker compose run --rm php composer run test:integration
	@echo "✓ Integration tests passed"

# ── Cleanup ──────────────────────────────────────────────────────────────────
clean:
	docker compose run --rm php bash -c 'rm -rf .phpunit.cache .php-cs-fixer.cache .phpstan coverage infection.log infection-summary.log'
	@echo "✓ Caches and reports cleaned"

fresh: down clean
	docker compose down --volumes
	@echo "✓ Everything cleaned; data volumes destroyed"
	@echo "Run 'make build install' to start fresh"

# ── Development workflow ────────────────────────────────────────────────────
watch:
	@echo "Watching src/ for changes — run 'make test-unit' on save"
	@if command -v watchman &> /dev/null; then \
		watchman watch-project . 2>/dev/null | head -1; \
		watchman trigger . sqlcraft-test 'src/**/*.php' -- make test-unit; \
	elif command -v entr &> /dev/null; then \
		find src -name '*.php' | entr -c make test-unit; \
	else \
		echo "Install watchman or entr for file watching"; \
		exit 1; \
	fi

# ── Debug ────────────────────────────────────────────────────────────────────
status:
	@echo "=== Container Status ===" && docker compose ps
	@echo "" && echo "=== Git Status ===" && git status --short
	@echo "" && echo "=== Last Commits ===" && git log --oneline -5
