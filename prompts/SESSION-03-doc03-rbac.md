# SESSION — DOC 03: RBAC — ROLES, PERMISSIONS & OVERRIDES

You are the principal engineer and platform architect for **Na Live Support**, with 25+ years
building authorization systems for platforms of this class (Zendesk, Intercom, Stripe-grade).
I am the product owner and I am **not a programmer** — I verify by running tests, so **never
claim anything works without pasting the executed test output.** Never simplify a spec, never
silently drop a requirement, never shorten your way past a hard part; if a spec is ambiguous
or conflicts with these instructions, STOP and ask.

RBAC is the third-most consequential module and the last of the security core: every feature
document (04–17) consumes this document's catalog and resolver. Doc 03 §21 says it plainly —
retrofit permission mapping is the classic RBAC failure. Treat the resolver, the precedence
contract, and the seeding discipline as the deliverable; the admin screens are drawn already
and you implement them, you do not design them.

**Read, in full, before writing any code:**
1. `CLAUDE.md` — the constitution; §2 lists SEVEN registries (ADR 0005) and §9 defines your
   report shape.
2. `docs/Doc03-RBAC-Roles-Permissions-Overrides.md` — this session's law, every section end
   to end, including the full §4.1 catalog. No id may be covered from a summary.
3. `docs/BUILD-STATUS.md` — the Session-18 Doc 02 close-out (final 85-id audit) AND the
   "Deferred with reason" tripwire naming FR-09 + SEC-05: your plan must claim both or they
   are lost.
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — the interpretation record. Q9 (registry normative over
   prose counts) and Q5's AC-05 ruling bind this session directly.
5. `docs/DESIGN-FIDELITY-MANDATE.md` — binds every user-visible surface you build this
   session (the RBAC admin screens are in scope; see the DESIGN FIDELITY block below).
6. The RBAC seam Doc 02 left you, read the docblocks before touching anything:
   `app/Support/Rbac/EffectivePermissions.php` (the T-05 interface — Doc 03 binds the real
   implementation; TokenScopeGate and the interface itself do NOT change),
   `app/Support/Rbac/RegisteredKeysEffectivePermissions.php` (the stub you delete when your
   resolver lands), `app/Support/Rbac/PermissionRegistry.php` (the registered-keys reader —
   your resolver is built beside it, it does not grow into one),
   `registries/permissions.php` (four Doc03-owned entries already registered — their key
   NAMES are law, NFR-03).
7. `docs/EVENT-CATALOG.md` — `tenant.provisioned` already declares `Doc03/RoleSeeder` as a
   consumer: you are filling a declared seam. The catalog's closed-payload rule (missing OR
   extra key rejected) applies to every rbac.* event you add.
8. `docs/adr/0003-database-role-separation.md` (the four-role grant model your migrations
   live under), `docs/adr/0004-traceability-gate-per-document-flip.md` (Doc 03's ACs become
   blocking the moment its BUILD-STATUS row leaves NOT STARTED), and
   `docs/adr/0005-features-registry-is-registry-seven.md`.

**Stack/context notes (not conflicts; do not stall on them):**
- Doc 03's header says "Laravel Gates" and "storage compatible with spatie/laravel-permission
  tables". The repo is Laravel 13 by owner ruling; spec stack contexts read as minimums.
  Doc 03's own T-01 defines the storage (roles with `permissions jsonb string[]` +
  `scopes jsonb`, `user_permission_overrides`, catalog in code) — build THAT. Do not install
  spatie/laravel-permission: no new dependency without an ADR and my approval (CLAUDE.md §3),
  and T-01's shape does not need it. "Compatible" is a portability note, not an instruction.
- Where `docs/ANSWERS-FROM-DOC-AUTHOR.md` and a numbered document requirement ever disagree,
  the document wins and you STOP and ask.
- Prose counts anywhere are descriptive; **code registries are normative** (ruling Q9).

## SCOPE

Implement **all of Doc 03** — enumerate every FR / NFR / US / BR / T / SEC / EC / FS / SS /
AC / R id from the document itself and cover each one. State the id count YOU found; your
plan is audited against an independent count.

**Out of scope (do not touch):** authentication mechanics (Doc 02 — you consume its guards);
department/team/queue STRUCTURES themselves (Doc 11 — you define only how scopes intersect
them, against fixture-level membership); ticket/conversation/channel routes and features
(Docs 04/05/06/13/14 — their keys seed now, their routes enforce when their docs land);
Backoffice surfaces beyond Doc 03's own §8/§9 screens (Doc 16); platform-console cross-tenant
authorization (Doc 01, platform_admin only — Doc 03 §3 excludes it). Where Doc 03 defines a
contract a later document consumes (scopeSet membership sources, queue pull-in grants), build
the contract and stub the consumer with a documented seam.

> "The attached design files are the specification of every screen in scope. Implement them
> at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme, and
> language, tokens from the design system only, deviations only in the mandate's three
> classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned."

**Pre-agreed closure buckets (build to these; do not invent new deferrals beside them):**
- **Doc 11-blocked:** real Team/Department membership sources and the queue pull-in
  system-generated expiring grants (FR-09's second half, T-03's "Doc 11 supplies membership
  sources"). YOURS now: the complete override/expiry machinery, `scopeSet()` implemented once
  per module as composable named scopes proven against seeded membership fixtures, and the
  AC-03 fixture suite as the living contract. The AC-03 traceability row records "fixture
  membership; re-proves against Doc 11's real sources" — a bare DONE there is a false DONE.
- **Docs 04/06-blocked:** enforcement on real ticket/conversation routes, including the
  inherited Doc 02 AC-05 literal fixture (verified: its wording needs a reply route, which is
  Doc 06's — the row stays PARTIAL per ruling Q5). Doc 03's AC-01 route-coverage proof runs
  over every route that EXISTS (Doc 02's + Doc 03's own); the table extends automatically as
  later docs land because the permission-mapping gate is already blocking.
- **Docs 15/17/18-blocked:** the dedicated high-risk audit view, optional admin notification
  (SEC-03), and the monthly high-risk report (US-05, R-02). YOURS now: the events, the
  per-tenant denied-check metric (FR-11), and rbac.* audit-coverage entries they'll consume.
- **Doc 19 L1:** formal perf-budget closure (NFR-01 ≤0.3 ms p99, §12 numbers). Structure for
  them (per-user capability map, one batched map read per page, Redis + explicit
  invalidation), measure indicatively in-suite, record PARTIAL bucketed to Doc 19 — the
  established pattern.
- **Design-export-blocked:** AC-08's pixel half. Functional UI parity (DOM assertions that
  controls are hidden/disabled exactly where verdict=deny, 20 sampled permissions) is YOURS
  and DONE-able now; pixel baselines attach when I fire the Batch 6 export. The traceability
  row says so instead of claiming visual coverage.

## STEP 0 — Doc 02 micro-gaps (close FIRST, before any Doc 03 work; they are Doc 02-owned and tiny)
1. One test asserting the session cookie's HttpOnly/Secure/SameSite flags (Doc 02 FR-10's
   named gap — config exists, no executed assertion).
2. The EC-01 time-sync help-copy string on the 2FA challenge, `en` AND `am`, through the
   i18n chain (one string; the enumeration suite must stay untouched — Doc 02 S13 rule).
3. One test for FR-13's remember-cookie recaller path (the login-via-recaller itself).
These commit as step 0 with their own traceability updates before your plan's step 1 begins.

## INHERITED FROM DOC 02 (each is a mandatory plan-table row, not a footnote)
1. **FR-09 (Doc 02)** — 2FA role enforcement + grace countdown. The middleware and the
   `auth.2fa.required_roles` / `grace_days` settings exist and are validated; they need your
   real role resolver. Tripwired in BUILD-STATUS.
2. **SEC-05 (Doc 02)** — per-role IP allowlists + the `auth.ip.blocked` event.
   `FailureReason::IpBlocked` is defined and nothing fires it; the event is absent from the
   catalog. You wire the allowlist store (per-role), the check, the event (closed payload),
   and the audit entry. Tripwired in BUILD-STATUS.
3. **EffectivePermissions resolver swap** — bind your real resolver behind the UNCHANGED
   interface; delete `RegisteredKeysEffectivePermissions`; the T-05 intersection engine
   (TokenScopeGate) is finished and must not change. Its 10 s per-token cache now composes
   with your capability cache — see SUBTLE PARTS.
4. **RequirePermission flips declare → enforce** for staff sessions (its docblock documents
   the seam). Every existing `permission:` route declaration becomes a real check the moment
   the flip lands — sequence it after the resolver + seeder exist or you lock everyone out
   of the test fixtures; say so in the plan's ordering rationale.
5. **Admin surfaces to real permission-gated HTTP routes:** `admin.2fa.reset` (Doc 02 FR-08's
   endpoint exists, enforcement was the documented seam), the S5 admin unlock
   (`unlockByAdmin`, unit-proven only — needs its route), and BR-04's UserDisabler
   (`admin.users.disable`). Each route ships mapped in the permission registry in the same
   commit — the gate is blocking.
6. **Service-account role assignment (BR-07)** — Doc 02 FR-19's service accounts exist
   role-less. Identical rules, NO special system role: they get a real role like anyone, and
   the machine-token intersection then runs against genuinely resolved permissions.
7. **Doc 02 AC-05 literal fixture** — stays PARTIAL bucketed to Doc 06 (verified against the
   AC's text: it requires a reply route). NOTE: the AC's wording says `tickets.read`; Doc 03's
   closed catalog defines `tickets.view`. The catalog governs (BR-03, NFR-03) — do NOT invent
   a `tickets.read` key; when Doc 06 builds the fixture it uses catalog keys, and the mapping
   `AC-05 "tickets.read" ≙ tickets.view` is filed as an interpretation, not silently assumed.
8. **Registry seeding** — see THE SUBTLE PARTS: the 111-key rule.

## THE SUBTLE PARTS (spell each out in your plan; every one traces to Doc 03's text or a filed ruling)
- **111 vs 118 — the seeding rule.** Doc 03 §4.1 ENUMERATES 111 keys; its closing line says
  "118 v1 keys"; prose elsewhere says ~130. Registry is normative (Q9): seed the 111
  enumerated keys VERBATIM and nothing else. NEVER invent keys to satisfy a prose number.
  The discrepancy is filed with the doc author; if the missing seven ever arrive, they enter
  by owner ruling. `views.share` (Doc 07) and `ai.providers.manage` (Doc 09) are other docs'
  keys and register when their docs land — the DESIGN's matrix shows them (113 rows) as
  illustrative content; your matrix is REGISTRY-DRIVEN and shows what is seeded. The four
  existing entries (`settings.view`, `settings.edit`, `admin.2fa.reset`,
  `apikeys.machine.manage`) keep their exact names — verify against §4.1, don't re-derive.
- **Registry schema extension (FR-01).** The catalog requires per-key: module, i18n label,
  i18n description, risk level (normal/high), scope dimension (scoped/unscoped). The current
  `registries/permissions.php` schema lacks risk/description/scope-dimension. Extend the
  schema ADDITIVELY (existing four entries gain the new fields, keys unchanged), update
  `registry:check` to validate the new fields, and mark high-risk exactly as §4.1 marks
  "(high)" — 20 keys carry it; count them from the text, not from memory.
- **Precedence is a normative, falsified contract:** User Deny > User Allow > Role > implicit
  deny (FR-04/FR-05). AC-02's matrix is 10 combinations of {role grant yes/no} ×
  {override none/allow/deny} × {expired n/a/yes} — implement the resolver as an explicit
  decision sequence and prove every cell, then sabotage the order (swap deny/allow
  precedence) and paste the matrix failing.
- **Overrides cannot alter scopes** (FR-04, documented limitation): an override grants/denies
  the VERB, evaluated within the role's scope for that module. The nullable scope column is
  reserved (§19) — reserve it, don't use it.
- **Scopes fail closed** (FR-03, NFR-04, T-03): `scopeSet()` returns a composable named
  Eloquent scope per module — Own / Team / Department / All; a missing filter yields an EMPTY
  set, never open (Doc 01 RLS remains the backstop, not the mechanism). EC-04: Team scope
  with no team = empty-team set, fails closed; the role editor warns on save. EC-06: scoped
  verb with no object (list endpoints) → scope applies as filter, verdict allow — documented
  so nobody double-checks per row.
- **Seeded templates and the missing Appendix B (STOP-AND-PROPOSE — do not seed on your own
  authority).** FR-10 names nine templates and cites "Appendix B of the implementation repo"
  for exact grants. No Appendix B exists in the repo. Constraints that ARE in the document:
  Super Admin = all permissions, all scopes All; Read Only = exclusively `.view` keys, proven
  by AC-06's full mutation-route sweep (BR-05); RG keys (`customers.rg_flag.*`) in NO seeded
  agent template (BR-06); templates are `is_system`, editable, not deletable while assigned
  (FR-02); releases never overwrite edited templates (EC-07). In your PLAN, propose the full
  nine-template grant matrix derived from the catalog under those constraints, as a table I
  ratify — once approved it is committed as `docs/Doc03-AppendixB-role-templates.md` + the
  seeder fixtures, and the ruling is filed in the interpretation record.
- **Seeder idempotency under real concurrency:** `tenant.provisioned → Doc03/RoleSeeder`
  fills a declared seam; the seeder must be idempotent under Doc 01's provisioning
  concurrency proofs (provisioning runs twice → one consistent set of templates), and EC-07's
  never-overwrite-edited rule is part of the idempotency contract, not a separate feature.
- **NFR-03 never-rename**, enforced twice: T-05(b)'s catalog snapshot test (deletion/rename
  fails CI) and T-05(c)'s seeded-template drift test. Deprecation = new key + two-release
  migration, and no deprecation exists in v1.
- **Last-admin protection (FR-08a):** the LAST user holding `admin.roles.manage` +
  `admin.users.manage` cannot be demoted/disabled — hard block with the explanatory error
  naming who else holds the rights (§8 error states). This must also hold through
  UserDisabler (inherited item 5) and through role edits that would strip the pair — prove
  both paths, then sabotage the guard and paste AC-05 failing.
- **Four-eyes (FR-08b, T-06, EC-08, FS-04):** `rbac.four_eyes` off by default; when on,
  high-risk grants write a `pending_grants` row the resolver IGNORES; approval by a DISTINCT
  second admin applies + audits; auto-expire 24 h; the setting REFUSES to enable without ≥2
  users holding approval rights (EC-08 — the deadlock guard); approval-job failure leaves
  grants pending, visible, fail-safe (FS-04). AC-05's four-eyes clause proves the distinct-
  second-admin rule.
- **Expiry is resolver truth, not cron truth** (FR-09, EC-09): `expires_at` is enforced by a
  resolver time check against DB time (Doc 01 EC-08 — no app-server wall clocks), plus a
  cleanup job that only tidies. AC-07 proves allowed-before / denied-after /
  `rbac.override.expired` emitted / audit complete. EC-02: mid-action expiry denies the
  commit and the UI names the granter.
- **Two caches now compose:** your capability cache `cap:{tenant}:{user}` (TTL 10 s +
  explicit invalidation on rbac events, T-02) sits under Doc 02's 10 s per-token scope cache
  (its EC-06). Worst-case staleness is bounded by BOTH — state the combined bound in your
  plan and prove propagation ≤10 s end-to-end on the token surface too, not only the session
  surface. FS-02: pub/sub lost → the TTL bounds staleness. FS-01: Redis down → per-request DB
  resolution, degraded within NFR×10, alert fires, NO security regression — falsify by
  killing the cache and asserting verdicts unchanged.
- **Cache-bypass for the highest-sensitivity checks** (R-04, §21): `customers.rg_flag.*` and
  `audit.view`/`audit.export` deny paths bypass the capability cache — always fresh. Cheap,
  absolute, and a named test.
- **Enforcement is five-point, all mandatory (FR-06):** API middleware/policies (server
  truth), UI capability map, queued jobs re-resolve AT EXECUTION TIME (SEC-06 — a demoted
  user's queued action runs under post-demotion rights, tested), exports re-check per row
  set, Reverb channel authorization re-checks on subscribe. The UI never relies on hiding
  alone (FR-11, SEC-01).
- **Deny responses:** 403 problem-details `{code:"forbidden", permission:key}` naming the key
  for staff clients (T-02 diagram, FR-20-shape), BUT object-existence stays masked per
  Doc 01 SEC-06 — out-of-scope object READS are 404, not 403 (SEC-02). Get both right in the
  same test.
- **FR-11:** every API check failure logged (actor, permission, object type/id, trace_id) and
  counted per tenant — a security metric, wired now.
- **UI capability payload is minimal (T-04):** Inertia shares only the keys the page uses,
  from a per-page manifest — never the full matrix to the client.
- **One role per user (FR-02, BR-01).** No multi-role, ever; composite needs = new role or
  override. Role fields: name unique per tenant, description, color, permission set,
  per-module scope map, `is_system`.
- **Overrides are exceptions (BR-02):** warn when > `rbac.override.warn_threshold` (default
  10) users carry overrides for the same permission. **Strict-grant (BR-04):**
  `rbac.strict_grant`, default off. **Reasons (SEC-04):** mandatory, min 10 chars, enforced +
  audited. All three are registered settings with platform validation.
- **Catalog/boot mismatch fails safe (T-01, FS-03):** boot-time check asserts DB grants ⊆
  catalog; orphan DB keys logged + ignored, never enforced; missing-in-DB defaults deny.
- **Concurrent role edits (EC-03):** optimistic version check; loser sees the winner's diff
  and can re-apply. **Role deletion blocked while assigned (FR-08c, EC-01):** count +
  reassign shortcut; EC-01's "role deleted mid-session" is therefore structurally impossible
  — the test asserts the block, not the aftermath.
- **500-roles headroom (EC-10):** soft-limit warning at 100 (a setting), architecture
  supports more; per-user resolution means no cross-join.
- **The no-impersonation sweep stays green.** NoImpersonationTest sweeps routes, all seven
  registries, and the file tree; your override machinery, capability payloads, and admin
  routes must not trip it — run it explicitly after the resolver lands and cite the run.
- **Events (FR-12, T-07):** rbac.role.created|updated|deleted, rbac.user.role_changed,
  rbac.override.added|removed|expired, rbac.highrisk.granted|confirmed|rejected — through
  the Doc 01 outbox, old/new payloads for Audit, closed payload schemas (identifiers only —
  the 13-month PII rule), catalog regenerated, every one in audit-coverage.
- **i18n discipline (§14):** labels/descriptions en+am; scope helper sentences ICU-templated
  with correct grammar; role names/reasons are operator free text; exports carry stable key +
  localized label. Machine-authored Amharic joins the standing native-review queue — flag
  new destructive-tier strings (role delete, override deny confirmations) in your close-out.

## PROTOCOL (unchanged, in full)
1. **PLAN FIRST**: a table of every Doc 03 id → file(s) → proving test, plus rows for STEP 0,
   every INHERITED item, and the frame-mapping rows required by the DESIGN FIDELITY block
   (design frame → component/file → proving test, enumerated like spec ids). State the id
   count you found. Include your proposed nine-template Appendix B grant matrix. Sequence
   safety tests BEFORE the features they constrain, and justify any counter-intuitive
   ordering (the enforce-flip sequencing at minimum). WAIT for my approval.
2. One commit per numbered step; registries updated as you go (permissions — schema extension
   + the 111, settings, audit-coverage, features if any, traceability rows — the per-document
   flip makes Doc 03's ACs blocking the moment its BUILD-STATUS row leaves NOT STARTED,
   ADR 0004).
3. **Falsify every safety mechanism you build, with a positive control for every assertion,
   sabotage BEFORE counting anything proven**: swap the precedence order → AC-02's matrix
   fails; remove a module's scope filter → the fail-closed test returns empty-set-not-open or
   fails; delete the last-admin guard → AC-05 fails; let a pending high-risk grant resolve
   without a second admin → the four-eyes test fails; freeze time past an override's expiry →
   AC-07 fails; serve the capability cache stale past the bound → the propagation test fails.
   Paste fail AND restored-pass, every time. Backup before sabotage; nothing commits until
   `git status`/`git diff` proves the tree is clean of falsification damage.
4. Every user-visible string is an i18n key with `en` AND `am` entries; `string-lint` stays
   green; the Doc 02 enumeration suite stays untouched by your new strings.
5. Targeted tests while iterating; the FULL `make test`, `make gates`, `make lint` once per
   step-commit — pasted real output. Push and record the CI run ID at session close per the
   efficiency ruling (exceptions: CI/infra-file changes, and rulings that name CI as a gate).
6. Finish with the full Doc 03 self-audit table (every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket; a false DONE is a Sev-1), the
   DESIGN-DEVIATIONS section updated in BUILD-STATUS (an unfiled deviation is a fidelity
   violation), and the CLAUDE.md §9 report: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS /
   NEXT SESSION. Then STOP.

## DESIGN FIDELITY (mandatory — Doc 03's §8/§9 screens are in scope this session)
Per DESIGN-FIDELITY-MANDATE.md §5, which is in your reading list:
- I will attach these design files to this session; they are the specification of every
  screen you build: **B6-S07-roles-list-v1, B6-S08-permission-matrix-v2,
  B6-S09-user-overrides-v1, B6-S10-rbac-safety-rails-v1**, plus — always — the design
  system: **B1-DS-design-system-v2** (token source, §2 both colourways),
  **B1-DS-addendum-B5-v1, B1-DS-v3-addendum-B6-v1, B1-DS-v4-addendum-B7-v1** (frozen system
  of record). If any listed file is not attached, STOP and ask before building its surface.
- Your plan table maps frames like spec ids: for each of the four screen files, enumerate its
  frames/states and map frame → component/file → proving test. A designed surface with no
  frame rows is an incomplete plan.
- Tokens first: verify the existing token encoding against the design-system files (or encode
  once) before the first screen; all styling consumes tokens, never literals. Dark values
  come from v2 §2's dark column — never mixed by eye; fixed tokens (R-07/R-08) keep their
  exact values.
- The matrix is REGISTRY-DRIVEN: the design's 113 rows include two other-doc keys as
  illustrative content (a filed design-track ruling); you render what the registry seeds.
- Doc 03 §9's mobile rule matches the drawn frames: matrix → accordion ≤1024 px; editor
  usable on tablet; READ-ONLY on phones with the explicit "editing requires a larger screen"
  notice — a drawn ruling; it ships.
- Behavior as drawn: tri-state module select-all with `aria-checked="mixed"`, segmented scope
  control with ICU helper sentence, risk shield badges (icon + text, never color-only),
  verdict chips carrying the WHY, diff views for compare and the impact modal, countdown
  chips, unsaved-changes guard, matrix keyboard map (arrows move, space toggles, `/` focuses
  search), empty/error/loading states as drawn. AC-08's DOM half and Doc 03 §13's a11y
  contract are proven in the browser suite at the drawn viewports, en+am, light+dark.
- Deviations only in the mandate's three classes, every one filed in BUILD-STATUS's
  DESIGN-DEVIATIONS section AND named in your close-out. Design-vs-document conflict → STOP
  and ask. Design gap → class-2 fill with design-system components, filed. Design defect →
  class-3 flag, wait.

Then STOP.
