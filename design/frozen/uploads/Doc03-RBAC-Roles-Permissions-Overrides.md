Table of Contents

# Document 03 — RBAC: Roles, Permissions & Overrides

**Product:** AI-First Support Platform for Betting Companies **Document series:** 03 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, security, QA, product, operator admins **Depends on:** Doc 01 (tenancy, settings, events), Doc 02 (guards, token scoping). Consumed by: every feature document (04–17). **Stack context:** Laravel Gates, custom resolver; storage compatible with spatie/laravel-permission tables

## 1. Executive Summary

This document specifies the platform’s authorization system: an enterprise role-based access control (RBAC) engine with **no fixed roles**, a catalog of ~130 atomic permissions across 14 modules, per-module **data scopes** (Own / Team / Department / All), and **per-user overrides** with the precedence rule User Deny > User Allow > Role grant > implicit deny. It defines the complete permission catalog (the contract every other module document references), the resolution algorithm, the administration screens (role editor, permission matrix, user overrides with effective-permission preview), seeded role templates, high-risk grant controls, propagation guarantees, and the QA matrix that certifies enforcement. Every endpoint, button, view, and background action in the platform maps to exactly one permission from this catalog; a feature without a registered permission cannot ship (CI-enforced).

## 2. Purpose

- Provide the single authoritative permission catalog all other documents cite by key (e.g., Doc 06 references tickets.merge — defined only here).

- Specify resolution semantics precisely enough that backend, frontend, and QA implement identical behavior.

- Specify the admin UX so operators can self-serve unlimited roles, per-feature grants, and per-user exceptions with zero engineering.

- Define the safety rails (last-super-admin protection, high-risk grants, expiring overrides) that prevent operators from locking themselves out or over-granting silently.

## 3. Scope

**In scope:** permission catalog; role model; data scopes; user overrides; resolution algorithm; caching & propagation; admin UI (roles list, role editor/matrix, compare, user override editor); seeded templates; department/queue intersection rules; temporary access; RBAC events & audit hooks; enforcement conventions for API, UI, jobs, and exports. **Out of scope:** authentication (Doc 02); department/team/queue structures themselves (Doc 11 — this doc defines only how scopes intersect them); platform-console (cross-tenant) authorization (Doc 01, platform_admin only).

## 4. Functional Requirements

FR-01 Permissions are atomic strings module.action (or module.entity.action), registered in a code-side catalog (enum-backed) with: key, module, i18n label, i18n description, risk level (normal/high), and applicable scope dimension (scoped/unscoped). FR-02 Roles: unlimited per tenant; fields: name (unique per tenant), description, color, permission set, per-module scope map, is_system flag for seeded templates (editable, not deletable while assigned). A user has exactly one role. FR-03 Data scopes per module (where scoped): **Own** (records assigned to/created by the user), **Team** (records of the user’s teams), **Department** (records of departments the user belongs to), **All** (tenant-wide). Scope applies to reads and to the object set on which action permissions operate. FR-04 User overrides: list of {permission, effect: allow|deny, expires_at?, granted_by, reason}; resolution precedence User Deny > User Allow > Role > implicit deny. Overrides cannot alter scopes (scope comes from the role; an override grants/denies the verb, evaluated within the role’s scope for that module — documented limitation, see §19 for scoped-override expansion). FR-05 Resolution algorithm (normative):

effective(user, permission, object?) :=
  if override(user, permission) == deny  → DENY
  if override(user, permission) == allow → continue to scope check
  else if permission ∉ role.permissions  → DENY
  scope check (if permission is scoped and object given):
     object ∈ scopeSet(role.scope[module], user) ? ALLOW : DENY
  unscoped or no object (e.g., list endpoints apply scope as filter) → ALLOW

FR-06 Enforcement points (all mandatory): API middleware/policies (server truth), UI capability map (hide/disable controls), queued jobs acting “as user” re-resolve at execution time, exports re-check per row set, Reverb channel authorization re-checks on subscribe. FR-07 Propagation: any role/override change invalidates affected users’ capability cache; effect ≤ 10 s worst case (Doc 02 T-05 aligns); active sessions keep working with reduced/added capabilities without re-login. FR-08 Safety rails: (a) the last user holding admin.roles.manage + admin.users.manage cannot be demoted/disabled (hard block with explanatory error); (b) high-risk permission grants emit rbac.highrisk.granted and, if setting rbac.four_eyes is on, require confirmation by a second admin within 24 h before taking effect (pending state visible); (c) role deletion blocked while assigned (shows count + “reassign users” shortcut). FR-09 Temporary access: overrides support expires_at; queue pull-ins (Doc 11) create system-generated scoped grants expiring at shift end; expiry is enforced by resolver time check (no cron dependency) plus a cleanup job. FR-10 Seeded role templates (created at tenant provisioning, all editable): Super Admin (all permissions, all scopes All), Admin, Manager, Team Leader, Senior Agent, Agent, Junior Agent, QA, Read Only — exact grants listed in Appendix B of the implementation repo, derived from the catalog below. FR-11 Every permission check failure on the API is logged (actor, permission, object type/id, trace_id) and counted per tenant (security metric); the UI never relies on hiding alone. FR-12 RBAC events: rbac.role.created|updated|deleted, rbac.user.role_changed, rbac.override.added|removed|expired, rbac.highrisk.granted|confirmed|rejected — all with old/new payloads for Audit (Doc 17).

### 4.1 Permission Catalog (normative, v1)

**Tickets (scoped)** — tickets.view, tickets.create, tickets.edit, tickets.reply, tickets.note, tickets.assign, tickets.assign_self, tickets.status.change, tickets.priority.change, tickets.department.change, tickets.close, tickets.reopen, tickets.merge, tickets.split, tickets.escalate, tickets.follow, tickets.tags.manage, tickets.fields.edit, tickets.spam.mark, tickets.delete (high), tickets.export (high)

**Conversations (scoped)** — conversations.view, conversations.history.full (vs. windowed), conversations.transcript.export (high)

**Customers (scoped)** — customers.view, customers.create, customers.edit, customers.notes.add, customers.notes.view, customers.identity.link, customers.identity.unlink (high), customers.merge (high), customers.block, customers.rg_flag.view (high), customers.rg_flag.set (high), customers.export (high), customers.delete (high)

**Telegram (unscoped module admin + scoped messaging)** — telegram.send (scoped via tickets), telegram.message.delete, telegram.bots.view, telegram.bots.manage (high), telegram.connections.manage (high), telegram.health.view

**Web chat** — webchat.respond (scoped via tickets), webchat.widgets.view, webchat.widgets.configure (high)

**SMS** — sms.send, sms.templates.view, sms.templates.create, sms.templates.edit, sms.templates.delete, sms.gateways.view, sms.gateways.manage (high), sms.optout.manage

**AI** — ai.copilot.use, ai.copilot.insert_draft, ai.chatbot.configure (high), ai.guardrails.edit (high), ai.thresholds.edit (high), ai.decisions.view, ai.killswitch (high)

**Knowledge Base** — kb.view_internal, kb.articles.create, kb.articles.edit, kb.articles.delete, kb.articles.publish, kb.categories.manage, kb.feedback.view

**Automation ****&**** Workflow** — automation.rules.view, automation.rules.edit, automation.rules.activate (high), macros.personal.manage, macros.shared.manage, forms.view, forms.edit, forms.publish (high), fields.manage (high), sla.policies.view, sla.policies.edit (high)

**Organization** — org.departments.manage (high), org.teams.manage, org.queues.manage, org.queues.pull_in, org.business_hours.edit

**Users ****&**** Roles** — admin.users.view, admin.users.manage (high), admin.users.disable (high), admin.roles.view, admin.roles.manage (high), admin.overrides.manage (high), admin.2fa.reset (high)

**Reports (scope applies to data visibility)** — reports.view, reports.dashboards.create, reports.dashboards.share, reports.export (high), reports.schedule, reports.supervisor.live, reports.agents.individual (high — per-person metrics)

**Supervision** — supervision.monitor (high), supervision.whisper (high), supervision.reassign, supervision.agent_status.force (high), supervision.broadcast

**Platform settings** — settings.view, settings.edit (high), branding.edit (high), features.toggle (high), languages.manage, audit.view (high), audit.export (high), apikeys.machine.manage (high)

*(Catalog count: 118 v1 keys + reserved namespaces; additions require doc-owner sign-off per Doc 01 R-03.)*

## 5. Non-functional Requirements

NFR-01 Resolution ≤ 0.3 ms p99 per check from the per-user capability cache; cold rebuild ≤ 20 ms. NFR-02 Capability cache invalidation propagates ≤ 10 s cluster-wide (Redis pub/sub), typically ≤ 1 s. NFR-03 The permission catalog is backward-compatible within a major version: keys are never renamed or repurposed; deprecation = new key + two-release migration. NFR-04 Scope filters are implemented as composable query scopes so a missing filter fails closed (empty set), never open (Doc 01 RLS remains the backstop). NFR-05 The matrix UI remains usable at 130+ permissions (search, module grouping, sticky headers) and loads ≤ 1 s. NFR-06 100% of API routes are covered by a policy/permission mapping test (route→permission table asserted in CI; unmapped route fails the build).

## 6. User Stories

US-01 As an operator admin, I create a “Payments Specialist” role with full ticket rights scoped to Department, SMS send, and no admin access, in under 5 minutes. US-02 As an admin, I give one trusted agent customers.merge for two weeks via an expiring override, with a reason logged. US-03 As a team leader, I can reassign and monitor within my department but cannot see other departments’ tickets or any settings. US-04 As a QA analyst, I can read all conversations and score them, but every reply/edit control is absent for me. US-05 As a security officer, I review a monthly report of high-risk grants and permission-denied spikes. US-06 As a developer, adding a new endpoint without registering its permission breaks CI, so the catalog is always complete. US-07 As an admin, before saving a role change I see exactly which users are affected and can preview any user’s effective permissions.

## 7. Business Rules

BR-01 One role per user (simplicity + auditability); composite needs are met by creating a new role or an override, never multi-role. BR-02 Overrides are exceptions, not policy: a warning surfaces when > rbac.override.warn_threshold (default 10) users carry overrides for the same permission — signal to create a role. BR-03 Nobody can grant what the platform doesn’t define: no free-text permissions; the catalog is closed. BR-04 Strict-grant mode (setting rbac.strict_grant, default off): an editor cannot grant a permission they don’t themselves hold; default off because operators often have a single owner-admin bootstrap pattern. BR-05 Read Only means read only: seeded template contains exclusively .view keys; QA verifies no mutation route accepts it. BR-06 RG-related permissions (customers.rg_flag.*) are never included in seeded agent templates; granting them is always an explicit act. BR-07 Service accounts (Doc 02 machine tokens) follow identical rules — no special system role exists.

## 8. UX Requirements

- **Roles list:** table (name w/ color dot, description, users count, high-risk count badge, updated); actions: create, clone, compare (select 2 → side-by-side diff), delete (guarded).

- **Role editor:** header (name, description, color) → scope bar per scoped module (segmented control Own/Team/Department/All with plain-language helper: “Agents with this role can see tickets belonging to: …”) → permission matrix grouped by module: module header row with tri-state select-all, rows = permission label + info tooltip (description + risk), high-risk rows carry a shield badge; search filters live; right sidebar summary (“42 permissions · 6 high-risk · 13 users affected”); unsaved-changes guard; Save shows an impact modal (affected users, added/removed keys) before commit.

- **User override editor:** on the user profile: current role (link), effective-permissions explorer (search any key → shows verdict + why: “Denied by user override, set by Hanna, expires Aug 4”), add-override flow (pick permission → allow/deny → optional expiry → mandatory reason), list of active overrides with countdown chips.

- **Four-eyes pending state (if enabled):** banner on the role/user showing pending high-risk grants with approve/reject for other admins.

- **Empty states:** no custom roles yet → template gallery explanation; no overrides → “No exceptions for this user.”

- **Error states:** last-super-admin block (explains exactly why + who else holds the rights); delete-while-assigned (count + reassign shortcut).

- **Loading:** matrix skeleton (module groups shimmer); optimistic toggle with rollback toast on save failure.

## 9. UI Requirements

- Components: tri-state checkbox (module select-all shows indeterminate), segmented scope control, risk shield badge, verdict chip (Allowed/Denied with source), diff view (added=green/removed=red rows) for compare and impact modals, countdown chip for expiries.

- Matrix keyboard navigation: arrow keys move cells, space toggles, / focuses search.

- Responsive: matrix becomes an accordion by module ≤ 1024 px; editor fully usable on tablet; read-only on phones with an explicit “editing requires a larger screen” notice (deliberate: fat-finger risk on 130 checkboxes).

- Dark/light themes; risk badge never color-only (icon + label).

## 10. Technical Requirements

T-01 Storage: roles (permissions jsonb string[], scopes jsonb), user_permission_overrides (per Doc 01 schema); catalog in code as enum + attributes; a boot-time check asserts DB grants ⊆ catalog (orphan keys logged and ignored, never enforced). T-02 Resolver service: builds per-user capability map {permission → verdict, scope map} cached in Redis cap:{tenant}:{user} (TTL 10 s + explicit invalidation on rbac events); exposes can($user,$perm,$object=null) consumed by Gate::before, route middleware perm:tickets.reply, Blade/Inertia capability payload, job wrapper, and export row-filter. T-03 Scope sets: scopeSet(module, user) returns a query constraint (assignee_id = user / team_id in … / department_id in … / none); implemented once per module as a named Eloquent scope; Doc 11 supplies membership sources including temporary queue grants. T-04 UI capability map: Inertia shares a minimal can{} map per page (only keys the page uses — computed from a per-page manifest) to avoid shipping the full matrix to clients. T-05 CI gates: (a) route→permission mapping table test (NFR-06); (b) catalog snapshot test (accidental key deletion/rename fails); (c) seeded-template drift test. T-06 Four-eyes implementation: high-risk grant writes a pending_grants row; resolver ignores pending; approval by a different admin applies + audits; auto-expire 24 h. T-07 Everything emits the §FR-12 events through the Doc 01 outbox.

**Resolution data flow (text diagram):**

request → guard (Doc 02) → perm middleware
   → resolver.can(user, key, object?)
        cache hit? ── yes → verdict (+scope constraint applied to query)
                   └ no  → build map (role + overrides + expiries) → cache → verdict
   deny → 403 problem-details {code:"forbidden", permission:key} → metric+log

## 11. Security Requirements

SEC-01 Server-side checks are the only truth; UI hiding is UX, proven by API tests hitting every route with an under-privileged token. SEC-02 Deny paths return 403 with the permission key for staff clients, but object-existence is still masked per Doc 01 SEC-06 (404 for out-of-scope object reads). SEC-03 High-risk events feed a dedicated audit view and optional admin notification (Doc 17/18). SEC-04 Overrides require a reason (min 10 chars) — enforced, audited, reportable. SEC-05 The capability cache contains verdicts only, never secrets; poisoning is mitigated by server-side rebuild on every invalidation (clients cannot write it). SEC-06 Job-as-user execution re-resolves at run time — a demoted user’s queued actions execute under post-demotion rights (documented, tested).

## 12. Performance Requirements

- 0.3 ms p99 per check (cache hit); a typical inbox page performs ≤ 30 checks server-side (batched into one map read).

- Role save affecting 200 users completes ≤ 2 s including invalidation fan-out.

- Matrix screen initial payload ≤ 150 KB; interactions ≤ 16 ms frame budget.

- Effective-permission explorer query ≤ 100 ms.

## 13. Accessibility Requirements

Matrix operable fully by keyboard with visible focus; tri-state checkboxes expose aria-checked="mixed"; verdict chips readable by screen readers including the *why* (“Denied — user override”); impact modal focus-trapped; risk conveyed by icon+text; accordion (mobile) uses proper aria-expanded; contrast AA in both themes.

## 14. Localization Requirements

Permission labels/descriptions are i18n keys (en + am shipped); module names localized; scope helper sentences localized with correct grammar per language (templated with ICU); reasons and role names are operator content (free text, any language); exports include both the stable key and the localized label.

## 15. Edge Cases

EC-01 User’s role deleted mid-session → blocked (FR-08c) so cannot occur; test asserts. EC-02 Override expires while user is mid-action → resolver time check denies the commit; UI shows “your temporary access expired” with the granter’s name. EC-03 Two admins edit the same role concurrently → optimistic version check; loser sees a diff of the winner’s changes and can re-apply. EC-04 Scope=Team but user has no team → Own-equivalent empty-team set (fails closed); role editor warns when saving a Team scope for teamless users. EC-05 Object spans scopes (ticket transferred out of the agent’s department mid-view) → next action re-checks; agent gets a “moved out of your scope” state, remains a follower if tickets.follow was set. EC-06 Permission checked with no object on a scoped verb (list endpoints) → scope applies as filter, verdict allow (FR-05); documented so devs don’t double-check per row. EC-07 Seeded template edited then a platform release updates templates → releases never overwrite edited templates; changes ship as changelog suggestions in the roles UI. EC-08 Four-eyes enabled with only one admin → pending grants would deadlock → setting refuses to enable without ≥ 2 users holding approval rights. EC-09 Clock skew vs. expiries → resolver uses DB time (Doc 01 EC-08). EC-10 An operator creates 500 roles → soft limit warning at 100 (setting), hard architecture supports it; matrix perf budget still holds (roles are per-user resolved, not cross-joined).

## 16. Failure Scenarios

FS-01 Redis capability cache down → resolver computes per request from DB (latency degrades within NFR budget ×10, alert fires, no security regression). FS-02 Invalidation pub/sub lost → 10 s TTL bounds staleness (documented maximum exposure window). FS-03 Catalog/boot mismatch after bad deploy → orphan DB keys ignored + alert; missing-in-DB keys default deny (safe). FS-04 pending_grants approval job failure → grants simply remain pending (fail-safe); visible in UI.

## 17. Success Scenarios

SS-01 An operator models their entire 40-person org (6 custom roles, 4 overrides) with no support contact. SS-02 Security review: auditor picks 10 random permissions; team demonstrates enforcement at API, UI, job, and export layers for each. SS-03 A demoted supervisor’s open tabs lose supervision controls within 10 s without re-login and with zero errors.

## 18. Acceptance Criteria

AC-01 Route coverage: CI table proves every route maps to a catalog key; hitting each route with a token lacking that key → 403/404 per spec. AC-02 Precedence matrix test: for {role grant ∈ {yes,no}} × {override ∈ {none,allow,deny}} × {expired ∈ {n/a,yes}} all 10 combinations produce the normative verdicts. AC-03 Scope test per module: fixtures across Own/Team/Department/All verify exact visible sets on list and object endpoints. AC-04 Propagation: role change reflected in an open session’s capabilities ≤ 10 s (automated browser test). AC-05 Safety rails: last-super-admin demotion blocked; delete-assigned-role blocked; four-eyes flow (when on) requires a distinct second admin. AC-06 Read Only template passes a full mutation-route sweep with zero accepted writes. AC-07 Override with expiry: allowed before, denied after, rbac.override.expired emitted, audit complete. AC-08 UI parity: for 20 sampled permissions, controls hidden/disabled exactly where verdict=deny (visual + DOM assertions).

## 19. Future Expansion

- Scoped overrides (override carries its own scope) — model reserved via nullable scope column.

- Attribute-based rules (e.g., “may close tickets < priority High”) as a constraint layer atop verbs.

- Role packages/marketplace: exportable role sets between tenants (platform-signed).

- SSO group mapping (Doc 02 §19) → role assignment rules with conflict resolution UI.

- Delegated administration: department-limited admin rights (scope dimension applied to admin.* keys).

## 20. Risks

R-01 Catalog bloat degrading admin UX (High likelihood) → naming conventions, module grouping, search, and the sign-off gate (Doc 01 R-03). R-02 Operators over-granting to “make it work” (High/Medium impact) → high-risk badges, grant events, monthly high-risk report, override-threshold warnings. R-03 Scope logic divergence across modules (Medium/High) → single named-scope implementation per module + AC-03 fixtures as the contract. R-04 Cache staleness exploited in the 10 s window (Low/Low) → documented bound; high-risk deny paths (rg_flag, audit) bypass cache (always fresh). R-05 Four-eyes friction causing it to stay off (Medium/Low) → default off is accepted; recommend enabling once ≥ 3 admins exist (in-product tip).

## 21. Recommendations

- Implement the resolver + route-mapping CI gate before any feature module merges its first endpoint — retrofit mapping is the classic RBAC failure.

- Treat this catalog as the API between product and engineering: new features begin by proposing their permission keys in this document’s change log.

- Keep one-role-per-user until a real multi-role need is proven; overrides absorb the exceptions cheaply.

- Bypass the capability cache on the handful of highest-sensitivity checks (RG flag, audit access) — the cost is trivial, the guarantee is absolute freshness.

- Give QA the AC-02 precedence matrix and AC-03 scope fixtures as living test suites — they are the regression net for every future module.

*End of Document 03. Next in series: Document 04 — Conversations **&** Messaging Core.*