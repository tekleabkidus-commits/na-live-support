# BUILD STATUS

Updated by Claude Code at the end of every session. The owner reads this file to know where the
project actually stands. A row is only `DONE` when its acceptance criteria have executed tests.

| Doc | Module | Status | Session | ACs proven | Notes |
|---|---|---|---|---|---|
| — | Repo bootstrap + CI gates | IN PROGRESS (CI red) | S0 | smoke 6/6, gates 8/8, browser 2/2 | Laravel 13.23; 14-service stack healthy; 8 gates live (3 bootstrap-warn until first registry entry); 4 gates falsified+restored; performance stage is a placeholder until Doc 19 L1. CI HAS run (3 runs) — latest on main is RED at Stage 2; Stages 3–7 have never executed |
| 01 | Platform Foundation & Multitenancy | NOT STARTED | S1 | 0/8 | |
| 02 | Authentication, Sessions & Security Core | NOT STARTED | S2 | — | |
| 03 | RBAC, Roles, Permissions, Overrides | NOT STARTED | S3 | — | |
| 04 | Conversations & Messaging Core (fake adapter) | NOT STARTED | S4 | — | |
| 06 | Ticketing System | NOT STARTED | S5 | — | |
| 05 | Telegram Channel (Business + Direct) | NOT STARTED | S6 | — | needs staging bot + Business account on a dedicated phone |
| 07 | Unified Inbox & Agent Workspace | NOT STARTED | S7 | — | needs approved Claude Design screens |
| 08 | Customer Profiles & Identity | NOT STARTED | — | — | |
| 09 | AI System (Chatbot, Copilot, Supervisor) | NOT STARTED | — | — | needs AI provider staging keys |
| 10 | Knowledge Base | NOT STARTED | — | — | |
| 11 | Departments, Teams, Queues & Routing | NOT STARTED | — | — | |
| 12 | Automation, SLA, Forms & Custom Fields | NOT STARTED | — | — | |
| 13 | Web Chat Widget | NOT STARTED | — | — | Wave 2; needs support subdomain |
| 14 | SMS Notification Module | NOT STARTED | — | — | needs Ethiopian gateway sandbox |
| 15 | Reporting, Analytics & Dashboards | NOT STARTED | — | — | |
| 16 | White-Label, Settings & Feature Flags | NOT STARTED | — | — | |
| 17 | Audit Logging & Compliance | NOT STARTED | — | — | |
| 18 | Infrastructure & DevOps | CONTINUOUS | all | — | gates added per session |
| 19 | QA Strategy & Test Plans | CONTINUOUS | all | — | traceability grows per session |

## Session-0 close-out notes

- `tests/Feature/ExampleTest.php` (Laravel scaffolding, `GET /`) was replaced by
  `tests/Feature/HealthEndpointTest.php` (`GET /up`): Stage 2 installs no Node and builds no
  assets, so the Inertia root view's `@vite` had no manifest and 500'd on every clean runner
  (CI run 30307818550) while passing locally. The full Inertia render stays proven by Stages 3–4.
- `tests/Integration/StackSmokeTest.php` lost its silent `localhost` Redis fallback: a native
  redis-server on the host shadows the published 6379, so the fallback could assert against the
  wrong server with the stack up and healthy. The suite is now container-only (`make test`) and
  fails with an explicit message instead.

## Deferred with reason

_(Claude Code appends here. Every deferral needs a spec id and a reason.)_

## Open blockers

_(Claude Code appends here. Only the owner can clear these.)_
