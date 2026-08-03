Table of Contents

# Document 01 — Platform Foundation & Multitenancy Architecture

**Product:** AI-First Support Platform for Betting Companies **Document series:** 01 of 19 · Standalone enterprise specification **Audience:** Architects, backend engineers, DevOps, security, QA, product **Stack context:** PHP 8.3+ / Laravel 11+, PostgreSQL 16, Redis 7, Laravel Octane, Horizon, Reverb

## 1. Executive Summary

This document specifies the foundational layer on which every other module of the platform is built: the tenant model, tenant resolution and isolation, the shared kernel (IDs, timestamps, soft-deletion policy, event bus, settings engine), environment topology, and the cross-cutting conventions (naming, error format, time handling, i18n plumbing) that all 18 subsequent module documents assume. It defines how one deployment serves many white-label betting operators with hard data isolation, per-tenant configuration, per-tenant rate and resource limits, and zero cross-tenant visibility. Every requirement here is mandatory for all other modules; no module may ship if it violates a Foundation rule.

## 2. Purpose

- Establish a single, unambiguous multitenancy model so that isolation is a property of the platform, not of each developer’s discipline.

- Define the shared kernel primitives (identifiers, events, settings, translations, feature flags integration points) exactly once.

- Define global conventions (API error format, pagination, time, localization) so all modules behave identically.

- Provide the acceptance criteria QA uses to verify isolation and foundation behavior before any feature module is accepted.

## 3. Scope

**In scope:** tenant lifecycle; tenant resolution; data isolation (application + database layers); shared kernel entities; the domain event bus; the settings engine; ID, time, and error conventions; environment matrix; foundational observability hooks. **Out of scope (covered elsewhere):** authentication mechanics (Doc 02), permission checks (Doc 03), channel logic (Docs 05/13/14), branding surfaces of white-label (Doc 16 — this document covers only the tenant data model white-label depends on), deployment pipelines (Doc 18).

## 4. Functional Requirements

FR-01 The system SHALL support unlimited tenants in a single deployment; each tenant represents one betting operator brand instance. FR-02 Every persistent business record SHALL carry a non-null tenant_id; the only exceptions are the tenants table itself and platform-global reference data (currency codes, country codes, language codes, timezone list). FR-03 Tenant resolution SHALL occur exactly once per request/job/socket connection, from server-controlled context only: a. Staff web/API request → from the authenticated session or Sanctum token (token belongs to a user; user belongs to a tenant). b. Public API key → key record’s tenant. c. Telegram webhook → the route’s bot key → bot record’s tenant. d. Widget request → widget key → widget record’s tenant. e. SMS DLR webhook → gateway key → gateway’s tenant. f. Queued job → tenant id serialized into the job payload at dispatch time; a job with no tenant context SHALL fail before executing its handler. FR-04 Tenant id SHALL NEVER be accepted from client-supplied input (body, query, header) on any endpoint. FR-05 Tenant lifecycle states: provisioning → active → suspended → archived. Suspended: staff logins blocked, inbound channel traffic accepted and queued (nothing lost) but no outbound sends, banner shown on any allowed surface. Archived: read-only export access for platform admins only; all keys revoked. FR-06 Tenant provisioning SHALL be an idempotent job that creates: tenant row, default settings set, default roles (from Doc 03 seed), default SLA policy, default business hours, empty KB with starter templates, audit log entry tenant.provisioned. FR-07 The settings engine SHALL store per-tenant settings as typed keys (string, int, bool, json, encrypted-string) with: registered defaults in code, per-tenant overrides in DB, validation rule per key, cache with invalidation on write, audit entry on every change (old → new), and a settings.read API for modules. FR-08 A domain event bus SHALL exist: modules publish events (ticket.created, message.delivered, connection.revoked, …) as immutable payloads; subscribers (Automation, SLA, Reporting, Audit, Notifications) consume asynchronously via queued listeners; events are additionally appended to the events table for reporting replay (see NFR-07 retention). FR-09 All user-visible strings produced by the platform SHALL pass through the i18n layer (code-registered keys + DB-backed operator-editable translations); a CI check SHALL fail the build on user-visible string literals outside the i18n layer. FR-10 Global reference data (languages, countries, timezones, currencies) SHALL be seeded and versioned; tenants select from it, never define it. FR-11 Every module SHALL register its feature-flag keys with the foundation flag registry (Pennant, tenant-scoped) so Doc 16’s Backoffice can enumerate and toggle them without code knowledge.

## 5. Non-functional Requirements

NFR-01 **Isolation guarantee:** a defect that omits an application-level tenant filter SHALL still be contained by PostgreSQL row-level security (RLS); cross-tenant read or write is a Sev-1 incident class. NFR-02 Tenant resolution overhead ≤ 1 ms p99 (cached key lookups in Redis). NFR-03 Settings read ≤ 0.5 ms p99 from cache; cache invalidation propagated ≤ 2 s across nodes. NFR-04 Event publication SHALL add ≤ 5 ms p99 to the publishing transaction (write to outbox inside the same DB transaction; relay to queue asynchronously — see §10 Transactional Outbox). NFR-05 The platform SHALL survive the loss of any single queue worker, Reverb node, or app node without message loss (at-least-once processing everywhere; idempotent consumers required by convention C-06). NFR-06 Per-tenant quotas enforceable at foundation level: messages/min, AI tokens/day, storage GB, API req/min; exceeding quota returns 429 quota_exceeded with the quota name, and raises an internal alert at 80%. NFR-07 events table retention: raw events 13 months (rolling monthly partitions), aggregated forever; retention jobs owned by foundation. NFR-08 All timestamps stored UTC (timestamptz); tenant display timezone applied at presentation layer only.

## 6. User Stories

US-01 As a **platform owner**, I can provision a new operator tenant from the platform console and have a working, branded-default instance in under 2 minutes, so onboarding does not need engineers. US-02 As a **platform owner**, I can suspend a tenant (non-payment) knowing inbound player messages are still being captured, so reactivation loses nothing. US-03 As an **operator admin**, I can change any registered setting from the Backoffice and see it take effect within seconds, without deployments. US-04 As a **developer**, I get tenant scoping automatically from the framework layer, so I cannot accidentally query across tenants. US-05 As a **QA engineer**, I have a documented isolation test suite (see §18) I can run against any environment to certify tenancy. US-06 As a **security engineer**, I can demonstrate to an auditor that isolation is enforced at two independent layers.

## 7. Business Rules

BR-01 One tenant = one brand instance. An operator running three brands purchases/receives three tenants; cross-brand reporting is a platform-console concern, never in-tenant. BR-02 Nothing is ever hard-deleted at tenant scope except by the archival/retention jobs; user-initiated “deletes” are soft-deletes or state changes (module documents define which). BR-03 Reference data changes (new language, new country) are platform releases, not tenant actions. BR-04 A tenant’s data never leaves its tenant boundary except through the explicit export mechanisms defined in Docs 15 and 17. BR-05 Suspended tenants accrue inbound data ≤ 30 days; beyond that, inbound is still ACKed to channels but stored in cold summary form (count + last message pointer) — prevents unbounded liability from a dead account. Configurable at platform level.

## 8. UX Requirements

The foundation has three thin UI surfaces (full UI specs live in the owning docs, requirements here are contractual):

- **Platform console — tenant list ****&**** detail** (platform owner only): searchable tenant table (name, state, plan flags, created, usage sparkline), create-tenant wizard (name, default language, timezone, region), state controls with typed-name confirmation for suspend/archive, quota panel with edit.

- **Tenant state banners:** suspended tenants show a full-width, non-dismissable banner on any permitted surface; provisioning shows a progress screen with per-step status (created / roles seeded / defaults applied / ready).

- **Settings surfaces (rendered by Doc 16 Backoffice):** foundation defines that every registered setting exposes: label (i18n), description, type-appropriate control, validation error inline, “changed by X at Y” provenance line, and reset-to-default.

## 9. UI Requirements

- Tenant console uses the platform design system (Doc series design tokens): data table component with virtualized rows, state pills (provisioning=blue, active=green, suspended=amber, archived=gray), destructive confirmations use the typed-name pattern.

- Loading: skeleton rows for the tenant table; per-step spinners in provisioning.

- Empty state: “No tenants yet” + primary Create action.

- Error states: provisioning step failure shows the failed step, error reference id, and Retry (idempotent).

- Responsive: console is desktop-first but functional at 768 px (table collapses to cards).

## 10. Technical Requirements

**T-01 Tenancy implementation (two layers):** 1. *Application layer:* a BelongsToTenant model trait applying a global Eloquent scope reading tenant id from a request/job-bound TenantContext service; model creation auto-fills tenant_id; attempting to save with a mismatched tenant id throws. 2. *Database layer:* RLS enabled on every tenant table: USING (tenant_id = current_setting('app.tenant_id')::uuid); the app sets app.tenant_id per connection checkout (and clears on release — Octane persistent connections make this mandatory); a dedicated platform_admin DB role bypasses RLS for the platform console and retention jobs only.

**T-02 Identifiers:** UUIDv7 primary keys everywhere (time-ordered, index-friendly); human-facing sequences (ticket numbers) implemented as per-tenant counters in a tenant_sequences table incremented with UPDATE … RETURNING inside the owning transaction.

**T-03 Transactional Outbox:** publishing a domain event = inserting into outbox within the business transaction; a relay worker (Horizon, outbox queue) reads batches, pushes to the event queues, marks relayed; consumers are idempotent on event_id. This guarantees no event is emitted for a rolled-back write and none is lost after commit.

**T-04 Settings engine:** settings_registry in code (key, type, default, validation, flag tenant_editable), tenant_settings table (tenant_id, key ✱, value jsonb, updated_by); read path: Redis hash per tenant (settings:{tenant}), write path invalidates + republishes; encrypted-string type stored via Laravel encrypted casts with key rotation support.

**T-05 TenantContext propagation:** middleware (HTTP), job middleware (queues — tenant id embedded at dispatch by a Bus pipe), Reverb channel auth callback (sockets), and Artisan command option (CLI); any code path reading models without a bound context throws MissingTenantContext in non-platform code.

**T-06 Events table:** monthly partitions; columns: id (uuidv7), tenant_id ✱, name ✱, payload jsonb, occurred_at ✱, correlation_id, causation_id; correlation id flows from the originating request through all resulting jobs (log + event stamping) for end-to-end tracing.

**T-07 Error format (all APIs):** RFC 9457 problem-details JSON: {type, title, status, code, detail, errors{field:[..]}, trace_id}; code values are stable strings (reply_window_expired, quota_exceeded, …) registered in a shared enum; trace_id matches logs.

**T-08 Pagination convention:** cursor-based (cursor, limit ≤ 100, response next_cursor); offset pagination prohibited on unbounded collections.

**T-09 Caching conventions:** every cache key prefixed t:{tenant}:; TTL mandatory; cache never the source of truth; documented invalidation event per cached aggregate.

**T-10 Reference data:** seeded tables ref_languages, ref_countries, ref_timezones, ref_currencies with platform-versioned migrations.

**Component relationship (text diagram):**

HTTP/Webhook/Socket/CLI entry
  → TenantResolver (key/session → tenant_id)
    → TenantContext (request-bound)
      → [RLS session var set on DB checkout]
      → Domain modules (models auto-scoped)
        → writes + outbox rows (same transaction)
          → Outbox relay → event queues
            → Subscribers: Automation | SLA | Reporting | Audit | Notify

## 11. Security Requirements

SEC-01 RLS is mandatory in all environments including local dev (parity prevents “works locally” scope bugs). SEC-02 The platform_admin DB role is used only by the platform console service account and retention jobs; its use is logged with actor identity; application tenant traffic can never reach it. SEC-03 Tenant keys (bot keys, widget keys, API keys, gateway keys) are ≥ 128-bit random, stored hashed (SHA-256) with a lookup prefix; plaintext shown once at creation. SEC-04 All secrets (bot tokens, gateway credentials, encryption keys) live in the secrets vault; DB stores vault references; secrets never serialize into jobs, logs, or events. SEC-05 Quota and rate limiting occur before expensive work (auth-cheap paths rate-limited by IP+key). SEC-06 Cross-tenant attempts (mismatched tenant on any lookup) return 404 (not 403) to prevent tenant-existence probing, and increment a security metric. SEC-07 The outbox/event payloads exclude PII beyond ids wherever a subscriber can re-fetch by id (data minimization in transit).

## 12. Performance Requirements

- Tenant resolution + context bind: ≤ 1 ms p99 (Redis-cached key→tenant map).

- Settings read: ≤ 0.5 ms p99; registry cold-load ≤ 50 ms.

- Outbox relay lag: ≤ 2 s p95 under nominal load, ≤ 30 s during 10× burst; alert at 60 s.

- Events table insert overhead ≤ 1 ms p99 (partition-local index).

- Foundation adds ≤ 3 ms p99 total to a simple authenticated GET.

- Capacity target (per deployment): 200 active tenants, 100k conversations/month/tenant peak, 500 concurrent staff, sustained 100 events/s with 10× burst headroom.

## 13. Accessibility Requirements

Platform console and all foundation-rendered banners meet WCAG 2.2 AA: banner contrast ≥ 4.5:1, focus order preserved when banners inject, banners announced via role="status" (suspension) — no information conveyed by color alone (state pills carry text), all confirmations keyboard-completable, typed-name confirmation fields properly labeled.

## 14. Localization Requirements

- All foundation strings (banners, provisioning steps, error titles) are i18n keys with English + Amharic at launch; fallback chain: user language → tenant default → English.

- Dates/numbers formatted per user locale at render; API payloads remain locale-neutral (ISO 8601, decimal point).

- The translation manager (Doc 16) can override any foundation string per tenant; missing-translation telemetry recorded per key/language.

- RTL-safe markup from day one (logical CSS properties), even though launch languages are LTR.

## 15. Edge Cases

EC-01 Octane connection reuse leaks app.tenant_id → mitigated by set-on-checkout/clear-on-release with a test that asserts the var is empty on a fresh checkout. EC-02 Job dispatched inside a transaction that rolls back → events unaffected (outbox), but plain jobs must use afterCommit dispatching; convention C-06 makes afterCommit the default bus configuration. EC-03 Tenant suspended while staff sessions active → next request hits state middleware, session invalidated, banner login screen. EC-04 Tenant suspended mid-provisioning → provisioning job checks state between steps; halts cleanly, resumable. EC-05 Duplicate provisioning trigger (double-click, retry) → idempotency key = tenant id + step; safe. EC-06 Settings write race (two admins) → last-write-wins with both writes audited and a conflict toast to the loser (compare updated_at optimistic check). EC-07 Reference data version skew during deploy (old nodes + new seed) → additive-only seeds within a release; removals require two-release deprecation. EC-08 Clock skew across nodes → all ordering decisions use DB time or UUIDv7 monotonicity, never app-server wall clocks. EC-09 A tenant’s quota lowered below current usage → no destructive action; writes above quota blocked going forward, alert raised, grace policy configurable. EC-10 Partition maintenance failure (month rollover) → pre-created partitions 3 months ahead; monitor asserts future partitions exist.

## 16. Failure Scenarios

FS-01 **Redis unavailable:** settings fall back to DB reads (degraded latency, logged); rate limits fail-open for staff traffic but fail-closed for public ingestion (bounded queue); presence/realtime degrade per Doc 07’s poll fallback. Recovery: cache warm on reconnect. FS-02 **Outbox relay down:** business writes unaffected; events accumulate; alert at lag threshold; relay resumes from last relayed id — no loss, no duplication (consumer idempotency). FS-03 **RLS misconfiguration detected** (isolation test failure in prod smoke): automatic block of deploy promotion; runbook: enable emergency read-only mode flag while diagnosing. FS-04 **Provisioning step failure** (e.g., KB seed): tenant remains provisioning, step marked failed with error ref, retry idempotent; tenant never partially “active”. FS-05 **Quota counter corruption:** counters are Redis with periodic DB reconciliation; reconciliation job repairs drift and logs deltas.

## 17. Success Scenarios

SS-01 New tenant provisioned end-to-end in ≤ 2 min; all defaults present; audit trail complete. SS-02 A deliberate cross-tenant query in a code review exercise is blocked by both scope and RLS in the isolation suite. SS-03 A settings change propagates to a running agent session within 2 s without reload. SS-04 During a 10× Telegram burst, event lag stays under alert threshold and no consumer processes an event twice with side effects.

## 18. Acceptance Criteria (QA-executable)

AC-01 Isolation suite: for every tenant-scoped table, an automated test authenticates as tenant A and attempts read/write of a tenant B row via every public endpoint touching that table → expect 404/none; direct SQL as app role with A’s context selecting B’s rows → 0 rows. AC-02 Fuzz test: 500 requests with forged tenant_id in body/query/header → zero effect on resolution (proven by trace ids). AC-03 Kill each worker class mid-burst → no lost tickets/messages/events after drain (counts reconcile). AC-04 Outbox chaos test: crash relay between DB commit and queue push → event still delivered exactly-once-in-effect. AC-05 Provisioning executed twice concurrently → single consistent tenant. AC-06 All foundation strings render in Amharic with no key leakage (missing.translation telemetry = 0 for shipped keys). AC-07 p99 latency budget (§12) verified under load profile L1 (defined in Doc 19) with dashboards as evidence. AC-08 Suspended-tenant matrix: every entry surface (staff login, API, webhook, widget, DLR) behaves per FR-05.

## 19. Future Expansion

- Region-sharded deployments (tenant pinned to region) — the tenant record already carries region; routing layer added later.

- Database-per-tenant for contractual isolation tiers — the RLS + scope design means only connection resolution changes.

- Platform-level cross-tenant analytics warehouse (consented, anonymized) fed by the same event stream.

- Tenant self-service signup with billing integration (states already model it: provisioning gains a pending_payment predecessor).

## 20. Risks

R-01 *RLS session-variable misuse under Octane* (High impact / Medium likelihood) → mitigations: checkout hooks, dedicated tests (EC-01), staging parity. R-02 *Event bus becomes implicit coupling* (Medium/Medium) → schema-versioned event payloads; consumer contract tests in CI. R-03 *Settings sprawl* (Medium/High) → registry review gate: new keys require doc-owner sign-off; unused-key telemetry. R-04 *Quota disputes with operators* (Low/Medium) → usage APIs + 80% alerts give transparency before enforcement. R-05 *Partitioned tables complicate ORMs* (Medium/Low) → partitioning only on append-only tables (messages, events, audit) accessed via repositories, not ad-hoc Eloquent.

## 21. Recommendations

- Build T-01…T-05 as the first sprint of the entire project and freeze their interfaces; every other team builds on them in parallel from week 2.

- Treat the isolation suite (AC-01/02) as a deploy gate from the very first environment — retrofit certainty is impossible.

- Adopt the outbox pattern now even though it feels heavy at MVP scale; it is the difference between “reporting is eventually right” and “reporting is quietly wrong.”

- Keep the platform console deliberately minimal — it is internal tooling; invest UI effort in the operator-facing Backoffice (Doc 16).

- Document every published event in a living catalog (name, payload schema, producers, consumers) checked in CI — this catalog becomes the integration contract for Docs 04–17.

*End of Document 01. Next in series: Document 02 — Authentication, Sessions **&** Security Core.*