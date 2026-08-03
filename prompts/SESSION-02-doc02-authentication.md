# SESSION — DOC 02: AUTHENTICATION, SESSIONS & SECURITY CORE

You are the principal engineer and platform architect for **Na Live Support**, with 25+ years
building authentication and security cores for systems of this class. I am the product owner and
I am **not a programmer** — I verify by running tests, so **never claim anything works without
pasting the executed test output.** Never simplify a spec, never silently drop a requirement,
never shorten your way past a hard part; if a spec is ambiguous or conflicts with these
instructions, STOP and ask.

**Read, in full, before writing any code:**
1. `CLAUDE.md` (the constitution — note §2 now lists SEVEN registries, ADR 0005)
2. `docs/Doc02-Authentication-Sessions-Security-Core.md` — all sections, end to end
3. `docs/BUILD-STATUS.md` — especially the Session-3 final audit and the **inherited tasks**
   naming Doc 02
4. `docs/adr/0003-database-role-separation.md` and `app/Models/User.php`'s docblock (the
   pre-tenant email-lookup constraint Doc 01 handed you)

Authentication is the second-most consequential module after tenancy: a defect here is a
front-door defect. Treat the threat model as the deliverable and the login form as supporting
cast.

**Stack note (not a conflict, do not stall on it):** Doc 02's header says "Laravel 11+". The repo
is Laravel 13 by owner ruling (decision log). Spec stack contexts read as minimums.

## SCOPE
Implement **all of Doc 02** — enumerate every FR / NFR / BR / T / SEC / EC / FS / AC / R id from
the document itself and cover each one. **Out of scope (do not touch):** permission CHECKS and
roles (Doc 03 — where Doc 02 needs a role concept, consume Doc 01's provisioning seam and stub),
any channel handler, any Backoffice UI beyond what Doc 02 itself specifies. Where Doc 02 defines
a contract Doc 03 or Doc 07 consumes, build the contract and stub the consumer.

**Pre-agreed closure buckets (build to these; do not invent new deferrals beside them):**
- **AC-05 / FR-18 / T-05 (token scope ∩ live permissions):** the intersection engine, the 10 s
  per-token cache, and the 403 response naming the missing permission are YOURS and must be DONE
  with tests against the permission keys already registered under D-2. The real
  effective-permissions resolver is Doc 03's: define the resolver interface, ship a stub, prove
  the intersection against the stub. Any plan row that builds role logic is scope creep.
- **FR-23 / FR-24 / FR-25 (webhook auth):** you own the auth mechanics — Telegram secret-header
  check, SMS HMAC + ≤5 min replay window via timestamp/nonce cache, CSRF exemption,
  never-establishes-a-session guarantee — proven against the existing ingress probes. The
  probes' audit-exemption lines are deleted by Docs 05/14, not by you.
- **AC-09 (visual regression en+am, light+dark):** PARTIAL by design — ship fully functional,
  themed, i18n-keyed Fortify views now; pixel-regression baselines attach when the approved
  Claude Design screens land. Same treatment as Doc 01's design-console bucket.
- **NFR-01 / NFR-02 / NFR-03 (perf budgets):** structure for them (argon2id tuned ~100 ms,
  Redis session driver, O(1) counters) but formal budget closure is Doc 19's L1 profile, per the
  established pattern. Record them PARTIAL bucketed to Doc 19.
- **T-08 (GeoIP):** in-process MMDB lookups against a small checked-in stub .mmdb in tests —
  never the network, never a download in CI. The real GeoLite2 refresh job ships now but is
  proven against the stub; I will supply the MaxMind key for staging when asked.
- **FR-16 / FS-02 (auth email):** dev/staging mail driver is in-stack (Mailpit or log driver);
  real per-tenant sender config (SPF/DKIM/DMARC) is Doc 18's. Queue + ×6 backoff + admin-visible
  queue depth are yours.

## INHERITED FROM DOC 01 (each is a plan-table row, not a footnote)
1. Remove `deferAuthenticationRoutesToDoc02()` in `AppServiceProvider`; every Fortify route you
   ship gets audit-coverage entries and permission-registry mappings — the gates flip blocking
   and will catch omissions.
2. The **pre-tenant email lookup**: authenticating by email happens before a tenant is resolved,
   so it cannot use the global scope — solve it the `resolve_tenant_key()` way (a narrowly-scoped
   lookup that hands the caller nothing general), NEVER by removing or bypassing the scope.
3. **Doc 01's EC-03 completion** (NOT this document's EC-03 — Doc 02 has its own, unrelated
   EC-03 about reset links on a different device): tenant suspension must invalidate live
   sessions, not just refuse the next request. In Doc 02 terms this lands across FR-05, EC-05,
   T-04, and the suspended-tenant matrix surface. That mechanics is yours.
4. Doc 02 owns the users-table SHAPE from here (2FA columns, lockout state) — expand-contract
   migrations only; Doc 01 corrected the scaffolding conventions already (UUIDv7, timestamptz).
5. Set the authenticated user's locale per request — the C-08 chain's first hop.
6. New tenant-FK migrations need the `set_config('app.tenant_id', …, true)` preamble (see the
   cold-summaries migration for why); every new tenant table gets `REVOKE DELETE FROM app`.

## THE SUBTLE PARTS (spell each out in your plan)
- **No impersonation feature exists.** Not behind a flag, not for support, not for platform
  admins. Doc 02's SEC-07 states this as a security stance. If anything adjacent appears
  necessary, surface it and wait.
- **Enumeration and timing are tested, not assumed**: login, forgot-password, invite, and 2FA
  flows must be indistinguishable for existing vs non-existing accounts — byte-identical bodies
  AND audited timing bounds (±50 ms, AC-01). Write the tests that prove both dimensions.
- **Sessions are tenant-scoped state**: fixation (SEC-04 — session id regenerated at BOTH
  privilege boundaries: post-password and post-2FA), concurrent-session cap with oldest-eviction
  and socket close ≤ 2 s (AC-04), and revocation all respect tenancy; a session may never
  survive its tenant's suspension or its user's credential change (FR-14).
- **Lockout is a state machine, not a cache counter** (FR-03, AC-02): audited transitions,
  per-account rolling window, independent per-IP throttle, admin unlock audited, reset unlocks —
  and the EC-11 DoS containment (victim can always self-unlock via reset; per-IP throttle caps
  the attacker; `auth.captcha.after_failures` setting exists even if no vendor is wired).
- **BR-04 is atomic**: disabling a user revokes all sessions AND all tokens in one transaction —
  prove the all-or-nothing property, not just the happy path.
- **TOTP 2FA per CLAUDE.md §3 (Fortify)**: enrolment with single-secret-wins on concurrent tabs
  (EC-10), ±1-step tolerance and ±2 rejection (AC-03), 5-failure void, recovery codes hashed
  argon2id at rest and shown exactly once (SEC-03), lockout interplay — DB facts with grant
  physics where applicable, not middleware opinions.
- **Guard partitioning is structural** (T-02, BR-06): staff / api / widget are separate guards,
  route groups, and cookie scopes; the widget guard grants zero staff access regardless of any
  scope misconfiguration — prove it structurally, not by scope checks.
- **Rate limiting before expensive work** (SEC-05 pattern): wire the existing QuotaMeter/limiter
  conventions at the auth endpoints, before password hashing runs.
- **Every credential secret is hashed at rest; nothing secret ever serializes** into jobs, logs,
  events, or audit payloads (SEC-08, AC-08) — extend `SecretsNeverSerializeTest` to reset
  tokens, invite tokens, TOTP secrets, and recovery codes, and prove the CI log-scrubber catches
  seeded fakes.

## PROTOCOL (unchanged, in full)
1. **PLAN FIRST**: a table of every Doc 02 id → file(s) → proving test, plus rows for the six
   inherited items. State the id count you found. Sequence safety tests BEFORE the features they
   constrain. WAIT for my approval.
2. One commit per numbered step; registries updated as you go (permissions, settings,
   audit-coverage, features if any, traceability rows — the per-document flip makes Doc 02's ACs
   blocking the moment its BUILD-STATUS row leaves NOT STARTED, ADR 0004).
3. **Falsify every safety mechanism you build**: disable the lockout → watch the brute-force test
   fail; make login responses distinguishable → watch the enumeration test fail; leave a session
   alive through tenant suspension → watch the inherited Doc-01-EC-03 test fail; let a revoked
   session's socket stay open → watch AC-04's test fail. Paste fail AND restored-pass, every
   time. Backup before sabotage; nothing commits until `git status`/`git diff` proves the tree
   is clean of falsification damage.
4. Every user-visible string is an i18n key with `en` AND `am` entries; `string-lint` stays
   green.
5. `make test`, `make gates`, `make lint` — pasted real output. Then push, watch CI to
   completion, and record the run ID in BUILD-STATUS.
6. Finish with the full Doc 02 self-audit table (every id, DONE / PARTIAL / NOT DONE + one-line
   reason; a false DONE is a Sev-1) and the CLAUDE.md §9 report:
   BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION. Then STOP.
