# Na Live Support — the owner's entire interface (Session 0 Part F).
# Every target documented in README.md. One word each.

COMPOSE := docker compose -f docker/compose.yaml

.PHONY: up down fresh test gates lint browser shell logs status

## bring the whole stack up (builds images on first run)
up:
	$(COMPOSE) up -d --build
	@echo ""
	@echo "Stack is starting. 'make status' shows health; app at http://localhost"

## stop the stack (data volumes survive; 'make fresh' rebuilds from zero)
down:
	$(COMPOSE) down

## rebuild + migrate + seed from scratch (DESTROYS local data volumes)
# APP_KEY lives in the host .env (mounted read-only into containers), so it
# is generated with --show and written host-side, never inside a container.
fresh:
	@test -f .env || cp .env.example .env
	$(COMPOSE) down -v
	$(COMPOSE) up -d --build
	@grep -q '^APP_KEY=base64' .env || { \
		KEY=$$($(COMPOSE) exec -T app php artisan key:generate --show) && \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && \
		$(COMPOSE) up -d app worker scheduler reverb; \
	}
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force
	@echo "Fresh stack ready at http://localhost"

## full Pest suite, run INSIDE the app container (parity; 'make up' first)
test:
	$(COMPOSE) exec app ./vendor/bin/pest

## every CI gate, in registry order, with a PASS/FAIL summary
gates:
	bash ci/run-gates.sh

## Pint (format check) + PHPStan (max) + ESLint + vue-tsc
lint:
	./vendor/bin/pint --test
	./vendor/bin/phpstan analyse --memory-limit=1G
	npx eslint resources/js tests/Browser
	npx vue-tsc --noEmit

## Playwright browser suite (stack must be up)
browser:
	npx playwright test

## shell into the app container
shell:
	$(COMPOSE) exec app bash

## tail all services
logs:
	$(COMPOSE) logs -f --tail=100

## what is running, what is healthy
status:
	$(COMPOSE) ps
