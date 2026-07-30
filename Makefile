# Na Live Support — the owner's entire interface (Session 0 Part F).
# Every target documented in README.md. One word each.

# The dev override (ADR 0002) bind-mounts the working tree into the app roles
# so local runs test the code you just edited, not the code baked at build
# time. CI deliberately does NOT use it — .github/workflows/ci.yml names
# compose.yaml alone, so the pipeline always exercises the immutable image.
COMPOSE := docker compose -f docker/compose.yaml -f docker/compose.dev.yaml

.PHONY: up down fresh reload test gates lint browser shell logs status

## bring the whole stack up (builds images on first run)
# --renew-anon-volumes: the dev override pins vendor/ and friends to
# container-owned anonymous volumes, and Docker will otherwise reuse one built
# from an older image — the same staleness the bind mount exists to kill.
up:
	$(COMPOSE) up -d --build --renew-anon-volumes
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
	$(COMPOSE) up -d --build --renew-anon-volumes
	@grep -q '^APP_KEY=base64' .env || { \
		KEY=$$($(COMPOSE) exec -T app php artisan key:generate --show) && \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && \
		$(COMPOSE) up -d app worker scheduler reverb; \
	}
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force --database=pgsql_migrator
	@echo "Fresh stack ready at http://localhost"

## re-read source in the long-lived processes, then wait for them to be healthy
# The bind mount delivers files instantly, but Octane and Horizon hold compiled
# code in memory for the life of the process — a bind mount alone just moves
# the staleness from the image to the worker. `test` depends on this so a Pest
# run can never assert against code the server isn't running. The --wait is not
# optional: the Integration suite asserts Horizon's eleven supervisors, and
# without it that race is a flaky test.
reload:
	$(COMPOSE) exec -T app php artisan octane:reload
	$(COMPOSE) restart worker scheduler reverb
	$(COMPOSE) up -d --wait app worker scheduler reverb
	@echo "Octane reloaded; worker/scheduler/reverb restarted and healthy."

## full Pest suite, run INSIDE the app container (parity; 'make up' first)
test: reload
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
