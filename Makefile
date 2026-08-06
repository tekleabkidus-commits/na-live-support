# Na Live Support — the owner's entire interface (Session 0 Part F).
# Every target documented in README.md. One word each.

# The dev override (ADR 0002) bind-mounts the working tree into the app roles
# so local runs test the code you just edited, not the code baked at build
# time. CI deliberately does NOT use it — .github/workflows/ci.yml names
# compose.yaml alone, so the pipeline always exercises the immutable image.
COMPOSE := docker compose -f docker/compose.yaml -f docker/compose.dev.yaml

# Evidence set for captured runs (RISK 0b). Override per session:
#   make soak SET=S21-step0
SET ?= manual

.PHONY: up down fresh reload test soak capture perf gates lint browser shell logs status

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
	$(COMPOSE) exec -T minio sh -c "mc alias set local http://localhost:9000 minio_local minio_local_secret >/dev/null && mc mb --ignore-existing local/na-live-support"
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
## The `soak` group is EXCLUDED here and run by `make soak` — see that target.
test: reload
	$(COMPOSE) exec app ./vendor/bin/pest --exclude-group=soak

## Doc 04 AC-03's 10k-message ordering soak, ALONE in its own process (~6-8 min)
##
## WHY IT IS NOT IN `make test`. The soak is the only test that commits its
## fixtures (10k writes across partitioned tables exceed max_locks_per_transaction
## in one transaction), and run inside the shared suite it corrupts the tests that
## follow it: with the soak, the soak itself and every Performance test fail with
## spurious "relation ... does not exist"; without it, 648 pass. That interaction
## is NOT root-caused and is recorded as an open defect in BUILD-STATUS rather than
## hidden. The soak is ALSO flaky in isolation (two green runs, one red with 50
## violations on a clean DB), so AC-03's soak half is NOT claimed as proven —
## see BUILD-STATUS RISK 0. Excluding it keeps the default suite honest.
## Captured by default (RISK 0b): the full run lands under docs/evidence/
## BEFORE you get a chance to pipe it through grep. See ci/capture.sh.
soak:
	CAPTURE_SET=$(SET) bash ci/capture.sh soak $(COMPOSE) exec -T app ./vendor/bin/pest --group=soak

## capture-first wrapper for ANY diagnostic run (RISK 0b, governance #6).
## The whole run is written to docs/evidence/$(SET)/ and ALSO passed through, so
## filtering downstream can no longer destroy the only copy of a failure.
##   make capture LABEL=drain-probe CMD='make soak'
##   make capture LABEL=full-suite SET=S21-step0 CMD='make test'
capture:
	@test -n "$(LABEL)" || { echo "usage: make capture LABEL=<kebab-name> CMD='<command>' [SET=<dir>]"; exit 64; }
	@test -n "$(CMD)" || { echo "usage: make capture LABEL=<kebab-name> CMD='<command>' [SET=<dir>]"; exit 64; }
	CAPTURE_SET=$(SET) bash ci/capture.sh $(LABEL) $(CMD)

## AC-07 latency budgets, STRICT spec assertions, dev-hardware-indicative (Doc 01 §12)
perf:
	$(COMPOSE) exec -e PERF_BUDGETS_ENFORCE=1 app ./vendor/bin/pest --testsuite=Performance

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
