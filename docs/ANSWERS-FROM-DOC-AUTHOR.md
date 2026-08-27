# ANSWERS FROM THE DOCUMENT AUTHOR — owner-ruling annotations

Questions that Docs 01–19 do not answer, resolved by the owner (acting with the document author)
and recorded here so the answer is durable rather than living in a chat transcript.

**Status of this file.** It is an ANNOTATION LAYER over the 19 documents, not a replacement for
them. Where a ruling here extends or narrows a document, it says which id it touches, and the
document text itself is left untouched — so a future reader always sees the original requirement
and the ruling that qualifies it, in that order.

`docs/CONFLICTS.md` records that the 19-document series **permanently supersedes** the never-
supplied master design document (owner ruling, 2026-08-01). This file is therefore the only
sanctioned route for a binding decision that is not already in Docs 01–19.

---

## 2026-07-31 — The document author's Q1–Q9 responses (merged verbatim 2026-08-04, S19, by owner order)

The author's response document to `QUESTIONS-FOR-DOC-AUTHOR.md`, delivered from the owner's
machine and merged here unaltered so the interpretation record is one file. Its own header:
_"Author: the specification-series author · 2026-07-31 · Format: confirm/conflict per
question, citations included."_

**Q1 — Master document status: (a) CONFIRMED — the 19-doc series fully supersedes the master document. Close the gap permanently.**
The master document was the earlier, shallower pass; every binding decision it contained was carried into, refined by, or deliberately changed by the series. Phase 22 (schema) contains **no deltas** that survive contact with Doc 01's conventions — the built schema (UUIDv7, timestamptz, RLS'd partitions, the four-role grant model) is the law and must not be re-litigated. Phase 23 (endpoint catalog) likewise contains no contract the series does not already state where it matters (e.g., `409 reply_window_expired` is Doc 01 T-07's own example; template-only `POST /v1/sms/send` is Doc 14 FR-02/BR-01; the webhook-out event list is Doc 01/Doc 15/Doc 17). If the master doc ever enters the repo, mark it `reference/non-binding — superseded by Docs 01–19`. CONFLICTS.md's ruling stands and becomes permanent.

**Q2 — Tenant selection at staff login: CONFIRMED, with two required refinements.**
Your `resolve_staff_login(p_email)` design is the intended shape — Doc 02 was genuinely silent, and host-based resolution is indeed not v1 (Doc 16 §19). Refinements: (1) the >1-tenant-verifies refusal must use the *same generic error* as bad credentials (Doc 02 FR-02's non-enumeration bar) but log a distinct reason category `ambiguous_tenant` in the FR-04 failure event so it's diagnosable; (2) the refusal path's documented escape hatch is exactly your Q3 ruling (reset in one tenant breaks the collision) — put that sentence in the runbook/help copy, never in the error message. Timing equalization (FR-02 ±50 ms) must hold across 0/1/N-candidate paths — add that case to the AC-01 timing test.

**Q3 — Cross-tenant reset & lockout: CONFIRMED as ruled.**
One token per matching account, each bound user+tenant, uniform response (FR-16's "if the account exists…" survives intact). Lockout counting against every candidate account is the correct conservative reading of FR-03; the cross-tenant amplification of EC-11's lockout-DoS is acceptable because (a) the per-IP throttle remains the primary anti-DoS control and (b) EC-11's self-unlock-via-reset holds per account, which is also Q2's escape hatch. No change.

**Q4 — Bearer tokens under FORCE RLS: CONFIRMED never-exempt; preference = lookup-prefix tenant routing.**
Both mechanisms are acceptable, but prefer the **TenantKey lookup-prefix convention**: Doc 01 SEC-03 already mandates a lookup prefix on tenant keys, and Doc 01 FR-03's principle is that *every* surface resolves tenant from server-controlled context before any tenant-table read. Encoding the tenant key in the token's public prefix → `resolve_tenant_key()` → bind TenantContext → then the `personal_access_tokens` row is read under ordinary RLS. This keeps the SECURITY DEFINER surface at its current size (one function stays one function — `resolve_staff_login` from Q2 is the sanctioned second; don't grow a third for this) and makes API auth follow the same resolution order as webhooks and widget keys. Constant-time behavior across unknown-prefix vs unknown-token paths, per the Doc 02 SEC posture.

**Q5 — SEC-06 plain-HTTP refusal: CONFIRMED literal.**
"Any environment" means any environment — the wording was deliberate, and your implementation (FrankenPHP local TLS, refusal middleware with no env exemption, TLS'd CI) is exactly Doc 18 BR-03's parity rule applied to auth. Keep it.

**Q6 — Recovery codes: CONFIRMED — SEC-03 wins, replacement store is the intended reading.**
Doc 02 FR-06 ("stored hashed") + SEC-03 ("argon2id; displayed exactly once") knowingly contradict stock Fortify's retrievable storage; the intended design was always "Fortify drives the flows (T-01), our store owns persistence." Your `used_at` consumption + no-reversible-column-by-grant-physics is the right realization. Ensure the "2 codes remaining → regenerate" banner (FR-07) reads counts from the hashed store's unconsumed rows.

**Q7 — Auth event path & payload split: CONFIRMED, both halves.**
(1) Path: Doc 17 FR-02 makes auth the one synchronous class, sharing insert code/schema with the future consumer — build it now exactly as ruled. (2) Payloads: bus events carry identifiers and reason categories only; IP/UA/fingerprint live in the audit entry and the Device record. That is the intended reading of Doc 02 FR-04's "record" — it names *what is captured*, and Doc 17 FR-01/FR-04 + the catalog's closed-payload rule name *where it lives*. A 13-month event stream must never accumulate IP/UA history; the audit table (with its own retention, masking, and access gates) is the only home for it.

**Q8 — AC-09 timing vs design Batch 7: CONFIRMED as ruled.**
AC-09's pixel baselines are defined against *approved* designs by construction, so the AC is structurally PARTIAL until Batch 7 exists — that is the series' intent, not a deviation. Ship functional, themed, i18n-keyed auth screens now; attach baselines at design approval. Do not pull auth forward in the batch order for this reason alone; the only legitimate reason to re-order batches is operator-facing demo needs, which is an owner call, not a spec requirement.

**Q9 — Permission count: both numbers are approximations of the same thing; the enumerated catalog governs.**
The discrepancy is *inside Doc 03 itself*: its Executive Summary says "~130" (an estimate including reserved namespaces) while §4.1's closing line says "118 v1 keys + reserved namespaces" — the enumerated catalog. Ruling: the **code registry is the only true count**, seeded from Doc 03 §4.1's 118 keys plus the additions the series registered through its own change-log mechanism (at minimum: `views.share` [Doc 07 FR-06], `ai.providers.manage` [Doc 09 FR-02]; Doc 09 T-04/Doc 10 also register the `embed` task in the AI task catalog, which is not a permission). PROJECT-SUMMARY's "118" is the pre-additions figure; treat prose counts everywhere as descriptive, the registry as normative — which is exactly what your seven-registry CI gate already enforces.

_Author's closing note: "Nothing above is invented beyond the documents; where the docs were silent (Q2, Q3, Q4's choice, Q8's ordering) I have confirmed your rulings as the intended design and they are now part of the series' interpretation record. Please file this document alongside CONFLICTS.md."_

---

## 2026-08-01 — Doc 02 ruling reconciliation (Session 8)

Four rulings, filed after reviewing what Doc 02's implementation had actually built against what
the document author would have preferred.

### R-1. The SECURITY DEFINER surface: ACCEPTED AS BUILT (recorded deviation)

**Question.** Doc 01 and Doc 02 both require reads that happen *before* a tenant is known, which
`FORCE ROW LEVEL SECURITY` otherwise forbids. The build answered with four `SECURITY DEFINER`
functions, all owned by the NOLOGIN `key_resolver` role:

| Function | Purpose | Spec basis |
|---|---|---|
| `resolve_tenant_key` | channel / API key → tenant | Doc 01 FR-03 |
| `resolve_staff_login` | staff email → candidate accounts | Doc 02 FR-01, Doc 01's handover constraint |
| `resolve_api_token` | bearer token → tenant + owner | Doc 02 FR-18/FR-19 |
| `resolve_auth_action_token` | reset / invite token → tenant + user | Doc 02 FR-16/FR-17 |

The document author's preference had been **two** definer functions, with bearer tokens routed by
the `TenantKey` lookup-prefix convention instead of getting their own door.

**Ruling: accepted as built.** No document is violated; all four are `key_resolver`-owned, each
answers exactly one narrow question, and `tests/Isolation/PreTenantLookupTest.php` proves for each
that it resolves pre-tenant, that a direct read of the same table still fails, that using it grants
no lasting cross-tenant visibility, that it matches exactly (no enumeration), and that it returns
no secret beyond what verification requires. The properties the preference was protecting are held
by the built design.

**The ceiling is now four.** A fifth `SECURITY DEFINER` function requires an owner ruling **and**
an ADR, every time, with no exceptions. The reason is that this surface is the one place in the
platform where RLS is deliberately bypassed; it stays small enough to audit in one sitting, or it
stops being auditable at all. `PreTenantLookupTest` asserts the owner and login-ability of every
definer function it knows about — a fifth arriving without a ruling should be caught there.

### R-2. FR-04 gains a reason category: `ambiguous_tenant`

**Extends** Doc 02 FR-04, whose listed categories are `bad_credentials`, `locked`,
`suspended_tenant`, `disabled_user`, `ip_blocked`, `2fa_failed`.

When one email **and one password** verify against accounts in more than one tenant, the login is
refused (the platform cannot know which workspace was meant, and choosing would silently sign
someone into the wrong one). That refusal previously logged `bad_credentials`, which is untrue and
makes the case invisible in exactly the reports that would reveal it.

**Ruling.** The refusal keeps the **generic** user-facing error — FR-02 and AC-01 are unchanged and
non-negotiable — but the failure **event and audit entry** carry `reason: ambiguous_tenant`.

**The escape-hatch explanation belongs in help and runbook copy only, never in the error message.**
(The way out of a collision is for the person to reset their password in one of the tenants, which
breaks the tie.) Putting that in the error would tell an attacker that the address exists in more
than one workspace — the exact inference AC-01 exists to prevent.

### R-3. AC-01's timing assertion covers all three candidate counts

**Narrows** the AC-01 test surface. The provider-level equalisation measurement must assert the
±50 ms bound explicitly across the **0-candidate**, **1-candidate**, and **N-candidate** paths,
rather than 0-vs-1 only.

This closes the residual the S4 session recorded and left open: an address existing in N tenants
cost N password verifications, so "this address is in more than one workspace" was
timing-distinguishable to an attacker who already knew the address existed. Under this ruling that
is no longer an accepted residual — it is a tested bound.

### R-4. FR-07's "2 codes remaining" banner reads the store, never a counter

**Clarifies** Doc 02 FR-07 ("at 2 codes remaining, banner prompts regeneration").

The remaining-code count is computed from the hashed recovery-code store — rows where
`used_at IS NULL` — and never from a cached or denormalised counter.

A counter can drift from the store, and the direction it drifts is the dangerous one: a stale
count says "8 remaining" while the account has one code left, so the prompt that would have told
the user to regenerate never appears, and they discover it when they are locked out. The store is
small, per-user, and indexed for exactly this query (`user_recovery_codes_live_index`), so there is
no performance argument for caching it.

---

## 2026-08-02 — Doc 02 step 8 rulings (Session 12, password lifecycle)

Filed at plan approval, before implementation.

### R-5. Auth email rides a dedicated `mail` queue family (ADR 0007)

**Extends** CLAUDE.md §3's Horizon supervisor list, by ruling and ADR — the only sanctioned way
the constitution changes. Rationale recorded in ADR 0007: reset email is the account-recovery
path (ruling Q1's escape hatch, EC-11's self-unlock) and must never queue behind bulk work;
FS-02's admin-visible depth requires a queue that contains nothing but mail. The family is named
`mail` generically so future notification email rides it; Doc 02 owns the family's existence.

### R-6. Reset-unlock is per account, never per address

**Clarifies** Doc 02 FR-03 ("unlock also possible by … completed password reset") against the
multi-candidate lockout rule (S5). A failed attempt locks EVERY account matching the address —
but a completed reset unlocks EXACTLY the account the token was bound to. Other candidate
accounts on the same address stay locked, and the attacker's per-IP throttle is unaffected.
Unlocking siblings would let one tenant's reset silently unlock an account in another tenant —
an unlock the other tenant's audit trail could not explain. Asserted in PasswordResetFlowTest.

### R-7. The multi-tenant escape-hatch explanation never appears in a response body

**Re-affirms and extends R-2's final paragraph to the reset flow's own screens.** The reset
request response and the token-error screens carry only generic copy ("if the account exists…",
"this link has expired — request a new one"). The explanation that resetting a password in one
tenant breaks a cross-tenant password collision lives in runbook/help content only. The
enumeration suite asserts the reset responses byte-identical across 0-, 1- and N-candidate
addresses, which structurally forbids any such copy from creeping in.

### R-8. T-01 deviation for the reset flow: Fortify substrate, custom token persistence

**Records a deviation** from Doc 02 T-01 ("Fortify drives login/reset/2FA"), same shape as S11's
2FA deviation. Fortify's reset flow persists tokens in the stock `password_reset_tokens` table,
which is keyed by EMAIL as its primary key — one row per address. That cannot express this
platform: emails are unique per TENANT, one address may match accounts in several tenants, and
the owner ruling on ambiguity requires one token PER MATCHING ACCOUNT. The flow therefore runs on
`auth_action_tokens` (SEC-02 as schema physics, one live token per user+purpose) behind the
`resolve_auth_action_token` door, while Fortify remains the substrate it already is for login.
The dead stock `password_reset_tokens` table is dropped in a later **contract** release per C-12,
not in this step.

## 2026-08-03 — Doc 02 step 11 ruling (Session 15, webhook authentication)

### A-1. FS-01 vs FR-24: the replay cache fails CLOSED (FILED INTERPRETATION)

**Resolves an internal tension in Doc 02, by owner ruling at step-11 plan approval; the doc
author has NOT been consulted, and this record exists so the interpretation cannot silently
become "how it has always worked."**

FS-01 says "Redis down → staff auth fail-closed (maintenance screen); **webhooks unaffected (no
sessions)**" — a sentence that states its own rationale: webhooks carry no session dependency.
FR-24 then mandates a replay window "≤ 5 min via timestamp/nonce cache", which is a Redis
dependency FS-01's sentence predates. The two cannot both be read literally during a volatile
outage: honoring "unaffected" would accept traffic whose replay status is unverifiable,
silently unbounding the replay window exactly while the platform is degraded.

**Ruling: bounded replay beats degraded-mode acceptance.** The `body_hmac` surface refuses
(403, `webhook.replay_cache_unavailable` metric) when the nonce cache is unreachable; real
gateways' retry semantics carry availability. FS-01 stays true where its rationale applies:
the `secret_header` surface touches no Redis and is PROVEN to keep answering 202 against the
same dead instance (WebhookHmacTest's FS case drives both surfaces at one broken port). The
per-key rate limiter — capacity protection, not authentication — fails OPEN with its own
metric for the same reason in reverse.

**Doc 14 revisits with the doc author only if a real gateway's retry semantics conflict**
(e.g., a gateway that treats 403 as permanent and stops retrying DLRs). Until then this
interpretation stands.

## 2026-08-03 — Doc 02 step 13 rulings (Session 17, authentication screens)

### A-2. EC-02's "401" vs Doc 01 C-10's 404: the sentinel confirms before it modals (FILED INTERPRETATION)

**Resolves a cross-document tension at step-13 implementation; the sentinel's scope itself was
owner-ruled at plan approval, and this records the mechanism.**

Doc 02 EC-02 says "session revoked in another tab → **next request 401** → re-login modal
preserving in-page state." But Doc 01 C-10/SEC-06 make failed staff resolution answer **404,
indistinguishable from a missing resource, on purpose** — and the staff API honours that
(empirically: a dead session's `/api/staff/*` request 404s; S7 recorded the same shape). Read
literally, EC-02's trigger never fires; triggering the modal on any 404 would pop a re-login
dialog on every genuinely missing resource.

**Interpretation: the modal's TRIGGER is client-side confirmation, not a status code.** On a
401 or 404 from a staff-surface fetch, the sentinel probes `auth.session.deadline` — a route
that always exists for an authenticated caller — with the unwrapped fetch. Probe 200 → the
original response was a genuine answer and passes through untouched. Probe non-200 → the
session is dead; the modal opens, and the original request replays after re-login. C-10 is not
weakened: the server's responses are unchanged, and the probe reveals only the caller's OWN
session state — a fact the caller already holds. The owner-ruled scope stands: staff Inertia
surface only, never the widget, never the bearer API (FR-20's contract belongs to
integrations, not modals).

## 2026-08-04 — Doc 03 plan-approval rulings (Session 19)

Filed at plan approval, before implementation. The owner's approval message numbered these
R-1…R-6; they are filed here as R-9…R-14 to continue this record's numbering, with the
owner's labels noted.

### R-9 (owner R-1). Four-eyes "approval rights" need no new key; the catalog stays closed at 111

**Resolves the B6-S10 design-vs-document conflict.** The drawn approver surface is gated by an
`admin.roles.approve` chip, but Doc 03 §4.1's closed catalog (BR-03) defines no such key.
Ruling: **option (a)** — approval rights = holding `admin.roles.manage` (for role/permission
grants) or `admin.overrides.manage` (for override grants), with FR-08b's "second admin"
enforced by the distinct-person rule; EC-08's deadlock guard requires ≥ 2 such holders before
`rbac.four_eyes` may be enabled. The drawn `admin.roles.approve` label is filed as a
**class-3 design defect** (wrong key name on the gate; the surface itself is unchanged) in
BUILD-STATUS's DESIGN-DEVIATIONS section.

### R-10 (owner R-2). Appendix B ratified

The nine-template grant matrix proposed in the S19 plan is ratified as proposed, including:
agent-tier templates carry **zero high-risk keys** (the B6-S07 fixture's "2 high-risk" badge
is operator-edited content, not template law); **Read Only = exactly the 18 `.view`-suffix
non-high keys** — `kb.view_internal` excluded (BR-05 read strictly: the action segment is not
`view`), `audit.view` excluded (a high-risk key never rides a seeded convenience template).
Committed as `docs/Doc03-AppendixB-role-templates.md` + the seeder fixtures.

### R-11 (owner R-3). Three filed interpretations

1. **Role deletion ships as soft-delete** (`deleted_at`) under ADR 0003's DELETE-revoked
   grant physics (Doc 01 BR-02: nothing hard-deletes at tenant scope); FR-08c's semantics are
   fully served and `rbac.role.deleted` is still emitted.
2. **Capability cache key is `t:{tenant}:cap:{user}`** per convention C-07; Doc 03 T-02's
   literal `cap:{tenant}:{user}` is read as descriptive.
3. **`rbac.roles.soft_limit` registers platform-only** (`tenant_editable: false`).

### R-12 (owner R-4). Counts: 111 keys, 38 high — text-derived, on the record

§4.1 enumerates **111** keys and marks **38** of them `(high)`; both counts are taken from the
document text (twice-counted, grep-confirmed). The session prompt's "20 high" is corrected on
the record. Q9's principle (registry normative, prose descriptive) governs the seeding rule
already filed: the 111 enumerated keys seed **verbatim and nothing else** — Q9's own "118"
citation of §4.1's closing line is one of the descriptive prose counts; the seven keys the
closing line implies were never enumerated and enter only by owner ruling if they ever arrive.
`views.share` (Doc 07) and `ai.providers.manage` (Doc 09) register when their documents land.
Related filed mapping: Doc 02 AC-05's literal `tickets.read` ≙ catalog `tickets.view` — the
catalog governs (BR-03, NFR-03); no `tickets.read` key exists or will be invented, and Doc 06
builds that fixture with catalog keys under this filed interpretation.

### R-13 (owner R-5). The B6-S10 "recent decisions" table stays excluded

It is drawn as an explicit OPTION frame ("OPTION, not in spec") and no spec id requires it.
Recorded in DESIGN-DEVIATIONS as the frame's own marking, not a deviation.

### R-14 (owner R-6). Amharic destructive-tier review rider

At session close-out, every new destructive-tier Amharic string (role delete confirmation,
override deny confirmation, four-eyes reject) is flagged for the standing native-review queue.

### A-3. Doc 02 FR-09's grace anchor: first subject encounter (FILED INTERPRETATION, S19 — mirrored at close-out acceptance)

**Resolves a silence in Doc 02 FR-09, filed at S19 step 7 in the migration's docblock
(`2026_08_04_020000_add_two_factor_required_since_to_users.php` — the docblock stays;
this record is canonical); the doc author has NOT been consulted.**

FR-09 says "grace period auth.2fa.grace_days (default 3) with countdown banner" but names
no per-user starting point. Interpretation: the anchor is when enforcement FIRST APPLIED
to the user — `two_factor_required_since`, stamped by the enforcement middleware on first
subject encounter — because it is the only moment that exists for every path into scope
(role assigned later, role added to the setting later, user invited later) and it can
never predate the user's ability to comply. The stamp is a guarded fill-only UPDATE in DB
time (it can never RESET anyone's grace window), the column is nullable expand-contract
(C-12), and the days-left comparison runs in SQL against now() (C-11).

## 2026-08-04 — Doc 04 plan-approval rulings (Session 20)

Filed at plan approval, before implementation, by owner order. R-15…R-17 are owner rulings;
A-4/A-5 are filed interpretations (the doc author has NOT been consulted). This record is
canonical over any restatement in commits or BUILD-STATUS.

### R-15. Doc 04 stack growth approved

ADR 0008 (egress-proxy compose service; `league/flysystem-aws-s3-v3`; `intervention/image`
on the GD driver; lane workers as a worker-container process per ADR 0007's precedent; a
hand-rolled clamd INSTREAM client instead of a client dependency) and ADR 0009
(`dompdf/dompdf` with embedded Noto Sans Ethiopic for FR-24's Amharic transcripts) are
approved. Each ADR is written BEFORE the code it covers; compose/CI commits fall under the
efficiency ruling's infra exception.

### R-16. SS-02 is claimed via the second fake adapter

The zero-core-edits architecture property (Doc 04 SS-02) is proven THIS session by a second
minimal fake adapter with a different capability profile driven through the conformance
suite, with the zero-core-edits diff pasted. Its traceability row carries verbatim:
"architecture property proven via a second fake adapter; the literal WhatsApp demonstration
re-proves if such an adapter is ever built (§19)". Never a bare DONE.

### R-17. FS-05's ops re-drive view ships as a class-2 gap fill

Doc 04 FS-05's "ops UI lists parked items with re-drive" ships this session under
DESIGN-FIDELITY-MANDATE class 2 (required functionality no frame draws): existing
design-system patterns only (data table, confirmation component), tokens from the B1-DS
lineage (v2 §2 + all three addenda), filed in BUILD-STATUS's DESIGN-DEVIATIONS. This amends
the session's scoping row to: "no drawn-FILE surface built; exactly ONE class-2 fill
(FS-05 ops view), filed." Everything else stays harness-only, never styled as product UI.

### A-4. Names for events Doc 04 requires but does not name (FILED INTERPRETATION)

FR-17's mention event = `note.mentioned` · FR-15's lane-skip warning =
`conversation.lane_skipped` · FS-01's channel health events = `channel.health.changed`.
All three: closed payloads, identifiers only (Q7's posture), audit-coverage entries,
catalog regenerated. FR-26's enumerated list is otherwise untouched.

### A-5. Names for the FR-16 rate-bucket settings (FILED INTERPRETATION)

FR-16 requires token-bucket settings but names no keys. Registered under `messaging.*`
with platform validation, `tenant_editable: false` until Doc 16 rules otherwise:

- `messaging.rate.outbound_per_second` — int, default 25, validation 1..100. Steady-state
  token refill per channel endpoint.
- `messaging.rate.outbound_burst` — int, default 50, validation 1..200. Bucket capacity
  (NFR-02's 50 msg/s burst-absorption figure is the default's source).

Adapter capability remains the harder cap where lower; over-limit messages WAIT in
`queued`, never dropped (FR-16's own text).

### A-6. Permissions for the FS-05 ops re-drive view (FILED INTERPRETATION, S20 step 9b)

FS-05 mandates "ops UI lists parked items with re-drive" but names no permission, and
the catalog is closed (NFR-03; the plan's inherited row 7 confines every new route to
the seven seeded keys). Filed mapping, from the document's own precedents:

- **List** (`GET /conversations/dead-letters`) — `conversations.view`. A parked payload
  is inbound conversation content that has not yet persisted; seeing it is seeing
  conversation content.
- **Re-drive** (`POST …/redrive`) — `tickets.reply`. FR-13 gates the manual retry of a
  failed(final) OUTBOUND with `tickets.reply`; the re-drive is the same operator
  decision applied to the inbound side. No key invented.

The doc author has NOT been consulted; if overruled, only the two route middleware
lines and the PageCapabilities manifest entry change.

Owner-ratified at close-out acceptance; stands unless the doc author overrules, in which
case only the two middleware lines + the manifest entry change.

## 2026-08-06 — Doc 04 close-out acceptance (Session 20)

### R-18. Doc 04 accepted with AC-03 PARTIAL; the soak is the next session's step 0

Doc 04 accepted with AC-03 PARTIAL. Root-causing the AC-03 soak (deterministic result +
`make test`/CI reconciled, most likely a dedicated CI job) and the RISK 0b capture
protocol are STEP 0 of the next build session; no Doc 06 plan may be approved before the
soak is deterministic. A flaky test is not a proof, and an ordering guarantee that is
only probably true is not a guarantee.

## 2026-08-07 — Doc 04 AC-03 ordering-key ruling (Session 21, step 0 continuation)

### R-19. FR-07's ordering key versus C-11 — C-11 governs; `created_at` moves to DB time

**R-19 — OWNER RULING: FR-07's ordering key versus C-11. C-11 governs; `created_at`
moves to database time.** 2026-08-07.

Doc 04 FR-07 defines thread display order as `(created_at, id)`. Doc 01 C-11 requires
ordering decisions to use DB time or UUIDv7 monotonicity and never an app-server wall
clock. The implementation wrote `created_at` from Eloquent's `freshTimestamp()` — an
app-server wall clock truncated to whole seconds — so FR-07's key violated C-11. Proven,
not argued: `ThreadOrderingKeyProbeTest` (negative, positive and threshold controls) and
`LaneOrderingForcedBackstepTest`, which reproduced run `soak-10`'s exact shape in the
soak's real geometry with counts predicted before the run (transient excursion -> 3 lanes;
persistent -> 50).

RULING. (1) The ORDER expression is unchanged: FR-07 remains `(created_at, id)` and
T-08's cursor remains `(created_at, id)`. What changes is the SOURCE of the leading
component. (2) For every column that participates in an ordering key, a cursor, or a
reported metric, `created_at` is supplied by PostgreSQL via `clock_timestamp()` — NOT by
the application, and NOT by `now()`/`CURRENT_TIMESTAMP`, which return transaction-start
time and would give every row in one transaction an identical stamp. (3) Full timestamptz
precision is retained; whole-second truncation ends. (4) `id` remains UUIDv7 and remains
the tiebreak within the same microsecond. (5) Historical rows are NOT backfilled; they
keep their truncated values, and any inversion already present in them stands as history.
There is no production data.

C-11 IS NARROWED IN THE SAME RULING. C-11 offers "DB time or UUIDv7 monotonicity" as
alternatives. UUIDv7's leading 48 bits are Unix milliseconds generated by the APPLICATION,
so under multi-server Octane the UUIDv7 route inherits exactly the skew C-11 exists to
prevent, merely at millisecond rather than whole-second granularity. UUIDv7 monotonicity
is therefore sufficient only as a tiebreak WITHIN a DB-time stamp, never as a primary
ordering source across servers. A future session reading C-11 must not pick that half.

SCOPE. Doc 04's message ordering path now; every Doc 06 FR-24 metric timestamp
(`first_response_at`, `agent_first_response_at`, `resolved_at`, `closed_at`, and the
waiting-seconds accumulators) at construction, so Doc 06 does not replicate the defect and
Doc 15's shared golden fixtures are not pinned to an app clock. Non-ordering, non-metric
timestamps are unchanged and reviewed at Doc 18/19.

ARCHITECTURAL CHANGE — requires an ADR (see ADR 0010).

### R-19a (addendum to R-19). The app must be STRUCTURALLY unable to supply `created_at`

**ADVISOR RULING, 2026-08-07.** A column `DEFAULT` is overridable, so R-19's guarantee
rests on developer discipline unless it is enforced. CLAUDE.md §1 requires physics over
discipline. The application must be made structurally unable to supply
`messages.created_at`.

Preferred mechanism: a column-level grant — `REVOKE INSERT (created_at) ON messages FROM
app` — the ADR 0003 pattern applied one level finer. A role without column-level INSERT
cannot name the column; the default still fills it. Fall back to a blocking CI grep gate
registered in `registries/gates.yaml` only if the fixtures that must keep writing
historical rows cannot survive the grant without contortion.

**MECHANISM TAKEN: the grant.** Verified before choosing, not assumed:

- `RetentionTest` and `MessagePartitionTest` — the two suites whose whole purpose is
  writing historical rows — already backdate through
  `DB::connection('pgsql_platform')->table('messages')->insert([... 'created_at' => ...])`.
  `platform_admin` keeps table-level INSERT, so **their capability is untouched**.
- Only `ConversationSchemaTest` backdated on the **app** connection (three sites,
  including the FR-07 same-second-burst cursor test). Those three fixture writes moved to
  `pgsql_platform`, which is alignment with the precedent the other two suites already
  set, not a contortion: writing a synthetic or historical timestamp is a platform-role
  capability, and what those tests actually assert (CHECK constraints and cursor
  ordering) is unchanged.
- Postgres has no "revoke one column" for a role holding table-level INSERT, so the
  grant is expressed as `REVOKE INSERT ON messages FROM app` followed by
  `GRANT INSERT (<every column except created_at>) ON messages TO app` — exactly the
  column-list form already used for `tenant_keys` in the RLS migration.
- All writes go through the parent partitioned table (verified: no code inserts into a
  monthly partition directly), so the parent's grant is the one Postgres checks.

Two pre-coding checks required by this ruling, both clean: **no `->latest()` or
`->oldest()` exists anywhere in `app/` or `tests/`**, so a null `CREATED_AT` cannot
silently break their column derivation; and **nothing inserts into a monthly partition
directly**, so both the default and the guard hold.

### R-19b (addendum to R-19). New tables inherit the DB-time rule from birth

**ADVISOR RULING, 2026-08-07.** Everything R-19 says about the SOURCE of an ordering- or
metric-bearing timestamp applies to every table created from here on, not only to
messages. Concretely, for Doc 06 and every document after it: any new table whose
created_at (or any other timestamp) participates in an ordering key, a cursor, or a
reported metric ships in its FIRST migration with the clock_timestamp() default AND
the R-19a column grant (INSERT and UPDATE both revoked on that column for app). Not
retrofitted later.

This explicitly includes Doc 06's tickets table and its companions — Doc 06 T-08 puts
list reads through Doc 07's read models, so those columns are ordered by construction
— and Doc 06's FR-24 metric timestamps, which ADR 0010 already names.

The schema-derived guard generalises with it: each such table gets the same assertion,
reading information_schema.columns and proving app's denied set for both INSERT and
UPDATE is exactly the DB-time columns. Same shape, per table.

## 2026-08-07 — Doc 06 plan-approval rulings (Session 22)

Filed at plan approval, before implementation, by owner order (advisor-audited plan).
R-20…R-22 are owner rulings; A-7…A-9 are filed interpretations approved as proposed
(the doc author has NOT been consulted). This record is canonical over any restatement
in commits or BUILD-STATUS.

### R-20. The FR-24 metric-timestamp trigger is approved in principle, with a condition

The migrator-owned `BEFORE UPDATE` trigger on `tickets` is approved as the only
R-19a-compatible mechanism for MUTABLE metric columns (a default cannot re-stamp; a
granted column cannot be trusted). CONDITION: before step 2's commit, a half-page
trigger design note covering (a) how `first_response_at` and `agent_first_response_at`
stamp when a qualifying public reply lands WITHOUT a status transition (e.g. an AI
reply on an already-open ticket), (b) the waiting-seconds accumulator arithmetic by
OLD.status class, and (c) how the golden fixtures drive the trigger deterministically
in tests — pasted and advisor-confirmed. No step-2 commit before that confirmation.

**NOTE CONFIRMED (advisor-audited, 2026-08-07), filed:** one plain (never
SECURITY DEFINER) migrator-owned plpgsql function `tickets_stamp_transition()`,
`BEFORE UPDATE ON tickets FOR EACH ROW`, writing `NEW.*` only (not privilege-checked,
so the R-19a column grants stay intact; R-1's four-definer ceiling untouched; writes
no `status`, so NFR-03's sole-writer rule is unaffected). (a) App-writable fill-only
signal columns `first_response_message_id` / `agent_first_response_message_id`
(A-7 amendment below): the service writes the message id in the same transaction as
the reply; the trigger stamps the metric columns from the signal message's own
DB-time `created_at` (+ `author_type` for the agent stamp), refusing
cross-conversation and pre-ticket messages. (b) Only when status actually changes:
`interval := clock_timestamp() − OLD.last_status_changed_at`; OLD=pending_customer →
customer accumulator; OLD∈{new,open,pending_internal,escalated} → staff accumulator;
OLD∈{resolved,closed,spam} → nothing; stamps resolved_at/closed_at/spam_marked_at on
entry; reopen clears ONLY resolved_at; spam-unmark clears spam_marked_at;
last_status_changed_at re-stamps. (c) Determinism: DB-value-to-DB-value equality for
first-response stamps; backdated intervals via `pgsql_platform` (the R-19a escape
hatch) with `clock_timestamp()` probe-bracketed tolerance; relational assertions for
fresh stamps; DST dates in the backdated instants; no clock mocking anywhere.

**Two required strengthenings, both binding:** (1) the trigger ALSO refuses (RAISE)
when the signal message is an internal note — FR-24's note exclusion gets the same
in-trigger physics as its pre-ticket exclusion; the service not signalling on notes
is convention, the trigger refusing them is the guarantee. (2)
`last_status_changed_at`'s INSERT default is `clock_timestamp()` and, being
app-unwritable under R-19b, the default is the ONLY legal source — so the first
accumulator interval measures from the row's birth instant; stated in the migration
comment and asserted in the schema guard.

**A-7 AMENDMENT (approved with the note):** the `tickets` field list gains
`first_response_message_id` and `agent_first_response_message_id` — uuid, nullable,
APP-WRITABLE (they are signals/provenance, not timestamps), made FILL-ONLY by the
trigger (an occupied signal column can never be overwritten, so C-09 redeliveries and
later replies cannot move an existing stamp).

**A-7 AMENDMENT 2 (filed at S22 step 3, self-reported):** the field list gains
`idempotency_key` — text, nullable, app-writable, with a PARTIAL unique index on
`(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL`. This one is a
CORRECTION rather than a discovery: FR-13 says "duplicates prevented via idempotency
keys" in as many words, so the field was required by the document all along and A-7's
first pass simply missed it. Partial because FR-12's agent door has no key (a human
deciding once), and NULLs would collide in a plain unique index the moment two
key-less tickets existed. Recorded here rather than slipped into a migration, on the
same principle as the amendment above.

### R-21. All four `tickets.*` settings are `tenant_editable: true`

Including `tickets.resolve.requires_reply` — it is operator policy in US-07's spirit.
The four keys: `tickets.implicit_claim` (bool, default true),
`tickets.resolve.requires_reply` (bool, default true), `tickets.autoclose.hours`
(int, default 48), `tickets.reopen.days` (int, default 7).

### R-22. The tag rename/merge SURFACE defers to Doc 07 with the §8/§9 rendering bucket

It is the filed design gap it already is (`B2-S06-closing-audit-v2`: "Tags —
per-tenant autocomplete; no rename or merge designed"). The T-07 engine builds
headless in this session, fully tested. Before Doc 07 begins the owner chooses
between a scope-limited design-track reopen and a class-2 fill; that choice does not
block this session. Nothing is improvised.

### A-7. FR-01's base ticket field list (FILED INTERPRETATION)

FR-01 cites "Doc 01 Phase-22 schema", which ruling Q1 permanently superseded
(`docs/CONFLICTS.md`); the reference resolves to nothing, so the base row is derived
from Doc 06's own text plus Doc 01/Doc 04 conventions and filed here. The `tickets`
row: `id` (UUIDv7), `tenant_id`, `conversation_id`, `customer_id`, `number`
(per-tenant integer, unique per tenant, from `tenant_sequences`), `subject` +
`subject_source` (ai|staff|null), `status` (the 8 fixed statuses), `priority`
(low|normal|high|urgent), `source` (FR-01's enum), `assignee_id`, `department_id`
(nullable uuid, NO FK — Doc 11 owns departments, consistent with the
MembershipSource seam), `escalation` jsonb, `pending_reason` (FR-01's nullable enum),
`spam_marked_by`/`spam_marked_at`, `reopened_count`, `channel_unreachable`
(bool, Doc 05's field, schema-only), `linked_work` (bool), `origin_ticket_id`,
`previous_ticket_id`, `merged_into`, `parent_ticket_id` (reserved, §19),
`form_data` jsonb, `tags` text[] (GIN), FR-24's metric columns
(`first_response_at`, `agent_first_response_at`, `resolved_at`, `closed_at`,
`waiting_customer_seconds`, `waiting_staff_seconds`) + `last_status_changed_at`,
`created_at`/`updated_at`. Nothing beyond what Doc 06's text requires.

### A-8. FR-26's event-family reconciliations (FILED INTERPRETATION)

(i) `follower_added|removed` reads as TWO events — `ticket.follower_added` and
`ticket.follower_removed` — matching the list's own idiom
(`spam_marked|spam_unmarked`, `tagged|untagged`). (ii) FR-25's `tickets.bulk_completed`
ships as **`ticket.bulk_completed`**: FR-26's enumerated `ticket.*` family governs
(Q9: enumerations normative, prose descriptive); FR-25's prefix is read as
descriptive. (iii) **`ticket.assignee_deactivated`** — required by EC-06, unnamed in
FR-26's list — enters the catalog by this filed interpretation, the A-4 precedent.
Doc 06's catalog total: 23 events (FR-26's 22 + EC-06's one); `ticket.resolved`
doubles as Doc 14's `ticket_resolved` SMS signal (FR-06), no separate event.

### R-23. The session-cap eviction orders by integer microseconds (OWNER RULING, closes RISK 0e)

**2026-08-08.** Doc 02 FR-10 caps concurrent sessions and evicts "the OLDEST".
`SessionRegistry` scored its sorted set with `getTimestamp()` — whole seconds — so
several logins inside one second carried an IDENTICAL score, and Redis then orders
equal-score members lexicographically BY MEMBER, i.e. by random session id. "Evict the
oldest" therefore meant "evict the lowest session id": a coin flip, latent since Doc 02
shipped, surfaced by CI run 31216863586.

RULING. (1) The score is **INTEGER MICROSECONDS** since the epoch — not fractional
seconds: Redis scores are IEEE-754 doubles, `seconds.micros` needs ~16 significant
digits and can round, while integer microseconds is exact below 2^53. (2) The residual
same-microsecond tiebreak (lexicographic member order) is DOCUMENTED in the class
comment beside the existing just-authenticated-session defence — it is arbitrary but
TOTAL, so the cap still evicts the right count. (3) **No data migration**: every legacy
second-score (~1.7e9) is smaller than every microsecond-score (~1.7e15), so pre-cutover
sessions sort as older, which they are; and they re-score on their next touch, so the
mixed state is self-clearing. (4) Proof at two levels — a deterministic registry test
with CHOSEN microseconds (the browser test can only sample whichever microseconds real
logins happen to land in) plus the browser assertion.

**THE FAMILY PRINCIPLE, which is the durable part.** This is the fourth defect in this
project caused by a clock too coarse to order with (R-19's `created_at`, the S1
`occurred_at` finding, Doc 04's `updated_at` draft guard, and now this). Stated once, to
be applied everywhere: **any ordering decision must use a clock finer than the plausible
event rate, or carry an explicit, documented total-order tiebreak.** A tie that "cannot
happen in practice" is a coin flip in production.

### A-11. FR-15's assignee scope check is the POST-ASSIGNMENT state (FILED INTERPRETATION)

**Filed at S22 step 4, advisor-endorsed.** FR-15 says the "assignee must hold view scope
over the ticket (Doc 03 intersection) — violations rejected assignee_out_of_scope", and
does not say at which moment that must be true. Read in the present tense the rule is
CIRCULAR:

(a) An `Own`-scoped role sees tickets "assigned to the user". An unassigned ticket
sitting in a queue is, by definition, assigned to nobody and therefore outside EVERY
Own-scoped agent's scope. Checking present-tense visibility before assigning would
refuse every claim by every Own-scoped agent — which is most agents — and make
`tickets.assign_self` unusable. The rule would read: you may only take work you already
have.

(b) FR-16's claim race presupposes the opposite: "first commit wins; loser receives
already_claimed {by}" describes two agents racing to claim work NEITHER of them holds
yet. A present-tense reading of FR-15 contradicts FR-16 outright.

INTERPRETATION: the check asks whether the assignee would hold view scope ONCE
ASSIGNED — the only state in which they actually need to open the ticket. So `Own` is
satisfied by the assignment itself; `All` is satisfied always; a role with NO ticket
scope is refused; and **`Team`/`Dept` must still match the ticket's department**, which
is where FR-15 genuinely bites (handing a Payments ticket to a Withdrawals-only agent
leaves them unable to open it — assignment does not change the ticket's department).

Pre-Doc 11 this means Team/Dept assignment is REFUSED, because `MembershipSource` binds
to `EmptyMembership` and those levels resolve to the empty set — fail-closed, consistent
with the rest of the platform, and it loosens the moment Doc 11 supplies real
membership. Implemented as `TicketScope::visibleOnceAssignedTo()`, whose docblock cites
this record rather than carrying the argument. The same question and the same answer
apply to FR-18's followers, which attach a person to a ticket they usually do not hold.

### A-10. §4.2's spam-unmark edge resolves to spam→new and spam→open (FILED INTERPRETATION)

**Filed at the S22 step-2 checkpoint, advisor-countersigned 2026-08-07.** The doc
author has NOT been consulted; this records an inference so it cannot silently become
"how it has always worked".

§4.2 draws the unmark edge as a parenthetical rather than an arrow —
`spam (terminal; unmark ≤ 7 d → prior status)` — and FR-09 repeats the same wording
("unmark within 7 days restores prior status"). Neither names a target status, so
"prior status" has to be resolved before the FR-04 matrix can contain the edge at all.

**THE BASIS IS REACHABILITY, not preference.** A ticket's prior status can only be a
status from which `spam` is reachable. In §4.2 the mark-spam line joins exactly two
descenders — verified mechanically by character index rather than by eye: column 2
(`new`, the `└`) and column 23 (`open`, the `┴` at column 24 joining it). No other
column's descender meets that line. So spam is entered from `new` and `open` and from
nowhere else, and therefore:

- `spam -> new` — unmark within 7 days, restoring the prior status
- `spam -> open` — unmark within 7 days, restoring the prior status

Both carry `tickets.spam.mark` (the catalog holds no separate unmark key, and the
disposition is one permission) and both require a reason (SEC-03 audits spam-unmark
with actor + old/new).

**CONSEQUENCE WORTH STATING.** Because spam is reachable only from `new` and `open`,
marking a `pending_customer`, `pending_internal`, `escalated`, `resolved` or `closed`
ticket as spam is NOT an edge, and §8's "illegal targets absent, not disabled" means
the status control simply never offers it there. FR-25's bulk `spam` action over such a
ticket fails THAT ITEM with `illegal_transition` — AC-07's honest per-item partial
success, not a batch error. The same reasoning applies to `closed`, whose only inbound
edge is `resolved -> closed`: a bulk `close` over an open ticket per-item-fails, and
the advisor's countersign records that as spec behaviour rather than a defect.

Implemented in `TransitionMatrix::EDGES`, whose docblock cites this record.

### A-9. Route→permission mappings Doc 06 does not name inline (FILED INTERPRETATION)

All from the seeded 21-key `tickets.*` catalog (counted from the registry this
session); no key invented (NFR-03): the generic transition route →
`tickets.status.change`; manual/bulk close → `tickets.close`; ticket notes incl.
FR-06's resolution note → `tickets.note`; tag operations incl. T-07 rename/merge →
`tickets.tags.manage`; adding OTHERS as followers → `tickets.assign` (FR-18's
"assigners"; self follow/unfollow stays `tickets.follow`); `tickets.fields.edit`
reserved for Doc 12's custom fields, no route this session; `tickets.delete`
platform-disabled, no route (BR-01).

### A-12. FR-08's beyond-window successor ticket carries source=automation (FILED INTERPRETATION)

**Filed at S22 step 5; needs advisor countersign.** FR-08: a customer message beyond
the reopen window creates "a new ticket in the same conversation carrying a
previous_ticket_id link". FR-01's source enum — `ai_handoff | agent | automation |
api | offline_form` — names the five creation DOORS and none of them is "a codified
platform rule acting on a customer message". The successor has to record SOME source
(the column is NOT NULL, CHECK-constrained, and Doc 15 counts by it), so the gap must
be resolved before the consumer can exist.

INTERPRETATION: `automation`. The door is a rule the platform executes without a
human or an AI decision — which is what `automation` names in every other row that
carries it — and the successor is unambiguous in the data regardless, because it is
the only creation shape with a non-null `previous_ticket_id`. Doc 15 can therefore
count FR-08 successors exactly even if a future ruling renames their source; nothing
is lost while this interpretation stands.

REJECTED ALTERNATIVES, for the record: `api` (no API call happened), `agent` (no
agent acted — SEC-03 forbids misattribution), a NEW enum value (the statuses and
sources are platform-fixed shapes; inventing one is exactly what "never invent
requirements" forbids).

Implemented in `App\Jobs\Tickets\TicketReopenConsumer`, whose comment cites this record.

### A-13. FR-20's merge-close and unmerge-restore are OPERATION DOORS of the sole writer, not matrix edges (FILED INTERPRETATION)

**Filed at S22 step 6; needs advisor countersign.** FR-20: on confirm, "source →
closed with merged_into=target … Reversible for 24 h via unmerge (restores prior
state from snapshot)". FR-04: "Legal transitions are exactly the edges above" — and
§4.2's advisor-countersigned 15 edges reach `closed` only from `resolved`. A merge
source can be `new`, `open`, `pending_*`, `escalated` or `resolved`, so FR-20's close
cannot ride the plain matrix; and NFR-03 ("one server-side writer") forbids the merge
writing status anywhere else. Both requirements survive only one way:

INTERPRETATION: `TicketTransitionService` carries two OPERATION DOORS —
`closeForMerge` and `reopenFromUnmerge` — reachable only from the merge/unmerge
operations, never from a plain status change. The matrix's cells are untouched:
AC-01's 64-cell table still refuses `open → closed` on the ordinary door, which is
what FR-04's sentence governs. The doors still use the guarded repository write, still
emit `ticket.status_changed` + audit (SEC-03), and the FR-24 trigger still stamps.
Riders, each forced:

- **Sources**: open-family and `resolved` only. A `closed` ticket is immutable except
  "reopen, tag management, and serving as a merge TARGET" (FR-07 — source is not on
  the list); `spam` is metrics-excluded (N-14) and merging it into a live ticket would
  smuggle excluded traffic back into FR-24's accumulators.
- **Targets**: any non-spam ticket ("closed … serving as a merge target" is explicit).
  A cycle (a source anywhere in the target's `merged_into` chain, including
  source=target) is refused — EC-03's "resolve transitively" is satisfiable only on an
  acyclic chain.
- **Unmerge permission**: `tickets.merge` governs both directions — the
  escalate/de-escalate same-key precedent; the catalog is closed (A-9/R-12).
- **The merge-close does NOT emit `ticket.closed`** — that event anchors FR-08's
  reopen window and Doc 12's SLA stop, and a superseded source has neither (its exit
  is unmerge, not reopen — see A-14). It does cancel a resolved source's armed T-03
  timer (the deadline is meaningless once superseded); the snapshot records
  `auto_close_at` and unmerge restores both column and candidate, so AC-04's
  byte-identical bar covers the timer too.
- **Unmerge restores, then reconciles**: the snapshot restore is verbatim (AC-04
  diff = ∅ over everything the merge touched: source {status, pending_reason,
  assignee_id, auto_close_at, merged_into}, target {follower set, tag set}, moved
  anchor rows); if the restored assignee was deactivated while the source sat merged
  (EC-06's sweep skips closed tickets, so it cannot have caught this one), the same
  hand-back applies afterwards, separately evented — the EC-06 rule is general.
- **Unmerge open-slot conflict**: if the source's conversation grew a new open-family
  ticket during the 24 h, restoring an open-family status collides with FR-10; the
  refusal is the existing `open_ticket_exists`, and the operator resolves the newer
  ticket first.

REJECTED ALTERNATIVES: new matrix edges `* → closed (merge)` (breaks the
countersigned 15 and AC-01's illegal-cell assertions); a repository-level write
outside the service (NFR-03's gate exists to forbid exactly that); walking the source
through `resolved → closed` (emits a false `ticket.resolved`, triggers CSAT, arms a
timer — three lies for one convenience).

### A-14. A merged-away source is FROZEN: out of FR-08's ladders, no reopen window, until unmerge (FILED INTERPRETATION)

**Filed at S22 step 6; needs advisor countersign.** FR-20 gives a merged source "a
permanent banner → target", while FR-08 would happily reopen any closed ticket within
7 days of `closed_at` — and a merged source HAS a closed_at (the trigger stamps every
entry into `closed`). Left unreconciled, a customer reply in the source's conversation
within 7 days would reopen the superseded ticket alongside the surviving one, and a
staff `closed → open` reopen would do the same — un-superseding a ticket while its
`merged_into` pointer still says otherwise. "Permanent banner" decides it:

INTERPRETATION: while `merged_into` is set, the source admits NO transition except
A-13's `reopenFromUnmerge` (refusal code `merged_superseded`, naming the survivor);
`TicketReopenConsumer`'s candidate ladder excludes merged tickets
(`merged_into IS NULL`), so a customer reply in the source conversation lands as a
message and takes no ticket action — surfacing it on the SURVIVING ticket is the
banner's job (Doc 07) and, for fresh-ticket semantics, Doc 09's handoff decision, the
same division as the consumer's rung 4. The sanctioned path back is unmerge within
its window; after the window the source is simply a closed, superseded record.

### A-15. EC-05's re-route emits `ticket.escalation_rerouted` (FILED INTERPRETATION, the A-8(iii) mechanism)

**Filed at S22 step 6; needs advisor countersign.** EC-05 requires "auto-fallback to
supervisor_review with a system note + notification to the escalator" when a named
escalation target is later disabled. FR-26's enumerated family has no event for it,
and Doc 16's notification needs one on the bus. Exactly as A-8(iii) filed
`ticket.assignee_deactivated` for EC-06's unnamed event, EC-05's enters the catalog as
`ticket.escalation_rerouted` {ticket_id, from_target_user_id, notify_user_id (the
escalator), reason}: re-emitting `ticket.escalated` would overload one schema with two
meanings, and inventing nothing means EC-05 cannot notify.

**A-15 RIDER (filed with the implementation, same step).** FR-01's escalation shape is
`{path, reason, target, at}`; the implementation adds `by` — the escalator's user id —
because EC-05's "notification to the escalator" is unanswerable without a record of
who escalated, and the alternatives (audit-log lookup, event-stream replay) would make
a notification path depend on retention-governed stores. `ticket.escalation_rerouted`
reads `by` into its `notify_user_id`. The fallback also stamps `fallback_from` /
`fallback_at` (DB time) into the payload so the re-route is legible in place;
`path`, `reason`, `at` and `by` survive unchanged.

### COUNTERSIGN RECORD — A-12…A-15 (advisor, 2026-08-08, step-6 acceptance)

- **A-12 COUNTERSIGNED as filed.**
- **A-13 COUNTERSIGNED WITH RIDER**: the no-`ticket.closed`-on-supersede decision must
  be carried into the Doc 15 golden fixtures — a supersede-close scenario whose
  expected metrics show NO close-count increment — so the metric contract owns it,
  not a docblock. **Landing in step 7** (the golden fixtures are already open there
  for AC-06's spam-exclusion scenarios; one edit, two riders).
- **A-14 COUNTERSIGNED as filed.**
- **A-15 COUNTERSIGNED as filed, including the `by`/`fallback_*` rider.**

### A-16. Out-of-order chain unmerges restore per-link intermediate states — as built (ADVISOR RULING)

**2026-08-08, filed at step-6 acceptance.** Each merge link's snapshot captures the
target's follower/tag sets as of that link's own turn, so unmerging links out of
reverse order restores the target to an intermediate state. RULING: this IS the
per-link snapshot semantics EC-03 specifies ("unmerge only per-link within its own
24 h") — an ordering refusal would invent a requirement the document does not state.
As built stands; this record supersedes the MergeTickets docblock as the authority;
UI presentation of chain unmerges is Doc 07's. The owner may override later; nothing
blocks on it.

PARKED WITH OWNERS (same acceptance): the system-note tenant-locale question goes to
Doc 16's question list; `merged_superseded` + `unmerge_window_passed` join the R-14
native-review queue at PRIORITY tier (to be stated in the S22 close-out per the
standing rider).

### A-17. EC-09's fresh ticket: minted only once the spam is TERMINAL, and unlinked (FILED INTERPRETATION)

**Filed at S22 step 7; needs advisor countersign.** EC-09: "Spam unmark on day 8 →
refused with terminality explanation; the customer's next message opens a fresh
ticket (spam never blocks the conversation unless block was chosen)." Two gaps the
sentence leaves open:

(1) WHEN. The fresh ticket is minted only when the latest spam ticket is TERMINAL
(spam_marked_at + 7 d passed, DB time). While the spam is still unmarkable, a
customer message just lands in the conversation: a staff unmark restores the prior
ticket into the FR-10 open slot, and a ticket minted meanwhile would collide with
exactly that restore. EC-09's own framing is the day-8 scenario; nothing in FR-09
asks for a ticket during the window, and the message is not lost — the conversation
is alive and Doc 09's handoff can always act on it.

(2) LINKED OR FRESH. Unlinked — no `previous_ticket_id`. FR-08's successor links
because it continues a real issue's history; EC-09's word is "fresh", and the spam
row is excluded traffic (N-14) — threading it into a live ticket's linked-tickets
rail (§8) would smuggle excluded history back into view. The conversation itself
still holds the full context for a human who goes looking. Source is `automation`
(A-12's reasoning verbatim); idempotency on the event id (C-09).

### COUNTERSIGN RECORD — A-17 (advisor, 2026-08-08, step-7 acceptance)

**A-17 COUNTERSIGNED as filed** (unlinked per EC-09's silence; source=automation per
A-12). **RIDER, advisor-ruled**: the per-tenant bulk concurrency cap of 1 is the v1
DEFAULT — the smallest honest reading of T-06; raising it is a later tuning decision,
not a design change, and needs no ruling when it comes. bulk.progress frames being
ephemeral-by-design is accepted and stands.

### A-18. A ticket's transcript RANGE: its anchor rows where present, else its lifetime window (FILED INTERPRETATION)

**Filed at S22 step 8; needs advisor countersign.** Doc 04 FR-24: "rendered PDF/text
of a conversation **or ticket range**" — the ticket half was inherited row 1, and the
document never defines what a ticket's "range" is. BR-02 decides the primary reading:
"tickets reference ranges" and ticket_message_anchors is, per its own migration, THE
boundary table — so where a ticket HAS anchor rows (a handoff's transcript_anchor, a
split's split_selection), its transcript is exactly the messages those ranges resolve
to, in (created_at, id) order. But most tickets have no anchors (agent-manual,
automation, offline-form creations write none), and "no anchors → empty transcript"
would make the feature vanish for the common case; for those the range is the
ticket's LIFETIME WINDOW — messages from the ticket's own DB-stamped created_at up
to its closed_at (unbounded while it lives). Both bases are named in the audit entry
(`range_basis: anchors|lifetime`), the event payload is UNCHANGED (v1 — the export
signal; the ticket detail is the audit's, the Q7 posture), and the gate, watermark,
notes double-confirm and SEC-05 posture are Doc 04's, byte for byte — SEC-05 keeps
tickets.export (property/timeline) a separate future gate.

### COUNTERSIGN RECORD — A-18 (advisor, 2026-08-09, step-8 acceptance)

**A-18 COUNTERSIGNED as filed** (anchors where present, else lifetime window; the
audit carries ticket_id + range_basis; the event stays v1). The shared-render-path
closure of inherited row 1 endorsed as the strongest form of "cannot drift".

---

## S23 (Doc 05) plan-approval rulings — advisor-ruled, owner-approved, 2026-08-09

### A-19. Bot-token storage: the hmac_secret precedent, pending the Doc 18 vault (ADVISOR RULING)

Doc 05 SEC-01 says vault-only per Doc 01 SEC-04, but no vault exists (Doc 18 owns it;
BUILD-STATUS is explicit). RULING: `telegram_bots.bot_token` stores as a Laravel
`encrypted` cast (the `webhook_credentials.hmac_secret` / TOTP posture, ruling D-5),
in `$hidden`, pinned by the secrets-never-serialize suite; NFR-06 extends the SEC-08
scrubber with the Telegram bot-token shape (`\d+:[A-Za-z0-9_-]{30,}`) and the
`api.telegram.org/file` URL shape (T-07: file URLs CONTAIN the token), each with a
plant→detect→remove positive control; the UI never displays a stored token (re-entry
to change, SEC-01); FS-03's vault-unavailable maps to retryable
`credentials_unavailable` (see A-20) with NO fallback storage by design. The C-13
vault-migration handoff is FILED for Doc 18: on vault arrival, `bot_token` becomes a
vault reference by expand-contract migration.

### A-20. Two document-required additions to the frozen DeliveryFailureReason enum (ADVISOR RULING)

`connection_paused` (non-retryable; Doc 05 FR-18 verbatim) and
`credentials_unavailable` (retryable; FS-03 verbatim) enter the enum — both are
DOCUMENT-REQUIRED, which is what the enum's series-review bar exists to check. The
conformance suite's expected classification table updates in the same commit; both
edits are enumerated file-by-file in SS-04's zero-core-edits diff assertion. The
overload alternative (window_expired / channel_unavailable doubling up) is REJECTED
as requirement-simplification.

### A-21. T-02 met by a thin in-house TelegramBotApi client; Nutgram declined (ADVISOR RULING + ADR 0012)

The ADR 0008 clamd precedent: ~300 auditable lines over a framework dependency.
Proxy pinning stays explicit (every request carries the egress-proxy option from
config — the broken-proxy sabotage proves no direct fallback), and the per-bot token
binds at call time from the bot registry, which satisfies T-02's no-per-bot-deploys
requirement. The doc's Nutgram mention reads as a stack-context minimum (standing
reading); the deviation is ADR 0012's subject. Owner approved as stack growth with
the S23 plan.

### A-22. channel.health.changed schema_version 2, tri-state, TRANSITION-ONLY (ADVISOR RULING)

Payload `{tenant_id, channel, channel_ref_id, status}` with
`status: healthy|degraded|down` (FR-29), TRANSITION-ONLY — **this filing is the
canonical home of the transition-only semantics**: A-4's text never carried it (the
S23 session verified this); it binds from this ruling forward, not retroactively
"from A-4". Doc 04's ChannelHealthTracker is reconciled to emit v2 (up→healthy,
down→down); that edit joins A-20's in the zero-core-edits diff assertion. No
consumer contracts exist for the event yet, so the bump breaks nothing; the S22
completeness machinery demands contracts the moment a consumer ships.

### A-23. FR-05's mode is per-bot entity configuration, not a tenant-singleton settings key (ADVISOR RULING)

"Setting per Telegram channel" (Doc 05 §1) and "Per bot" (FR-05) read together:
`telegram_bots.mode` enum column (`direct|business|hybrid`), rendered on the
Connection tab (frame B01) wearing the per-key settings-row contract exactly as
B6-X01 draws it (J03–J06, J08 consequence-before-save). The genuine tenant
singletons (window, rate, health thresholds) register in `registries/settings.php`
normally.

### A-24. Dev-inbound strategy: capture-replay; no public ingress; /human event named (ADVISOR RULING, two riders)

Real captured updates (getUpdates via the egress proxy) replay as authenticated
POSTs through the full S15 middleware stack; AC-03's lifecycle walk is owner-assisted
live capture; setWebhook/getWebhookInfo register/rotate/verify prove against the real
API; AC-08's render comparison uses owner-supplied screenshots. RIDER 1 — the S23
close-out carries verbatim: "true Telegram→endpoint webhook delivery was never
exercised in dev (no public ingress); the first publicly-reachable deployment must
verify real delivery" — filed as a Doc 18 handoff row. RIDER 2 — the staging bot's
webhook stays UNSET except during register/rotate proofs; any tunnel is a new
explicit decision. The /human event name `telegram.handoff_requested` is APPROVED as
filed (neither Doc 09 FR-12 nor Doc 06 names the trigger event; the A-4 pattern
applies; Doc 13 files its own equivalent if ever needed).

### CORRECTIONS bound at S23 plan approval (advisor, binding)

- **C1**: `telegram.window.closing_hours` registers `tenant_editable: false`,
  default 2 — FR-22 DEFINES closing as ≤2 h; operators get the notification option
  (`telegram.window.closing_notify`, tenant-editable), not the threshold.
- **C2**: every remaining new settings key's `tenant_editable` is set by what the
  frozen B6 frames actually draw (drawn operator editing ⇒ true, else false), with
  the frame cited in the registry entry.
- **C3**: step 1 adds the notifications registry entries — every admin notification
  Doc 05 mints (paused/revoked, misconfiguration, SEC-04 pending-too-long, FS-04
  digest, closing-notify) gets a registered category with the 3-layer opt model,
  same-commit as its producer.
- **C4**: the step-12 §14 sweep explicitly covers numbered-menu locale digits and
  RTL-ready composition — both cited in the sweep's evidence.
- Advisor's own record: the session prompt's "catalog at 24" was the advisor's
  wording error (24 was Doc 06's family count; the platform catalog at 73 governs
  per Q9).

### A-25. The webhook-edge quota check fails OPEN on metering outage (ADVISOR RULING, S23 step-4 checkpoint)

Doc 01's QuotaMeter fails CLOSED for inbound surfaces on metering outage
(FS-01's bounded-queue backpressure reading); the S15 FS ruling proved the
telegram secret_header surface stays 202 against the same dead volatile
instance, reasoning "capacity protection, not authentication — fails OPEN
with its own metric… capacity protection must not convert a cache outage
into a channel outage." RULING: at the WEBHOOK EDGE the quota check is
capacity protection on that same surface and degrades OPEN with the
`quota.check_degraded` metric; the bounded-queue backpressure the exception
asks for is the queue itself. A 500 would tell the channel to hammer a
degraded platform. Applies to channel webhook edges; staff and API surfaces
keep their existing postures. Proven by WebhookHmacTest's FS case driving
the real edge against a dead volatile port.

### A-26. The media chain's just-in-time SourceUrlResolver seam (ADVISOR RULING, owner-approved, S23 step-5)

Doc 05 T-07 (Telegram file URLs contain the token: never persisted, never
logged, never serialized) collided with the Doc 04 chain's persisted
`source_url` contract — the first real channel exposed the chain's
stable-URL assumption. RULING: the chain gains a scheme-keyed resolver
registry consulted PER FETCH ATTEMPT — the media item persists an OPAQUE
`telegram-file:{bot_id}/{file_id}` reference; the tokened URL exists only as
a local variable inside the fetch; job payloads unchanged; unknown schemes
behave exactly as today. BINDING CONDITIONS: (1) the edit is the third ruled
exception in the zero-core-edits diff assertion, per-file; (2) per-attempt
resolution proven by an expired-then-refreshed-URL test on the EC-11
hold/retry path; (3) resolver failure classifies through the existing
machinery — Telegram's "file is too big" → MediaTooLarge (the chain's
terminal reject, EC-07's door), transient errors → the hold/retry path,
never a silent skip — with a test; (4) the T-07 scrubber positive control
proves the resolved URL reaches no log, no job payload, no row
(plant-detect-remove); (5) sabotage list AMENDED to 19 items — item 19:
resolver deregistered → the telegram media fixture goes red on the hold
path. The Doc 04 docblock records WHY the seam exists. The close-out ledger
reconciles against 19 items.

### A-27 (RULED, advisor at S23 step-5 checkpoint; owner-approved). Owner-phone attribution: the Doc 04 inbound persister gains an explicit author override

Doc 05 T-03/FR-11/BR-03: a business_message whose `from = owner` maps to
`author_type=agent` under a lazily-created reserved staff identity
"{account_name} (phone)", reported as agent type `owner_phone`. The DETECTION
is built and proven at step 5 (RouteTelegramUpdate flags `owner_phone` on the
envelope when from.id == the connection's account_user_id; the flag rides
channel_meta, InboundMappingTest + InboundPipelineTest green). But
`InboundPersister`/`MessageRepository::persistInbound` HARD-CODE
`author_type='customer'` — the Doc 04 inbound door cannot express an agent
author, and the `messages.author_type` CHECK has no `owner_phone` value (it
is 'customer'|'agent'|'ai'|'system'; `owner_phone` is a REPORTING agent-type,
A-8/T-03 precedent, not a message author_type). CURRENT PROVEN BEHAVIOR
(documented in the test): the message persists as `author_type='customer'`
with `channel_meta.owner_phone=true`. PROPOSED (smallest core touch, a
step-6 ruled exception if approved): the persister accepts an optional
author override so an owner-phone inbound writes `author_type='agent'` +
the reserved-identity author_id (T-03), reported as `owner_phone`. This is
Doc 08 identity-seam adjacent (the reserved staff identity is a Doc 08
pattern) — the ruling decides whether the persister gains the override in
Doc 05 or whether owner-phone attribution defers to Doc 08 with the
detection + channel_meta flag shipped here. Raised at the step-5 checkpoint.

**RULING (advisor, owner-approved): the persister gains the override — the
FOURTH ruled core edit.** T-03/BR-03 are DOCUMENT-REQUIRED verbatim and
Doc 08 owns CUSTOMER identity, not staff authorship, so the defer alternative
is rejected. BINDING CONDITIONS: (1) enumerated per-file in the
zero-core-edits diff assertion; (2) the override is reachable ONLY when the
update's from.id matches the connection's registered account_user_id by
SERVER-SIDE comparison — a negative spoof control proves arbitrary inbound
content can never claim agent authorship; (3) the reserved staff identity is
lazily created per T-03 ("{account_name} (phone)"), linked as author, with
EC-05's forward-rename semantics; (4) sabotage list AMENDED 19→20 — item 20:
the override gate removed (any inbound can claim agent) → the spoof control
goes red; (5) non-owner inbound proven byte-identical (regression). The
reserved identity reports as agent-type `owner_phone` (A-8/T-03 precedent),
distinct from the message `author_type='agent'`.

### A-28 (COUNTERSIGNED at the owner's step-11 confirmation; filed at the step-12 close-out review). FR-30's WRITE test tools ride `telegram.bots.manage`

The step-11 plan's one-line mapping put "test tools" under
`telegram.health.view` — a VIEW-tier key Read Only holds — and
ReadOnlySweepTest caught `simulate-window-expiry` ACCEPTING a Read Only
write (302; the SEV-1 the sweep exists for, fixed in the flow at step 11).
FILED INTERPRETATION (step 11): the WRITE tools (send-test,
simulate-window-expiry) ride `telegram.bots.manage` — the established
precedent of their sibling write tool, one-click re-register; viewing (the
health panel, the masked raw viewer) stays `telegram.health.view` as
planned. **COUNTERSIGNED: the owner's close-out review message confirms the
advisor countersign given at the step-11 confirmation.** Record note: this
filing was ordered at the close-out review because the countersign had not
yet been written into this register — the interpretation itself, its
defect, and its fix have been in the step-11 BUILD-STATUS section since
`a01833b`.

### A-29 (RULED at the owner's step-12 close-out review). FR-26 "stored on the conversation" is satisfied adapter-side — APPROVED on three conditions, each discharged

FR-26, verbatim (Doc 05 §4.7): "FR-26 Deep links: t.me/{bot}?start={payload};
payload schema src-{source}[_ctx-{context}] (e.g., src-app_ctx-deposit) →
**stored on the conversation as acquisition metadata** and passed to the AI
first turn (Doc 09) for context-aware greeting; unknown payloads ignored
gracefully."

A column on `conversations` would be a Doc 04 core edit (the zero-core-edits
assertion, inherited row 1). RULING: the adapter-side `telegram_acquisitions`
table satisfies "stored on the conversation", on three conditions:

1. **The acquisition row is deterministically reachable from its
   conversation, proven by test.** DISCHARGED: the row is keyed by the
   conversation's own channel identity under
   `UNIQUE (tenant_id, bot_id, external_chat_id)` (one row can ever match),
   and `DeepLinkResolver::forConversation()` resolves conversation →
   (channel_ref_id, external_chat_id) → row. Proven in
   `tests/Feature/Telegram/DirectSurfaceTest.php` — "FR-26: record is
   FIRST-WINS and redelivery-idempotent (C-09); the seam reads it back per
   conversation" (the join asserted with the original acquisition returned
   and an unknown conversation answering null).
2. **Write-once-by-grant proven at the migration level.** DISCHARGED: the
   REVOKE lives in the migration itself
   (`2026_08_10_110000_create_telegram_acquisitions_table.php` — `REVOKE
   INSERT, UPDATE, DELETE … GRANT INSERT (…columns, created_at excluded)`),
   and `tests/Isolation/TelegramGrantPhysicsTest.php` asserts the resulting
   physics from `information_schema`/`has_column_privilege` — "FR-26:
   acquisitions are WRITE-ONCE for the app — no UPDATE, no DELETE, on any
   column", plus the table's entry in the R-19b denied-set loop
   (`created_at` insert-denied, defaults fill) and the FORCED-RLS sweep.
3. **The interpretation filed citing FR-26's verbatim text.** DISCHARGED:
   quoted in full above; the BUILD-STATUS step-12 section and the
   `telegram_acquisitions` migration docblock carry the same filing.

The AI-first-turn half ("passed to the AI first turn") remains Doc 09's
pre-agreed bucket; `forConversation()` is the seam it consumes.

### R-22 RESOLVED / R-24 / R-25 — three owner rulings of record (2026-08-10, filed at the S23 close-out per the owner's final-docs order)

**R-22 RESOLVED.** The tag rename/merge surface is an implementation-side
class-2 fill using existing design-system components (settings-row +
confirmation patterns); **no design-track reopen**. Built and frame-mapped
when Doc 07 reaches it. (Closes the design half left open by R-22's
headless ruling in S22 — the T-07 chunked resumable rewrite stands as
built.)

**R-24.** Untranslated menu buttons render FALLBACK-LANGUAGE TEXT per the
Doc 01 fallback chain — never a blank and never a raw key — with the §14
completeness indicator surfacing the gap in Backoffice. (This also answers
the B6-S04 delivery note's carried question, DESIGN-DEVIATIONS row 7: the
fallback is the ruled rendering.)

**R-25.** Health outage bars use the NEUTRAL INK RAMP; status hues stay
reserved to their semantic components per design-system law R-07/R-08.
(This also answers the B6-S06 delivery note's carried SLA-token question,
DESIGN-DEVIATIONS row 8: the as-drawn ink ramp is the ruled rendering.)

---

## S07 (Doc 07) plan-approval rulings — advisor-ruled, owner-approved, 2026-08-10

Filed at plan approval, before implementation (the committed plan is
`docs/SESSION-07-APPROVED-PLAN.md`). A-30/A-31 are advisor-countersigned filed
interpretations; A-32 is an advisor ruling the owner may override. The doc author has
NOT been consulted. This record is canonical over any restatement in commits or
BUILD-STATUS.

### A-30 (COUNTERSIGNED). EC-04 per-viewer counts ride per-scope ZSET variants

Doc 07 T-01 flags the per-scope-ZSET-variant-or-filtered-count decision and EC-04
requires per-viewer counts under BR-03's no-hidden-rows-indication rule. FILED: the
view-hash embeds the viewer's resolved Doc 03 scope signature (SEC-01 already requires
scope context in the hash); viewers whose resolved scope differs get a materialized
variant ZSET, patched by the same projector delta path; ZCARD per variant is the
per-viewer count. Chosen over filtered-count-at-read because T-03 requires counts
PUSHED on delta (debounced 2 s) — a read-time filter per push per viewer is a stampede
by construction. COUNTERSIGN CONDITIONS, binding: (1) variants materialize LAZILY on
first subscribe; (2) eviction/volatility documented per C-07; (3) FS-02's rebuild
covers variant loss; (4) the EC-04 fixture proves two scopes → two counts with zero
out-of-scope rows.

### A-31 (COUNTERSIGNED). The T-07 sync endpoint's scope filter joins through ticket_list_items

T-07 returns "ordered events since cursor for the user's subscribed scopes" without
naming the scope mechanism. FILED: the sync read filters `events` by tenant + the
consumer-relevant name set, then applies the SAME scope predicate the lists use —
joining through `ticket_list_items` for ticket/message events — so reconnect delivery
equals list/thread visibility exactly. COUNTERSIGN CONDITION: the reconnect golden
asserts a scope-excluded event never reaches the narrower client.

### A-32 (RULED, advisor; owner may override). A mention is "unresolved" until the mentioned agent opens the thread or dismisses the bell row

Doc 07 FR-05 defines the Mentions system view as "unresolved @mentions" and defines
"unresolved" nowhere. RULED as proposed: a mention is unresolved from `note.mentioned`
until the mentioned agent next opens that ticket's thread or dismisses the bell row;
Mentions-view membership = tickets with unresolved mentions for the viewer. Trade-off
named at ruling: lower-friction than an explicit resolve action, at the cost of
"glanced but not handled" leaving the view — acceptable for v1, revisit on dogfood
feedback. AC-02's membership fixture is written against this definition.

### Corrections bound at S07 plan approval (advisor, binding)

- **C1**: `ticket_list_items.snoozed_until` DROPPED — no Doc 07 text demands it
  (FR-31's "S" is snooze-to-pending, Doc 06's status action; the time picker is §19).
  No invented schema.
- **C2**: the `events.occurred_at` R-19 treatment is BUILD-STATUS row 9's filed
  deferral discharging, not a new core-edit decision — but it edits Doc 01's relay
  path, so the S23 ruled-edit pattern applies: relay-side change enumerated in a
  committed diff assertion citing row 9; sabotage #16 at the migration level; the
  step-1 battery includes the Doc 01–06 suites as the event-write regression proof.
- **C3**: Doc 03 EC-02 reconciles at close-out against the row's CURRENT BUILD-STATUS
  text (transition half CLOSED at S22 step 2; the S19 audit row carries the UI-state
  half) — executed evidence only, no double-closing.
- **C4**: the PROJECT-HANDOFF-v6 gap is RULED — the committed session prompt
  (`90ec851`) is the binding restatement of the inherited rows; the owner filed the
  v6 kit at `8e3c0b4` (governance #2's third recurrence, caught at the S07 reading
  check).
- `inbox.away.idle_minutes` registers `tenant_editable: true` — APPROVED (operator
  policy per BR-06/§21; the editing surface arrives with Doc 16).
- **Stack growth APPROVED (R-15)**: `@tanstack/vue-virtual`, `pinia`, `@lhci/cli`
  (dev/CI only); the declined list (in-house fuzzy scorer, native
  BroadcastChannel/IndexedDB, existing Playwright/scout) accepted as stated.

### S07 SCHEMA AMENDMENT 1 (filed at step 1, before the migrations shipped — the A-7-amendment-2 precedent; REWRITTEN to the advisor's completeness bar at the step-1 checkpoint ruling; needs advisor countersign)

The approved plan's table list missed per-VIEWER read state, and three sort/
boundary keys need pinned copies the plan's §6 table did not name. All are
CORRECTIONS the document text demands, recorded here rather than slipped into
migrations. The final Doc 07 table list is SEVEN: ticket_list_items,
inbox_saved_views, inbox_selection_tokens, inbox_notifications,
inbox_user_prefs, agent_statuses, inbox_read_markers (correction C1's
`snoozed_until` stays dropped — no document line demands it).

1. **`inbox_read_markers` (new table).** Doc 07 FR-09 requires "unread bold
   state + count" on the row and FR-15 requires the thread to open "at first
   unread" — per-viewer facts that cannot live on the shared
   `ticket_list_items` row. One marker per (user, conversation); unread
   state/count are computed at hydration by overlaying the viewer's markers
   (and Doc 04 drafts) on the shared page — ZSETs and pushed counts never
   carry per-viewer unread numbers.

   **Full R-19b per-column analysis (every column):**
   - `id` (UUIDv7, C-01), `tenant_id` (C-02), `user_id`, `conversation_id`,
     `last_read_message_id` — identities and the provenance pointer; not
     timestamps.
   - `last_read_at` — **PINNED-PROVENANCE, ordering-RELEVANT**: it is
     compared against DB-time message stamps for the first-unread/unread
     computations, so it must live in message-stamp space — a byte-copy of
     the last-read message's own `messages.created_at`, NEVER a fresh clock
     read (a wall-clock write here would shift the unread boundary by clock
     skew). App-writable by that documented discipline; the marker writer's
     test proves byte-equality with the source message stamp when the writer
     ships (step 9's list/thread wiring).
   - `created_at` — row birth, hygiene default clock_timestamp(),
     app-writable (no ordering key or cursor reads it).
   - `updated_at` — maintenance metadata (LWW on the viewer's own marker);
     not ordering/metric-bearing.
   - No metric reads this table (BR-06's active-time metrics derive from
     activity events); DELETE revoked; RLS FORCED with tenant_isolation.

2. **`ticket_list_items` gains three pinned-provenance timestamp copies
   beyond the plan's two, each with its document citation:**
   - `ticket_created_at` — Doc 07 FR-10: "Unassigned = created asc
     'first-come'" (BR-04 encodes the value). The sort key is the TICKET's
     own DB-time birth; the projection row's created_at is the row's birth,
     not the ticket's, so a copy is required.
   - `last_message_created_at` — Doc 07 FR-09 ("unread bold state + count")
     + FR-15 ("opens at first unread"): the unread-boundary anchor the
     hydration overlay joins against the viewer's `last_read_at` marker,
     in message-stamp space on both sides.
   - `sla_due_at` — Doc 07 FR-05 ("SLA at risk (Doc 12 warned/breached,
     open-family)") + FR-10 ("SLA at risk = time-to-breach asc"): the
     time-to-breach sort key; the Doc 12 seam, fixture-fed until Doc 12
     produces real policy data.
   Same class as the plan's `last_activity_at`/`window_expires_at`:
   app-writable copies of canonical DB-time stamps, never fresh clock reads;
   ZSET scores derive from them as INTEGER MICROSECONDS (R-23).

The InboxGrantPhysicsTest denied-set map and the migrations' docblocks carry
this same analysis; the sabotage list is amended by A-33 below (20→21), not
by this amendment.

### A-33 (ADVISOR RULING at the S07 step-1 checkpoint, 2026-08-10). The events-stream ordering and idempotency contract — Option B

**The finding (C2's battery, working as designed):** the as-filed row-9
treatment (relay stops supplying `occurred_at`; clock_timestamp() fills at
the relay insert) BROKE C-09's duplicate-delivery physics — the events PK
`(id, occurred_at)` deduplicated re-driven outbox rows only because the relay
supplied a deterministic COPY; a fresh stamp per insert let the same event
land twice (OutboxChaosTest red, deterministic, alone and in-suite).
**Record correction absorbed into this ruling:** the stamp was ALWAYS DB
time — `outbox.occurred_at` defaults to Postgres now() at publish and the
relay copies it; BUILD-STATUS row 9's "relay's APP clock" premise was wrong.

**RULED: Option B, four conditions:**

- **B-1.** The relay reverts to copying `outbox.occurred_at`; the
  composite-PK dedupe physics returns byte-identical. OutboxChaosTest goes
  green via the restored physics, never via test change — PLUS a new
  positive control asserting the PK refusal ITSELF (error class and the
  re-driven row-count trace through the real relay).
- **B-2.** The role/privilege topology, stated exactly: the `app` role
  CANNOT name `occurred_at` (column grant — physics; the migration's
  default+grant governs DIRECT APP-SIDE writes). The relay runs as
  `platform_admin`, which necessarily keeps table-level INSERT (it must
  supply the copy; fixtures backdate through it), so WITHIN the platform
  role "the supplied value is the outbox copy" is pinned by the strongest
  STRUCTURAL mechanism available and stated honestly as such, not as grant
  physics: the committed diff assertion pins the copy expression, and the
  byte-identical behavioural proof (events.occurred_at === its outbox row's
  occurred_at through the real relay) executes it. **Sabotage list AMENDED
  20→21 by this ruling** — item 21: the relay's stamp-copy broken (a fresh
  clock supplied instead of the outbox copy) → the A-33 byte-identical
  proof goes red.
- **B-3.** BUILD-STATUS row 9's text corrected in the same commit; the
  deferral's discharge redefined there: ordered-read treatment = the T-07
  watermark + UUIDv7 within-stamp tiebreak contract (step 5) + the
  direct-write guard (step 1). The relay revert and the guard's final shape
  are enumerated in the committed diff assertion citing row 9 AND this
  ruling.
- **B-4 (owed to step 5, recorded now):** the sync watermark value is
  stated WITH its derivation (the commit-skew bound it protects against);
  the reconnect golden proves no-miss under a long-transaction fixture;
  UUIDv7 is documented as within-stamp tiebreak ONLY (R-19's narrowing);
  the freshness trade-off is documented in the endpoint contract —
  reconnect sync serves settled events, the live path is Reverb's.

### A-34 (FILED INTERPRETATION, the A-28 shape; advisor-ruled at the S07 step-4 STOP). FR-06's "any user with tickets.view" names the AUDIENCE, not the write-tier grant

ReadOnlySweepTest caught `POST inbox/views` accepting a Read Only write —
Doc 07 FR-06's literal wording ("any user with tickets.view creates personal
views") collided with Doc 03 BR-05/AC-06's zero-accepted-writes bar for Read
Only, which holds tickets.view. INTERPRETATION: FR-06's sentence names WHO
may have personal views (the workspace audience), not which key grants the
WRITE; saved-view create/update/tombstone/duplicate ride `views.manage`
(R-26); `views.share` is unchanged, share-only, per FR-06's own separation.
DECISIVE EVIDENCE: the tickets.edit alternative would deny Junior Agents
personal views (their template lacks tickets.edit), contradicting FR-06's
audience sentence — this interpretation preserves BOTH documents' intent.
The sweep stays ABSOLUTE (option (b) was rejected as gate-weakening).
Proofs: the SEV-1 flips green via the MAPPING change only; an explicit Read
Only refusal; the Junior Agent audience proof; Read Only reads system views.

### R-26 (OWNER-SIGNED, 2026-08-10). `views.manage` — the THIRD addition of record

Registered via the views.share mechanism (doc-owner sign-off in the ruling
paste): normal-risk, unscoped, owning_doc Doc07; catalog 112→113;
CatalogSnapshotTest/RoleSeederTest pins moved in the same commit (NFR-03
satisfied — the key now exists, nothing invented). TEMPLATE SEEDING, one
coherent change under the R-10 ratification: views.manage seeds into ALL
templates EXCEPT Read Only — Super Admin (all-keys rule), Admin, Manager,
Team Leader, Senior Agent, Agent, Junior Agent, QA (personal views are
workspace config; config for a read-and-score role is consistent). Read
Only's exclusion is AUTOMATIC PHYSICS: its grant list is computed
(non-high + `.view` suffix), which views.manage fails — the exclusion needs
no hand-maintained list. Appendix B amended citing this ruling.

### R-26 AMENDMENT (advisor-ordered at the 6+7 acceptance, 2026-08-10; the countersign's one condition). The GENERALIZED principle, so future siblings cite one statement instead of accreting cases

**Per-user workspace-state and workspace-config writes ride `views.manage`;
Read Only's exclusion is the enforcing mechanism; the underlying action's
own keys remain the authority at execution (Doc 06 per-item live checks
unaffected).**

Applications of record: (1) saved-view create/update/tombstone/duplicate
(the original R-26/A-34 ruling); (2) the prefs write (PATCH /inbox/prefs —
countersigned extension at the 4+5 acceptance); (3) BOTH selection-token
doors (POST /inbox/selections + /{tokenId}/execute — countersigned as the
same principle's sibling at the 6+7 acceptance; the executed 403 proofs at
both doors verified). A fourth sibling cites THIS statement and flags at
its checkpoint; it does not reopen the ruling.

### A-35 (ADVISOR-RULED at the 6+7 acceptance, 2026-08-10). BindTenantContextToJob becomes restore-what-you-found — the FIFTH ruled core edit, under the S23 ruled-edit pattern

**The two-sighting history (verbatim from the reports):**

- **First sighting (S07 step 6, the indexer):** wiring the event-driven
  `IndexSearchDocuments` job under queue=sync stripped the DISPATCHER's
  ambient binding — `BindTenantContextToJob` CLEARS context in its finally
  instead of restoring what it found; the whole inbox suite went
  MissingTenantContext the moment the indexer was wired. Local mitigation:
  the indexer rides `RunsWithinTenant` (restores the caller; skips the
  rebind when already inside the tenant). Evidence:
  `docs/evidence/S07-step6/` (the step-6 report's defect (1);
  `make-test-01.txt` is the battery on the mitigated tree).
- **Second sighting (S07 step 7, the inline bulk):** under queue=sync,
  `TicketBulkJob` runs INLINE inside `BulkOperation::submit()` and the same
  finally cleared the caller's binding — `SelectionTokens::execute`'s own
  audit write after the chunk loop died unbound. Local mitigation: the
  guarded restore (rebind only when context went null). Evidence:
  `docs/evidence/S07-step7/` (`make-test-03.txt` is the battery on the
  mitigated tree; the mechanism is commented at the call site).

**THE RULING (GRANTED, five conditions):** change the middleware to
restore-what-you-found — capture the prior binding, restore it in finally,
never clear. Conditions: (1) the edit is enumerated in a committed diff
assertion citing A-35 and both sighting evidence paths; (2) a dedicated
regression test pins the restore semantics under the sync driver; (3)
**sabotage #22** — middleware reverted to clear-not-restore → that
regression test red → restored — with the 21→22 sabotage-list amendment
FILED before the sabotage runs; (4) the two local mitigations
(`RunsWithinTenant`, the guarded restore) REMAIN as defense in depth, each
commented to note the middleware now also restores; (5) the full battery
including the Doc 01–06 suites is the regression proof on the commit that
carries it. The edit rides the step-8 commit.

### R-26 AMENDMENT — sibling ledger update (advisor countersigns at the step-9 acceptance, 2026-08-11)

Application (4) of record: `POST /inbox/read` (the SCHEMA AMENDMENT 1 marker
writer's HTTP door) — cited against the generalized statement, refusal
executed (403, zero marker rows). CONSEQUENCE RECORDED, per the countersign:
a Read Only principal's read-position does not persist, so its unread
indicators are SESSION-LOCAL — the same designed degrade posture as the
prefs rider: silent, zero error spam. The UI verification of that posture
rides the step-10 thread surfaces (thread-open is the marker's first
caller).

### A-36 (ADVISOR-RULED at the S07 step-13 completion, 2026-08-13). §13's "listbox" for the ticket list yields to the GRID pattern — class-1 technical necessity (WCAG 4.1.2)

**The conflict.** Doc 07 §13 (and the step-9 deferral note) name the ticket
list a *listbox* with roving tabindex. The step-13 landing implemented it
literally: `role="listbox"` rows as `role="option"`. The moment the coach
guard let the layout axe sweep actually run, axe failed it **serious, WCAG
4.1.2 (nested-interactive)**: an option may not contain interactive
children AT ALL — negative tabindex on the inner checkbox/open-button does
not exempt them from assistive-tech focus — and the drawn row carries both
a selection checkbox and an open control. This is the exact constraint the
step-9 deferral note itself named ("an option may not contain
tab-reachable interactive children"); the literal listbox cannot hold it.

**The choice: GRID (rows/gridcells), row-focus model — not
aria-activedescendant.** Two standard resolutions exist. (1)
`aria-activedescendant` keeps `role="option"` but requires hoisting every
interactive child out of the option; it also moves DOM focus to the
container, which breaks the §13 focus policy this workspace proved (the
roving test asserts `document.activeElement` IS the active row) and
degrades the drawn per-row controls. (2) The **WAI-ARIA APG grid pattern**:
container `role="grid"`, rows `role="row"` (aria-selected is lawful on a
grid row), row content in `role="gridcell"` cells, which lawfully contain
widgets. The grid preserves the document's INTENT exactly — one tab stop,
J/K roving focus with arrows mirroring, visible selection, every control
keyboard-reachable (X selects, Enter/O opens, the inner controls stay out
of the tab order) — and the existing keyboard tests pass unchanged.
**Grid is the ruling's choice.**

**Scope of the edit:** `Row.vue` (`option`→`row`; the content wrapper and
claimed-flash become `gridcell`; the mid-gesture swipe zone is
`presentation`), `ListPane.vue` (`listbox`→`grid`; the empty-state and
load-more rows become row/gridcell so the grid's required-children
contract holds), `lib/shortcuts.ts` (the Enter-yield selector learns
`[role="row"]`/`[role="grid"]` so Enter on a focused row stays the row's
own binding, exactly as it was for options). The `t.list.listbox_label`
i18n KEY name is retained (its value is the accessible name and is
unchanged); renaming the key would churn both language files for zero
user-visible effect.

**Proof obligations (per the ruling):** the layout axe sweep RUNNING GREEN
on the converted list, plus the existing keyboard/roving/selection tests
unchanged, in the step-13 consolidated battery of record. Filed as a
class-1 technical-necessity deviation in DESIGN-DEVIATIONS (document word
yields to markup validity).

### A-37 (ADVISOR-RULED at the S07 step-14 D8 stop, 2026-08-13). The audit-declaration verifier recognizes DOCUMENTED noop paths by explicit in-code declaration — EC-07's same-state success is the first

**The collision, both sides correct by their own documents:** Doc 17
FR-11 / CLAUDE.md §2 (via the step-13 `DeclareAuditAction` verifier): a
2xx on a route declaring an audit action MUST have written the row. Doc
06 EC-07 (AC-01 clause 3, S22-proven): a same-state transition is a
SUCCESS that writes "no error, no event, no audit entry." D8's drawn
quiet-agreement surface pushed the noop through the declared route for
the first time in a guarded environment → LogicException → 500. Latent
since the route's declaration; filed in the honest-trail.

**The ruling (option b, conditions executed):** the verifier skips ONLY
on an explicit `audit.noop` request attribute — the signed-skip shape:
deliberate, in-code, reviewable. The declaration is CAUSAL, set at the
exact EC-07 branch in the transition door, tied to the OWNING service's
own noop return (`TransitionResult::$changed === false`) — never at
controller entry, never derived from response shape; no Doc 06 file was
touched (the noop fact was already public on the service's return).
**Falsification executed both directions** (ComposerEndpointTest): the
declared noop passes the REAL guarded route with zero
`ticket.status_changed` audit rows; an UNDECLARED missing audit on a
bespoke route through the real middleware still THROWS. EC-07's S22
clause and the D8 browser proof both run green through the guarded
route.

---

## S08 (Doc 08) plan-approval rulings — advisor-audited, owner-approved, 2026-08-14

Filed at plan approval, before implementation (the committed plan is
`docs/SESSION-08-APPROVED-PLAN.md`). The advisor's independent enumeration reconciled
91/91 family-exact. The doc author has NOT been consulted. This record is canonical
over any restatement in commits or BUILD-STATUS.

### A-38 (RULED at S08 plan approval). Erasure INITIATION rides `customers.delete`

Doc 08 SEC-04's erasure-request door ("erasure requests only *initiate* here —
approval and execution are Doc 17's workflow"; drawn as B4-S07 H06) names no
permission, and the catalog is CLOSED at 113 (NFR-03). RULED: the initiation door
rides **`customers.delete`** — the only erasure-shaped key in the closed catalog, and
since nothing is ever hard-deleted (BR-05, §21), "delete" in this product means
precisely "set erasure in motion". BOTH CONSEQUENCES STATED PLAINLY, per the ruling:
(1) the key authorizes INITIATING a request only — Doc 17 owns approval and
execution; the door writes a request row + audit entry and deletes nothing (the H06
frame's own copy: "This does not delete anything"); (2) it is **Super-Admin-only by
default**, because R-10's ratified templates exclude `customers.delete` from Admin —
operators may grant it to a role explicitly (templates are editable, FR-02 of
Doc 03).

### A-39 (FILED INTERPRETATION, the Q9 shape). Unblock rides `customers.block`

Doc 08 §8's prose calls unblock "a distinct permissioned action with reason" while
the closed registry holds no `customers.unblock` key. Q9 governs: the registry is
normative, prose descriptive. Unblock rides **`customers.block`**; the distinctness
the prose names is delivered as the separate door (`POST …/unblock`), its own
mandatory reason, and its own audit action (`customer.unblocked` event +
audit-coverage row) — never a second key.

### Binding corrections folded at approval (advisor, blocking)

- **B1 — step 1 split into 1a/1b.** SCHEMA AMENDMENT 2 is filed and COUNTERSIGNED at
  the 1a mandatory stop BEFORE any migration ships; migrations, grant physics and the
  migration-level sabotages are 1b. Nothing touches the database before 1a returns.
- **B2 — legacy shared-phone backfill policy** (EC-02 is a documented reality, so the
  collision is expected, not hypothetical): when N customers share one
  `customers.phone` value, the phone identity goes to ONE deterministic keeper —
  oldest customer by `created_at`, tiebreak lowest UUIDv7; every loser's value is
  preserved (the deprecated `customers.phone` column survives the C-12 contract
  window) and the migration emits a shared-phone report enumerating every collision
  group for later human consolidation — FR-09's "humans consolidate" applied to
  legacy data. Proven by `IdentityBackfillTest` (two customers, one phone →
  deterministic completion, both values retrievable, pair reported).

### Stack growth approved (S08): ADR 0014 — `giggsey/libphonenumber-for-php`

Approved with three conditions the ADR must state: (a) the pinned version; (b) stored
values are normalized ONCE at write and never silently re-normalized by a metadata
upgrade; (c) the policy when a metadata upgrade would change an already-stored value.
The approval's stated ground: normalization correctness is a SAFETY property here —
BR-04's unique index assumes one phone normalizes one way forever, so an in-house
subset is the riskier option. Nothing else grows.

### S08 SCHEMA AMENDMENT 2 (filed at step 1a, BEFORE any migration exists — the B1 correction's split honored; needs advisor countersign)

The Doc 08 schema, complete: six items, every column R-19b-analyzed, ADR-0003
grant physics throughout, `APP_WRITABLE_COLUMNS` split-comment discipline, DB time
only (C-11), FORCED RLS + tenant_isolation + `REVOKE DELETE` on every new table.
Two interpretations are FLAGGED inline for the countersign (marked ⚑) rather than
chosen silently. No migration ships until this amendment returns countersigned.

**1. `customers` — column additions (FR-01, FR-03, FR-14, FR-18, FR-02).**

- `language` (text, nullable) + `language_source` (text CHECK detected|staff,
  nullable) — EC-10's sticky law lives in the profile service, marked by source.
- `country` (text ISO-3166-1 alpha-2, nullable) + `country_source` (text CHECK
  staff|geoip, nullable).
- `tags` (text[], NOT NULL default '{}', GIN) — the Doc 06/07 tag idiom.
- `rg_flag` (bool NOT NULL default false), `rg_flag_set_by` (uuid, nullable —
  null for AI-set), `rg_flag_reason` (text, nullable), `rg_flag_set_at`
  (timestamptz, nullable) — **PINNED-PROVENANCE**: a byte-copy of the owning
  `rg_flag_history` row's own DB-time `created_at`, never a fresh clock read
  (the history table is the authority; the customer columns are the current-state
  denormalization SEC-01's query-level gate covers). App-writable by that
  documented discipline; byte-equality with the history stamp proven by test.
- `blocked` (bool NOT NULL default false), `blocked_reason` (text, nullable),
  `blocked_by` (uuid, nullable), `blocked_at` (timestamptz, nullable) — same
  pinned-provenance treatment: byte-copy of the block operation's audit/event
  DB stamp.
- `merged_into_id` (uuid, nullable, self-FK) — FR-02's tombstone pointer; BR-05's
  redirect key; acyclic by the merge service's chain check (the Doc 06 A-13
  precedent).
- `created_source` (text CHECK telegram|webchat|staff|api, NOT NULL) — backfill
  by a MIGRATION-LOCAL static map from the creating identity's channel
  (telegram→telegram, fake→webchat); an unmapped channel FAILS the migration
  loudly. This map is migration-local on purpose — the RUNTIME mapping mechanism
  is escalation #1's ruling (options (a)/(b) below) and the backfill must not
  depend on it.
- Rollups (FR-03, event-maintained, recomputable): `tickets_count` (int NOT NULL
  default 0), `open_tickets` (int NOT NULL default 0), `satisfaction_avg`
  (numeric, nullable) + `satisfaction_count` (int NOT NULL default 0) — inputs
  fixture-fed until Doc 12 produces (the [D12] bucket) — and `last_contact_at`
  (timestamptz, nullable) — **PINNED-PROVENANCE**: byte-copy of the source
  message's `messages.created_at`, app-writable by documented discipline,
  byte-equality proven (the A-33/R-19b law; a wall-clock write here would let
  clock skew reorder "last contact" against the thread itself).
- ⚑ **FLAGGED INTERPRETATION — the repeat-contact input** (FR-03 names
  "repeat_contact_rate inputs" and defines them nowhere): proposed as ONE column,
  `repeat_contacts_count` (int NOT NULL default 0) — the rollup consumer counts a
  non-spam ticket (N-14 via the one MetricExclusion predicate) as a repeat when
  the same customer already had a ticket created within the preceding 30 days.
  The 30-day window is PROVISIONAL and so marked in the column comment: Doc 15's
  metric dictionary owns the formula (rate = repeat/tickets) and may redefine the
  window; the column is recomputable by the drift job, so a redefinition is a
  recompute, not a migration. Countersign confirms or adjusts.
- Deprecations opened (C-12 expand-contract): `customers.phone` and
  `customers.username` stop being written by the resolver the moment the new
  identity shape lands (phone becomes an identity type; username lives in the
  telegram identity's display + metadata history). Both columns SURVIVE through
  the contract window; the drop is GATED — see item 6.

**2. `customer_identities` — the evolution (FR-04/FR-05/FR-06, T-02).**
Expand: add `type` (text CHECK telegram|web|phone|email, nullable until enforce),
`value` (text, nullable until enforce), `source` (text CHECK
channel_auto|staff|api), `first_seen_at` (timestamptz — for backfilled rows a
byte-copy of the row's own `created_at`; for new rows filled by the writing
service from the statement's DB clock; not an ordering key, but provenance the
UI renders, so the copy discipline is documented), `metadata` (jsonb NOT NULL
default '{}' — username history {old, changed_at} per FR-06). Backfill: type/value
from (channel, external_user_id) via the migration-local map above; existing
`customers.username` copied into the telegram identity's metadata where absent.
Enforce: `type`/`value` NOT NULL + **UNIQUE (tenant_id, type, value)** (BR-04's
physics; AC-02's direct-SQL target; the resolver's ON CONFLICT arbiter). Legacy
`channel`/`external_user_id` columns deprecated (kept through the window, dropped
under item 6's same gate discipline).

**3. `customer_notes` (FR-17).** *(AMENDED at step 6, countersigned at the
step-6 acceptance — this amendment stays the single source for the grants:
the app UPDATE grant is `(customer_id, pinned, updated_at)`. The original
fence omitted `customer_id`; the merge's first execution was refused by the
grant itself (42501) because FR-12 re-points notes and FS-02 re-points them
back. Content stays fenced (`body`/`author_id`/`created_at`/`id`/`tenant_id`
denied). The surrendered physics is replaced by a PROVEN BOUNDARY: the
CustomerGrantPhysicsTest architecture assertion that customer_notes.customer_id
is written only by the merge machinery.)* Original filing:  `id` (UUIDv7), `tenant_id`, `customer_id` (FK),
`author_id` (uuid NOT NULL — notes always have a human author), `body` (text
NOT NULL), `pinned` (bool NOT NULL default false; partial UNIQUE
(tenant_id, customer_id) WHERE pinned — FR-17's "pinned note (one)"),
`created_at` — **ORDERING-RELEVANT** (notes render newest-first):
clock_timestamp() default + the R-19a column grant (app INSERT-denied on it),
`updated_at` maintenance. N-04: no adapter path exists from this table; the
structural-undeliverability test (sabotage #15's subject) asserts it.

**4. `customer_merge_snapshots` (FR-13, T-03).** Named with the `customer_`
prefix because Doc 06's `ticket_merge_snapshots` already claims the namespace —
T-01's bare "merge_snapshots" is read as prose (Q9), the schema takes the
collision-free name, stated here so it is a filed reading, not drift. Columns:
`id`, `tenant_id`, `survivor_id`, `merged_id`, `payload` (jsonb — the FULL
re-pointing map: every moved conversation/ticket/file/note/identity id, the
field diff with chosen values, pre-merge field values, armed-timer state —
AC-03's byte-identical bar is only reachable if this payload is exhaustive, so
the payload schema is asserted by test, not convention), `expires_at`
(timestamptz NOT NULL — creation stamp + 30 days, computed in SQL from the same
statement clock), `undone_at` (timestamptz, nullable), `created_at` —
**ORDERING-RELEVANT** (EC-04's outermost-first chain order is determined by
snapshot recency): clock_timestamp() default + R-19a grant. Grant shape: app
UPDATE restricted by column list to `undone_at` alone (the one legal mutation);
REVOKE DELETE (pruning after expiry runs as platform role — the
TicketMergeSnapshotsPrune precedent).

**5. `rg_flag_history` (T-05, FR-15, AC-05, AC-B4-02).** `id`, `tenant_id`,
`customer_id`, `action` (text CHECK set|cleared), `source` (text CHECK
staff|ai), `actor_id` (uuid, nullable — null exactly when source=ai),
`reason` (text NOT NULL), `excerpt_ref` (jsonb, nullable — the AI triggering
excerpt's message reference, [D09]-fed), `created_at` — **ORDERING-RELEVANT**
(history renders and exports in order): clock_timestamp() default + R-19a grant.
**INSERT-ONLY BY GRANT: REVOKE UPDATE, DELETE** — AC-05's immutability and
AC-B4-02's "an export run twice a year apart still contains the same early
rows" as physics, not convention.

**6. `customer_identity_conflicts` — the backfill's PERMANENT HOME (correction
B2 + the advisor's Note 1).** `id`, `tenant_id`, `type` (text), `value` (text —
the shared value itself), `keeper_customer_id`, `loser_customer_id`,
`resolved_at` (timestamptz, nullable, app-writable — stamped when a human
consolidates through the FR-09/FR-10/merge doors), `created_at` (hygiene
default). REVOKE DELETE. One row per (value, loser); written by the backfill in
the same transaction that chooses the keeper (oldest `created_at`, tiebreak
lowest UUIDv7).

**THE NOTE-1 ANSWER, STATED FOR THE PERSON WHO WRITES THE DROP MIGRATION:**
the mechanism is **permanent home FIRST, gated drop as the verification
backstop — both.** (i) By construction, every loser value is preserved FOREVER
in `customer_identity_conflicts` at backfill time — the preservation does not
depend on the operator acting on any report. (ii) The future contract-window
migration that drops `customers.phone`/`customers.username` MUST run this check
and REFUSE loudly on failure: every non-null `customers.phone` value is either
present as a phone identity on that customer or recorded in
`customer_identity_conflicts`; every non-null `customers.username` is mirrored
in its telegram identity's display/metadata. A drop that cannot prove both
halves does not run. This paragraph is the constraint of record — the drop
migration cites THIS amendment, not a comment on the deprecation.
`IdentityBackfillTest` proves the whole policy: two customers, one phone →
deterministic keeper → both values retrievable → the conflict row exists → the
drop-gate check passes on the healthy state and REFUSES when a conflict row is
deleted from under it (the negative control).

**ESCALATION #1 — THE RUNTIME channel→identity-type MAPPING (the advisor's
Note 2; for ruling with this countersign, both options presented, neither
pre-committed):**

- **(a) `identityType()` on the ChannelAdapter contract.** Each adapter
  self-describes; drift structurally impossible. COSTS: a Doc 04 CORE-CONTRACT
  edit — every adapter (real + both fakes), the conformance suite gains a
  clause, and R-16's zero-core-edits proof is spent on a contract change.
- **(b) A Doc 08-owned registry** —
  `App\Domain\Customers\Identity\ChannelIdentityMap` (const MAP:
  telegram→telegram, fake→web, webchat→web reserved for Doc 13), consulted at
  the resolver boundary, with a test asserting (i) every channel the
  AdapterRegistry knows has a mapping and (ii) an unmapped channel THROWS at the
  resolver — the same fail-loud property, zero core edits. LAYERING: the
  identity taxonomy (telegram|web|phone|email) is Doc 08's domain model; (a)
  would put Doc 08's taxonomy on Doc 04's frozen contract — a dependency
  inversion. CONSEQUENCE IF (b) RULES: escalation #1's Doc 04 surface SHRINKS to
  the `CustomerResolver` file itself (rewritten in place per its own docblock's
  cession; signature `resolve(string $channel, InboundSender)` unchanged), and
  `NormalizeInbound` needs NO edit. The session's recommendation matches the
  advisor's stated lean: **(b)**.

Remaining step-1a items (the twelve catalog events, the AC-09 repo-wide test,
notification categories, traceability rows) land in the 1a completion commit
immediately after this countersign returns, before any 1b migration.

### COUNTERSIGN RECORD — SCHEMA AMENDMENT 2 (advisor, 2026-08-14, at `a23befe`; four rulings, each folded before any migration)

- **Ruling 1 → A-40 below** (escalation #1 resolves to option (b)).
- **Ruling 2 — `customer_identity_conflicts` gains the full grant physics**,
  superseding the item-6 text above: REVOKE DELETE from app (Doc 01 convention);
  resolution is a MARKER, never row removal — the column set gains `resolved_by`
  (uuid, nullable) beside `resolved_at`, and app UPDATE is COLUMN-GRANTED to
  exactly (`resolved_at`, `resolved_by`) — the `customer_merge_snapshots`
  pattern applied a second time. The IdentityBackfillTest negative control moves
  to MIGRATION level (the grant makes a live delete impossible — the stronger
  proof, the ledger row-7/row-10 precedent): sabotage edits the migration's
  grant, migrate:fresh, the drop-gate/physics test goes red, restore, green.
  Because it carries phone values, any door that ever reads this table sits
  inside the C-10 404-pattern + curated-payload-allowlist floor.
- **Ruling 3 — the repeat-contact input is renamed to carry its own window:**
  `contacts_30d_count` (tickets by this customer created in the trailing 30
  days, N-14-excluded, recomputable). The provisional window lives in the NAME,
  never wearing Doc 15's metric name — Doc 15 owns `repeat_contact_rate` and its
  four-surface parity; Doc 08 stores a recomputable input. A **[D15] bucket** is
  added to the approved plan's closure table (marked as this ruling's amendment)
  and this row lives in it. CONFIRMED against US-01: the panel renders "how
  often they contact us" as a COUNT ("14 tickets · 2 open" — the B4-S01 frame),
  never a computed rate; the directory's satisfaction column is Doc 12-fed and
  no Doc 08 surface computes a rate.
- **Ruling 4 — `customer_merge_snapshots` approved**; recorded in BUILD-STATUS
  as a NAMED DEVIATION from the approved plan's `merge_snapshots` text (reason:
  Doc 06's `ticket_merge_snapshots` claims the namespace; symmetry beats
  matching the plan's text; the plan stays honest by naming its own edits).

### A-40 (RULED at the SCHEMA AMENDMENT 2 countersign). The runtime channel→identity-type mapping is a Doc 08-owned registry — option (b)

`App\Domain\Customers\Identity\ChannelIdentityMap` (telegram→telegram,
fake→web, webchat→web reserved for Doc 13), consulted at the resolver boundary,
with the fail-loud pair: every channel the AdapterRegistry knows must have a
mapping, and an unmapped channel THROWS at the resolver. REASONS OF RECORD:
layering (the identity taxonomy telegram|web|phone|email is Doc 08's domain
model — putting it on Doc 04's frozen contract points the dependency the wrong
way); R-16's zero-core-edits proof is a finite project asset nothing here
requires spending; blast radius (under (b) the Doc 04 surface shrinks to
`CustomerResolver` alone — `resolve(string $channel, InboundSender)` unchanged,
`NormalizeInbound` untouched); drift risk equal either way because the
fail-loud test carries it. **Option (a) recorded so the choice is visible, not
defaulted into:** `identityType()` on the ChannelAdapter contract —
self-describing adapters, drift structurally impossible — rejected at the cost
of a core-contract edit touching every adapter and the 32-combination
conformance suite.

### A-41 (FILED at S08 step 2, per the step-1 checkpoint's ruling 6). The S08 migrations' platform-role use is a SCOPED interpretation, not a precedent

Doc 01 SEC-02 names two sanctioned uses of `platform_admin`: the platform
console service account and retention jobs. The S08 backfill and enforce
migrations are a third: **one-time, migration-local TENANT ENUMERATION only**
— `FORCE ROW LEVEL SECURITY` polices the migrator (ADR 0003 working as
designed), so a data migration that must visit every tenant cannot list them
any other way. The scope is exactly `SELECT id FROM tenants`; every WRITE then
runs on the migration connection under each tenant's own RLS binding; the
actor is logged (`s08.enforce: tenant enumeration via platform role`, with
the migration name and this filing's scope line). **This filing is the fence:
a future document citing these migrations as precedent for anything broader
than migration-local tenant enumeration is widening a deliberately narrow
surface and needs its own ruling** (the checkpoint ruling's own words).

### SABOTAGE-LIST AMENDMENT 15→16 (filed at S08 step 2, BEFORE the sabotage runs — standing rule 16; the step-1 checkpoint's ruling 7)

**Item 16 — the pinned-provenance trio's copy discipline broken:** a writer of
`customers.last_contact_at` (source: the message's own `messages.created_at`),
`customers.rg_flag_set_at` (source: the owning `rg_flag_history` row's
`created_at`), or `customers.blocked_at` (source: the block operation's audit
stamp) switched to an app clock (`now()`) → that writer's **inverted-R-19
byte-equality control** (the Doc 05 shape: assert the written value ===
the source stamp, never a fresh clock) goes red → restored. The three
controls land WITH their writers (rollups step 3; RG step 4; blocking
step 7) and each executes this one amendment's sabotage at its own step;
the ledger reconciles the item once with three execution citations.

### SCHEMA AMENDMENT 2 — BACKFILL RIDER (filed at S08 step 3, BEFORE shipping; cites the step-2 acceptance ruling)

The refuse-loudly clause FIRED on the real dev database: 262 customers hold no
channel identity — they are the S07 browser-seeded customers, minted by the
seeders directly (customer + conversation inserts) rather than through the
resolver, so identity-derivation finds nothing. The step-2 acceptance ruling
states the fiction for exactly these rows: "in the fiction it was created by
the resolver on first inbound — that is channel_auto." RIDER: the backfill
gains a SECOND derivation pass — a customer with no identity but WITH
conversations derives `created_source` from its OLDEST conversation's channel
(telegram → telegram; the fake/webchat family → webchat; an unmapped
conversation channel refuses loudly). Identities are NOT minted from
conversations (a conversation's external_chat_id is not a user id — inventing
identity values is exactly what the backfill must never do; a zero-identity
profile is legal, EC-05). A customer with neither identities nor
conversations still refuses loudly — truly unknown provenance stays a human
question. Proven by two new IdentityBackfillTest clauses.

### A-42 (FILED INTERPRETATION at S08 step 5; needs advisor countersign). FR-10's conversation anchor is derived from CHANNEL PHYSICS — no schema addition

FR-10: "conversations/tickets tied to that identity's conversations move with
it — conversation ownership follows the identity that anchors it." The schema
stores no identity_id on conversations (a Doc 04 column would be a core
edit), and Doc 08 T-01 adds none — the document presumes the linkage without
naming its carrier. INTERPRETATION: the anchor DERIVES — a conversation is
anchored by the channel identity whose external user id equals the
conversation's external_chat_id on the same channel. BASIS: Telegram
private-chat physics — for the Business and direct surfaces Doc 05 built,
the chat id of a private conversation IS the user id, so the equality is the
channel's own fact, not a heuristic; Doc 13's web identities anchor by
visitor id through the same equality when they land. CONSEQUENCE, stated:
phone/email identities anchor NO conversations in v1 — no channel produces
them (SMS is notification-only, N-15) — so unlinking one moves the identity
alone; the B4-S03 C03 frame's fiction of "5 tickets that came through the
phone" cannot occur in v1 data, and AC-04's law makes the typed confirmation
render whatever the move-set query actually answers, so the surface stays
honest whichever way the fiction reads (noted for the step-11 frame mapping,
not a conflict — the confirmation is COMPUTED, per the document's own AC-04).
Implemented in UnlinkMoveSet, whose docblock cites this record.

### COUNTERSIGN RECORD — A-42 (advisor, 2026-08-14, at the step-5 acceptance; three conditions, each discharged at the step-6 commit)

- **Condition 1 — the reconciliation with the step-3 rider, stated inside this
  record:** the rider says "a chat id is not a user id" (never mint identities
  from conversations); A-42 says "Telegram private-chat chat-id == user-id"
  (anchor derivation). THE ASYMMETRY IS THE WHOLE RULING: a MATCH against an
  EXISTING identity is EVIDENCE — that identity was already minted through a
  path that established it, and the equality merely finds its conversations.
  MINTING an identity from a chat id is INFERENCE — nothing guarantees a given
  conversation is a private chat rather than a group, and an inferred identity
  is exactly the silent link BR-02 forbids. Matching is safe where minting is
  not; the two claims govern different acts and both stand.
- **Condition 2 — v1 scope and the reopeners, named:** "phone/email anchor
  nothing" is true TODAY only because no conversation arrives on those
  channels — SMS is notification-only (Doc 14) and webchat carries its own
  visitor identity (Doc 13). **Doc 13 and Doc 14 are A-42's REOPENERS**: the
  session that lands either one re-derives this anchor rule against its new
  channel before building on it. "Phone anchors nothing" is a v1 fact, not
  permanent law.
- **Condition 3 — the enumeration PROVEN, not claimed:** a repo-wide sweep
  across ALL file types (every `Customer::create`/`firstOrCreate`, raw
  `INSERT INTO customers`, and `table('customers')->insert` shape, no
  extension filter) finds every executable creation site carrying
  `created_source`, with exactly two sanctioned exceptions: a frozen S07
  evidence log (not executable) and `legacyCustomer()` in tests/Pest.php —
  the DELIBERATELY pre-enforce fixture that exists only inside the
  migrator-connection toggle. The three-surface enumeration (seeders → PHP
  fixtures → spec-embedded tinker) is therefore COMPLETE as of this sweep;
  captured in the step-6 commit.

### RULING-1 FILING NOTE (the step-5 acceptance's rider, on the record): the volume sweep ran on RECOMPUTE deliberately

The §12-budgeted figure is the HANDLER's; the swept figure is RECOMPUTE's —
and that was the CONSERVATIVE choice, stated here so a future reader need not
guess: recompute IS the handler's only volume-dependent part (the handler
wraps it with an O(1) event fetch + customer resolution), so sweeping
recompute sweeps everything in the handler that CAN scale. A flat recompute
p50 across 64× volume bounds the handler's own volume behavior from above.

### SCHEMA AMENDMENT 2 — GRANT DELTA (filed at S08 step 6, BEFORE the migration ships; needs advisor countersign at the checkpoint)

The countersigned analysis fenced `customer_notes` UPDATE to
`(pinned, updated_at)` — "pinning is FR-17's one named mutation." The merge
saga's first execution proved the fence one column too narrow, BY THE GRANT
REFUSING THE MERGE ITSELF (42501 in MergeFlowTest): **FR-12 re-points notes
to the survivor and FR-13/FS-02 re-point them back** — `customer_id` is a
document-required mutation of this table. DELTA: the app UPDATE grant becomes
`(customer_id, pinned, updated_at)`; `body`, `author_id`, `created_at`,
`id`, `tenant_id` stay denied — the original reasoning stands for CONTENT
(no note-body edit has a document line). Shipped as a NEW migration (a
shipped migration is never edited); the CustomerGrantPhysicsTest denied-set
moves in the same commit, which is that guard's job.

### SCHEMA AMENDMENT 2 — ADDITION 7 (filed at S08 step 7, BEFORE shipping; needs advisor countersign at the checkpoint). `customer_block_cascade_items` — the cascade's restore memory

FR-18: "unblock restores conversation states" and F04's frame: "their 4
conversations RETURN TO THE STATE THEY WERE IN." Conversations are binary
(active|blocked, no reason column — Doc 04's schema), so restore needs ONE
fact the platform does not durably hold: WHICH conversations the CUSTOMER
cascade blocked, versus those already blocked individually (Doc 04's
per-conversation block "remains available" per FR-18's own last sentence —
those must SURVIVE a customer unblock). Audit rows are append-only truth but
not a control-flow source (house norm), so the memory is a table:
`customer_block_cascade_items` — `id`, `tenant_id`, `customer_id`,
`conversation_id`, `restored_at` (nullable), `created_at` (hygiene). R-19b:
no ordering key or metric reads it. Grant physics (the marker pattern's THIRD
application): INSERT column-granted (`restored_at` excluded — an item is born
unrestored), UPDATE granted to exactly `restored_at`, DELETE revoked;
FORCED RLS + tenant_isolation; UNIQUE (tenant_id, customer_id,
conversation_id) so the idempotent cascade cannot double-record. The cascade
consumer inserts one row per conversation IT blocks; the unblock cascade
restores exactly the unrestored items and stamps them.

### SCHEMA AMENDMENT 2 — ADDITION 8 (filed at the S08 step-8 checkpoint; APPROVED by the advisor at that checkpoint with four conditions, all discharged in the landing commit). Drop `customer_identities_match_unique` — the legacy index that breaks T-02's arbiter guarantee

THE DEFECT (found by a single kept red in the step-8 canonical run, capture
`docs/evidence/S08-step8/step8-canonical-01.txt`): `ON CONFLICT` with a named
arbiter handles conflicts on THAT index only. The legacy unique index
`(tenant_id, channel, external_user_id)` predates the amendment-2 evolution
and still stands beside the countersigned `(tenant_id, type, value)` arbiter.
A race loser whose arbiter pre-check runs before the winner's commit (the
speculative-insertion window) detects the physical conflict on whichever
index raises first — the legacy one, being older, can raise 23505 RAW,
bypassing the graceful RaceLost path entirely. T-02's "race safety is a DB
property" is breached in that window; `LinkIdentity`'s upsert shares the
exposure.

THE CHANGE — **SCOPE STATED EXPLICITLY (condition 4): this drops the INDEX
only.** The deprecated `channel` / `external_user_id` COLUMNS, their C-12
contract window, and `DeprecatedColumnDropGate` are UNTOUCHED — this is not
the column drop and must not be mistaken for it; the columns still drop two
releases after deprecation through the existing gate.

SUBSUMPTION, PROVEN NOT ASSERTED (condition 1): every writer mirrors the
pairs — the resolver and LinkIdentity write `external_user_id = value` and
`channel` equal to the value `ChannelIdentityMap` maps to the row's `type`
(telegram→telegram, fake→web; staff link: channel = type for phone/email) —
so a legacy-pair collision implies an arbiter-pair collision and the legacy
index constrains nothing the arbiter does not. Checked twice: (a) the DROP
MIGRATION itself refuses to run if any existing row diverges
(`external_user_id IS DISTINCT FROM value` on rows carrying the legacy pair)
— the data check travels with the migration to every database it ever runs
on, including the backfill-written dev rows; (b) a standing test asserts the
mirror invariant on writer-created rows.

DETERMINISTIC PROOF (condition 2, the raced-withdraw technique's sibling): a
forked competitor inserts the same identity in an OPEN transaction and holds
it with pg_sleep before committing; the resolver then races into the held
window — its arbiter pre-check sees nothing (uncommitted competitor), the
speculative insert blocks on the competitor's index entry, and on the
competitor's commit the conflict materializes. BEFORE the drop: raw 23505
naming the legacy index (captured red). AFTER: the arbiter takes it —
RaceLost → winner adopted, same customer id, no exception (the standing
green regression proof).

MECHANICS (condition 3): a NEW migration, never editing a shipped one;
R-19b: no ordering key or metric reads the index; grant physics unchanged
(an index drop moves no column grants). This addition lives IN SCHEMA
AMENDMENT 2 (the amendment stays the single source).

### SCHEMA AMENDMENT 2 — ADDITION 9 (filed at S08 step 9, BEFORE any migration exists; needs advisor countersign at the checkpoint). `customer_field_values` — FR-16's value storage

FR-16: the betting fields (`betting_username`, `player_id`, `brand`) are a
custom-field SEEDED SET — Doc 12 owns definitions ([D12]; the defs are
fixture-fed here), Doc 08 stores and renders the VALUES for
entity=customer, free-editable with history, "staff-collected" labeled in
every render. The countersigned amendment's five deliverables carry no
value storage; without it FR-16, SEC-05's duplicate-player_id hint, and
EC-09's retired-field render have nowhere to stand.

THE TABLE: `customer_field_values` — `id` (UUIDv7 PK), `tenant_id`
(non-null, C-02), `customer_id` (non-null), `field_key` (non-null text —
the Doc 12 definition key; the seeded set's keys come from the fixture),
`value` (text, nullable — a cleared field keeps its row so EC-09 can render
a retired field's last value), `updated_by` (nullable uuid — staff actor),
`created_at`/`updated_at` (now() hygiene). UNIQUE `(tenant_id, customer_id,
field_key)` — one current value per field per customer; the EDIT HISTORY
lives in audit_log old/new pairs (`customer.fields_changed` entries), never
in a second table — FR-16's "free-editable with history" reads on the audit
trail, the same reading as FR-01's profile attributes.

R-19b: no ordering key or metric reads this table; no pinned-provenance
copy needed (values are staff-collected facts, not event-ordered state).
INDEX `(tenant_id, field_key, value)` for SEC-05's duplicate-player_id
COUNT — the hint is counts + scope-checked links, never names (the count
query is index-only; the link-out list rides CustomerScope).

COUNTERSIGN CONDITION 1 — THE HISTORY QUESTION, CHECKED AGAINST THE FROZEN
FILES BEFORE THE MIGRATION (the check and its result, not the conclusion
alone): `B4-S01-customer-panel-v1.dc.html` and `B4-S02-profile-page-v1.dc.html`
were swept for every history mention. Findings: the "History" heading in the
panel order is the ROLLUPS section (its card reads "14 tickets · 2 open /
4.6 of 5 · 9 ratings / 2h since last contact"); the only history AFFORDANCES
drawn anywhere are the identity rows' "Username history" popover (frame A03,
§9's pattern) and the profile page's "rating history". The betting-fields
card, in BOTH files and all its viewports, draws: values, the
"Staff-collected" micro-label, the told-to-us micro-copy, the
duplicate-player_id chip, and (B4-S02) the retired field — NO edit-history
affordance for any agent tier. THEREFORE the audit-only history reading
STANDS: nothing renders a history list an ordinary agent could reach, so
audit_log (audit.view-gated, per Doc 03 Appendix B outside the agent tier)
is a lawful home for FR-16's "with history". REOPENER: if a later batch
draws a betting-field history affordance for agents, the values need a
panel-readable history at THAT point — this condition's check is the record
a step-11 author consults first.

COUNTERSIGN CONDITION 2 — THE TYPE DECISION AND ITS REOPENER: `value text`
is a DELIBERATE choice for the seeded set (three string fields:
betting_username, player_id, brand). Doc 12 owns field definitions and may
define numeric/date/enum types; when it does, typed storage (or a typed
sidecar column set) is DOC 12's REOPENER on this table — a later author
should find this sentence, not the constraint, first.

COUNTERSIGN CONDITION 3 — THE JUSTIFICATION, STATED PRECISELY (correcting
the fused claim in the first filing): TWO separate facts. (a) EC-09 is
about a DEFINITION being retired — the row survives that regardless, and
keeps its last value ("the definition went, the fact stayed" — B4-S02's own
retired-field caption); no delete is involved. (b) CLEARED fields keep
their row with value NULL — a distinct choice made for churn visibility and
audit continuity (the old/new chain stays anchored to one row). DELETE is
revoked because NEITHER path needs it and grant physics beats discipline —
not "for EC-09" alone.

GRANT PHYSICS (ADR 0003): INSERT column-granted (id, tenant_id,
customer_id, field_key, value, updated_by, created_at, updated_at); UPDATE
granted to exactly (value, updated_by, updated_at) — key/customer/tenant
never move by app; DELETE REVOKED (a cleared value is value=NULL, EC-09's
retired render needs the row); FORCED RLS + tenant_isolation policy.
`APP_WRITABLE_COLUMNS` split-comment discipline in the migration.

Writer: `CustomerProfile`-adjacent `CustomerFieldValues` service — the ONE
door; produces `customer.fields_changed` (payload: field_keys — names only,
Q7) + `customer.fields_changed` audit entries with old/new; the
duplicate-player_id hint recomputes from truth on read (no denormalized
count to drift).

---

## S09 (Doc 09) — SCHEMA AMENDMENT 3 (filed at step 2a, BEFORE any migration exists — the B1-split law; needs advisor countersign)

The Doc 09 schema, complete: five tables + one extension, every column
R-19b-analyzed, ADR-0003 grant physics throughout, `APP_WRITABLE_COLUMNS`
split-comment discipline, DB time only (C-11), FORCED RLS + tenant_isolation +
`REVOKE DELETE` on every new table. Interpretations are FLAGGED inline (⚑) for the
countersign rather than chosen silently. No migration ships until this amendment
returns countersigned. pgvector's `CREATE EXTENSION` is migrator-owned DDL
(ADR 0015); the compose postgres image moves to `pgvector/pgvector:pg16` in the same
commit (drop-in Postgres 16 + the extension; no volume-format change).

⚑ **FLAG 0 — the "(Doc 01 schema)" reading.** FR-20 says "writes ai_decisions
(Doc 01 schema)". Doc 01 defines no such table (verified: zero hits in
Doc01-Platform-Foundation-Multitenancy.md and in the migrations). Reading: the
phrase names Doc 01's schema CONVENTIONS (UUIDv7, tenant_id, timestamptz UTC,
partitioning for high-volume tables, RLS, grant physics) — Q9's prose-descriptive
law. Countersign confirms or corrects.

**1. `ai_decisions` (FR-20, T-08, NFR-05, SEC-06, EC-05/EC-11, AC-03) — PARTITIONED
MONTHLY (the messages/events/audit precedent; high-volume by FR-21's own "sampled"
admission; joins partitions:check/maintain in the same commit).**

- `id` (UUIDv7, C-01), `tenant_id` (C-02).
- `surface` text CHECK (chatbot|copilot|supervisor); `task` text CHECK — the twelve
  FR-01 v1 task names, a closed enum grown only by migration.
- `provider` text NOT NULL, `model` text NOT NULL; `config_version_id` uuid nullable
  (the published `ai_config_versions` row consulted — FR-04's "every decision logs
  the exact config_version").
- `layer_hashes` jsonb NOT NULL — T-02: every prompt layer's version hash INCLUDING
  the immutable honesty layer's; the T-02 test asserts the immutable hash is present
  and matches the code-shipped layer.
- Join identifiers, all nullable uuids: `conversation_id`, `ticket_id`,
  `customer_id`, `message_id` (outcome enrichment joins on these + `id`; the handoff
  packet embeds decision_id per T-08). `endpoint_ref` text nullable (EC-07);
  `language` text nullable.
- Decision fields (FR-20): `intent` text nullable, `confidence` numeric nullable,
  `action` text nullable (the FR-08 closed enum + copilot/supervisor task outputs),
  `rg_risk` numeric nullable, `tokens_in` int NOT NULL default 0, `tokens_out` int
  NOT NULL default 0 (AC-10's reconcile needs both halves), `latency_ms` int
  NOT NULL default 0, `error_category` text nullable (closed reason categories:
  invalid_action, provider_refusal, timeout, quota_exhausted, …).
- `outcome` text nullable + `outcome_meta` jsonb nullable — the ENRICHMENT half,
  written after the fact by durable consumers (identifiers and categories only, Q7).
- Payload pair (SEC-06's 30-day schedule): `request_payload` jsonb nullable,
  `response_payload` jsonb nullable, `payload_hash` text nullable,
  `payloads_reduced_at` timestamptz nullable. The REDUCTION JOB (platform role — the
  Doc 01 SEC-02 retention-job class, its second sanctioned use, not a widening)
  nulls both payloads, stamps hash + reduced_at, daily, for rows older than 30 days.
- `created_at` — **ORDERING-RELEVANT** (the log renders in order; AC-03's simulation
  reads a day; weekly outputs window on it): clock_timestamp() default + the R-19a
  column grant (app INSERT and UPDATE both denied on it).
- GRANTS: app INSERT column-granted (everything EXCEPT `created_at`,
  `payload_hash`, `payloads_reduced_at` — the reduction columns are
  platform-role-only in BOTH directions); app UPDATE column-granted to exactly
  (`outcome`, `outcome_meta`, `updated_at`) — enrichment is the one legal mutation;
  REVOKE DELETE (retention = partition detach, platform work).
- ⚑ **FLAG 1 — sampled-event split**: `ai.decision.logged` is SAMPLED to the bus
  (FR-21); the table is the full-fidelity record. The sampling RATE is a registered
  platform setting (named per conventions at step 4), not a config-version record —
  it is operational, not operator policy. Countersign confirms.

**2. `ai_config_versions` (FR-04, FR-18, FR-02's routing, T-02's tenant layers,
T-06's RG config).** One table for every versioned operator-config kind.

- `id`, `tenant_id`; `kind` text CHECK (persona|thresholds|guardrails|
  provider_routing|prompt_template|rg_classifier); `key` text NOT NULL (persona
  name / endpoint_ref / task name; 'default' for singletons); `version` int NOT NULL.
- `payload` jsonb NOT NULL — **IMMUTABLE once written** (no UPDATE grant on it):
  FR-04's rollback correctness depends on old versions never mutating; a rollback
  REPUBLISHES an older payload as a NEW version row, so history is append-only in
  substance.
- `status` text CHECK (draft|published|superseded); `published_at` timestamptz
  nullable + `published_by` uuid nullable (stamped by the publish door);
  `created_by` uuid NOT NULL.
- UNIQUE (tenant_id, kind, key, version) + partial UNIQUE (tenant_id, kind, key)
  WHERE status = 'published' — exactly one active version per config. ⚑ **FLAG 2 —
  two unique indexes beside the PK**: the ON CONFLICT arbiter law (0h/addition-8
  class) is about upsert paths; NO writer upserts this table (plain INSERT +
  guarded status UPDATE inside one transaction), stated here so the next reader
  finds the reasoning, not the index count. Countersign confirms.
- `created_at` — hygiene (version int is the ordering key, not the clock):
  clock_timestamp() default, app INSERT-denied on it (the R-19b default posture for
  new tables).
- GRANTS: app INSERT column-granted (minus created_at); app UPDATE column-granted to
  exactly (`status`, `published_at`, `published_by`, `updated_at`); REVOKE DELETE.
- `published_at` is display provenance, not an ordering key; written by the publish
  door from the SAME statement's DB clock (`clock_timestamp()` in the UPDATE), never
  an app clock — stated for R-19b completeness.

**3. `ai_kill_switches` (FR-18, US-07, AC-08, SEC-05).**

- `id`, `tenant_id`; `surface` text CHECK (chatbot|copilot|supervisor); `channel`
  text nullable (telegram|web; NULL = all channels); `endpoint_ref` text nullable
  (bot/widget id; NULL = all endpoints) — the scope grid, one row per combination.
- UNIQUE NULLS NOT DISTINCT (tenant_id, surface, channel, endpoint_ref) — PG16's
  form, so the NULL-scoped rows are singletons too.
- `is_off` bool NOT NULL default false; `reason` text nullable; `flipped_by` uuid
  nullable; `flipped_at` timestamptz nullable — **PINNED-PROVENANCE** (the S08
  blocked_at idiom): a byte-copy of the flip's own audit-entry DB stamp, never a
  fresh clock read (AC-08's "OFF since {time} by {actor}" caption renders it; an app
  clock here would let skew contradict the audit trail). The writer's
  inverted-R-19 byte-equality control lands with the writer (step 8) — the
  sabotage-16-class control, filed now.
- `created_at` hygiene (clock_timestamp() default, app INSERT-denied).
- GRANTS: app INSERT column-granted (minus created_at); UPDATE column-granted to
  exactly (`is_off`, `reason`, `flipped_by`, `flipped_at`, `updated_at`);
  REVOKE DELETE (a switch row is a scope's history anchor).

**4. `kb_chunks` (T-05, T-03's index-time stripping, FR-10; [D10] fixture-fed).**

- `id`, `tenant_id`; `article_ref` uuid NOT NULL — Doc 10's article id space,
  **NO FK** (the MembershipSource seam precedent; Doc 10 owns articles);
  `chunk_index` int NOT NULL; UNIQUE (tenant_id, article_ref, chunk_index).
- `language` text NOT NULL (T-05's language filter); `audience` text CHECK
  (public|internal) — FR-10: internal chunks retrievable for Copilot tasks ONLY,
  never the chatbot (the retriever enforces; the column carries the fact).
- ⚑ **FLAG 3 — `ai_usable` bool NOT NULL default false ON THE CHUNK**: FR-10 scopes
  retrieval to "categories the operator marked AI-usable", but the category model is
  Doc 10's and does not exist. The chunk-level flag is the honest interim (fixture
  seeding sets it; Doc 10's category flag re-derives it on arrival — a [D10]
  reopener stated here so the later author finds the sentence first). Countersign
  confirms.
- `content` text NOT NULL — instruction-stripped AT INDEX TIME (T-03's sanitizer;
  the stripping happens in the indexer, the column stores the stripped text);
  `provenance` jsonb NOT NULL (article ref, source version, stripped-pattern count —
  T-03's provenance requirement); `content_tsv` tsvector GENERATED (hybrid keyword
  half) + GIN index.
- `embedding` vector(1024) nullable (ADR 0018's bge-m3 dims; nullable so a chunk can
  exist awaiting its embedding) + HNSW index.
- `retrievable` bool NOT NULL default true — unpublish/tombstone (EC-06) FLIPS this,
  never deletes (Doc 01's nothing-hard-deletes posture); REVOKE DELETE enforces.
- `created_at` hygiene (retrieval never orders by it); app INSERT-denied on it.
- GRANTS: app INSERT column-granted (minus created_at); UPDATE column-granted to
  (`content`, `content_tsv` excluded — generated, `provenance`, `embedding`,
  `language`, `audience`, `ai_usable`, `retrievable`, `updated_at`) — re-indexing
  rewrites in place; REVOKE DELETE.

**5. `ai_ticket_summaries` (T-05's similar-tickets half; summaries fixture-fed until
the nightly summarize job lands).**

- `id`, `tenant_id`; `ticket_id` uuid NOT NULL, UNIQUE (tenant_id, ticket_id) — one
  living summary per resolved ticket, re-embedded nightly.
- `summary` text NOT NULL; `language` text NOT NULL; `embedding` vector(1024)
  nullable; `embedded_at` timestamptz nullable — maintenance metadata (job stamp,
  not ordering); `created_at` hygiene (app INSERT-denied).
- Doc 03 scope filtering happens AT QUERY TIME (T-05's own words) by joining ticket
  visibility — no scope columns are stored (a stored scope would go stale on
  reassignment; the S08 CustomerSearchIndexer taught this).
- GRANTS: app INSERT column-granted (minus created_at); UPDATE column-granted to
  (`summary`, `language`, `embedding`, `embedded_at`, `updated_at`); REVOKE DELETE.

**DELIBERATE NON-TABLES (stated so the countersign can refuse them if wrong):**

- **Calibration (EC-06, the [D09] close)**: consumed from `rg_flag_history` cleared
  rows JOINed with `ai_decisions` — no new table; the FR-20 RG precision review
  queue is a read model computed from the two existing stores.
- **Metering (T-07, NFR-06, AC-10)**: rides the EXISTING `Quota::AiTokensPerDay` +
  `tenant_quota_usage` reconcile machinery (Doc 01 FS-05) — consumed, never forked.
  Per-task token attribution lives on `ai_decisions` rows (tokens_in/out), which is
  what the AC-10 monthly reconcile aggregates. Cost = tokens × the rate-card config
  (a registered setting), computed at read.
- **Kill-switch/audit history**: the audit log IS the history (SEC-05
  high-visibility entries with actor + old/new); the switch table stores only
  current state + its provenance stamps.

## S10 (Doc 10) — SCHEMA AMENDMENT 4 (filed at step 2a, BEFORE any migration exists — the B1-split law; needs advisor countersign; STOP-A/STOP-B rulings of 2026-08-27 folded)

The Doc 10 schema, complete: SEVEN new tables + the countersigned delta to Doc 09's
`kb_chunks`. Every column R-19b-analyzed; ADR-0003 grant physics; FORCED RLS +
`tenant_isolation` + `REVOKE DELETE` on every table and every partition; DB time only
(C-11); `APP_WRITABLE_COLUMNS` split stated per table; interpretations FLAGGED (⚑) for
the countersign rather than chosen silently. No migration ships until this returns
countersigned. UUIDv7 ids, non-null `tenant_id` FK to `tenants`, `created_at`/`updated_at`
timestamptz(6) `clock_timestamp()` defaults, app INSERT-denied on `created_at` everywhere
(the R-19b posture) — stated once here, true for all tables below.

**1. `kb_categories` (FR-01, EC-03, A-43).** `parent_id` uuid nullable (self FK);
`depth` smallint NOT NULL CHECK (1..3) — materialized so the max-depth rule is physics;
`name` jsonb NOT NULL (per-language `{lang: text}`); `slug` text NOT NULL;
`description` jsonb; `position` int NOT NULL default 0 (the ordering key — an int, not a
clock); `icon` text nullable; `ai_usable` bool NOT NULL default false (FR-01 — the
category-level flag that RE-DERIVES the chunk interim, FLAG 3's reopener); `visibility_default`
text CHECK (public|internal); `deleted_at` timestamptz nullable (A-43 soft delete).
UNIQUE NULLS NOT DISTINCT (tenant_id, parent_id, slug). GRANTS: INSERT column-granted
(minus created_at); UPDATE (parent_id, depth, name, slug, description, position, icon,
ai_usable, visibility_default, deleted_at, updated_at); REVOKE DELETE.

**2. `kb_articles` (FR-02, BR-03, FR-04/BR-07, EC-11, A-43).** `category_id` uuid FK
NOT NULL; `audience` text NOT NULL CHECK (public|internal) — ARTICLE-level, one decision
per article (BR-03 is physics: no per-variant column exists to disagree); `source_language`
text NOT NULL (FR-03: the operator-marked source, per article — §14); `tags` jsonb
NOT NULL default '[]'; `related_article_ids` jsonb NOT NULL default '[]'; `seeded` bool
NOT NULL default false + `seeded_fingerprint` text nullable (BR-07: the RoleSeeder
fingerprint contract — editable, deletable, never overwritten once touched); `created_by`
uuid NOT NULL; `deleted_at` timestamptz nullable. GRANTS: INSERT (minus created_at);
UPDATE (category_id, audience, source_language, tags, related_article_ids,
seeded_fingerprint, deleted_at, updated_at); REVOKE DELETE. ⚑ **FLAG A — `audience` is
app-updatable** because EC-11's internal→public change is a real operation; the
high-visibility audit + full-pipeline requirement is enforced by the ONE write door
(step 4's `Visibility::change`) and its sabotage (#12: a plain UPDATE bypassing the
pipeline goes red on the audit + index assertions), not by a grant. Countersign confirms.

**3. `kb_article_variants` (FR-02, FR-03, FR-05, FR-07, FR-13/NFR-01 states, T-07, EC-12).**
`article_id` uuid FK NOT NULL; `language` text NOT NULL; `status` text NOT NULL CHECK
(draft|in_review|published|archived) — VARIANT-level workflow; `translation_state` text
NOT NULL CHECK (current|outdated|missing) default 'missing'; `translation_source` text
NOT NULL CHECK (human|ai) default 'human'; `title` text NOT NULL default ''; `slug` text
NOT NULL; `summary` text NOT NULL default '' (FR-02's summary line = chunk 0);
`working_copy` jsonb NOT NULL default '{}' (FR-05 autosave = working copy, not history;
EC-12 recovery point); `working_copy_saved_at` timestamptz nullable — display provenance
("restored unsaved changes from {time}"), stamped IN SQL by the autosave UPDATE's
`clock_timestamp()`, never an app clock; `current_version_id` uuid nullable;
`index_status` text NOT NULL CHECK (none|pending|current|failed) default 'none' (§8 error
state: "index pending" with retry); `retrieval_generation` int NOT NULL default 0 (the
generation the SWAP made current); `submitted_at`, `published_at` timestamptz nullable —
provenance, DB-stamped in the transition UPDATE. UNIQUE (tenant_id, article_id, language);
UNIQUE (tenant_id, language, slug) (per-language slugs, §14; EC-06 collision =
auto-suffix). GRANTS: INSERT (minus created_at); UPDATE (status, translation_state,
translation_source, title, slug, summary, working_copy, working_copy_saved_at,
current_version_id, index_status, retrieval_generation, submitted_at, published_at,
updated_at); REVOKE DELETE. Locks live in Redis `volatile` (T-07) — no lock columns.

**4. `kb_article_versions` (FR-08, AC-07, SEC-04, BR-06) — IMMUTABLE BY GRANT.**
`variant_id` uuid FK NOT NULL; `article_id` uuid NOT NULL; `version` int NOT NULL;
`language` text NOT NULL; `title`, `slug`, `summary` text NOT NULL; `content` jsonb
NOT NULL (the FULL snapshot, NFR-04); `content_hash` text NOT NULL (T-02 determinism:
same content → same hash → same chunks); `audience_at_publish` text NOT NULL CHECK
(public|internal) (forensics: what the customer could have seen); `author_id` uuid
NOT NULL; `note` text nullable; `sanitizer_flags` jsonb NOT NULL default '[]';
`sanitizer_override` jsonb nullable (`{by, at, reason}` — BR-06/SEC-04, written at
INSERT, never after); `published_at` timestamptz NOT NULL default clock_timestamp() —
ORDERING-RELEVANT for SS-04 ("what did the bot show on May 3") → app INSERT AND UPDATE
denied. UNIQUE (tenant_id, variant_id, version). GRANTS: INSERT column-granted (every
column EXCEPT created_at, published_at); **NO UPDATE GRANT AT ALL**; REVOKE DELETE.
AC-07's "immutable (DB-level)" is this table's physics; `KbGrantPhysicsTest` pins the
empty UPDATE set and sabotage #5 (an in-place content UPDATE) goes red at the grant.

**5. `kb_slug_history` (T-06, EC-06, FR-16) — APPEND-ONLY.** `article_id`, `variant_id`
uuid NOT NULL; `language` text NOT NULL; `slug` text NOT NULL; UNIQUE (tenant_id,
language, slug) (a slug ever used resolves forever to the variant that owned it; a NEW
owner of a retired slug is refused — EC-06's auto-suffix keeps both URLs working).
GRANTS: INSERT (minus created_at) ONLY; no UPDATE; REVOKE DELETE.

**6. `kb_review_comments` (FR-07, §8 review).** `variant_id` uuid FK NOT NULL;
`version_id` uuid nullable (the version under review); `block_anchor` text nullable;
`author_id` uuid NOT NULL; `body` text NOT NULL (SEC-03 inert-rendered); `resolved_at`
timestamptz nullable + `resolved_by` uuid nullable (DB-stamped in the resolving UPDATE);
`created_at` — **ORDERING-RELEVANT** (the thread renders in order) → clock_timestamp()
default + app INSERT/UPDATE denied. GRANTS: INSERT (minus created_at); UPDATE
(resolved_at, resolved_by, updated_at) — a comment's text never changes after posting
(accountability); REVOKE DELETE.

**7. `kb_feedback` (FR-11, FR-12, SEC-05, EC-10, AC-08).** `article_id`, `variant_id`
uuid NOT NULL; `source` text NOT NULL CHECK (widget|telegram|staff); `visitor_hash` text
nullable (a keyed hash of the Doc 04 visitor token / Telegram user id — never the raw
id; NULL for staff); `staff_id` uuid nullable; `score` smallint NOT NULL CHECK (score IN
(-1, 1)); `comment` text nullable (length-capped by the door: `kb.feedback.comment_max_chars`);
`actioned_at` timestamptz nullable + `actioned_by` uuid nullable (§8 "mark-actioned",
DB-stamped); `created_at` — **METRIC-RELEVANT** (AC-08 windows; EC-10 anomaly rate) →
app INSERT/UPDATE denied. CHECK ((visitor_hash IS NULL) <> (staff_id IS NULL)).
**Partial UNIQUE (tenant_id, variant_id, visitor_hash) WHERE visitor_hash IS NOT NULL —
EC-10's dedupe arbiter ("one score per visitor per article, latest wins").** ⚑ **FLAG B
(the amendment-3 FLAG-2 discipline, inverted): this index IS an ON CONFLICT target** —
the feedback writer upserts on it (latest wins = `ON CONFLICT … DO UPDATE SET score,
comment, updated_at`); it is the ONLY non-PK unique on the table, so the arbiter law
holds. Countersign confirms. GRANTS: INSERT (minus created_at); UPDATE (score, comment,
actioned_at, actioned_by, updated_at); REVOKE DELETE.

**8. `kb_chunks` — THE DOC 09 DELTA (STOP-A + STOP-B as ruled; every line below is a
Doc 09 core edit landing under this countersign).**

- **Shape:** the table is RECREATED as `PARTITION BY LIST (audience)` with exactly two
  partitions, `kb_chunks_public` FOR VALUES IN ('public') and `kb_chunks_internal` FOR
  VALUES IN ('internal') — index-level separation in the literal sense: each partition
  owns its OWN HNSW (`vector_cosine_ops`) and GIN (`content_tsv`) indexes. The chatbot's
  retrieval path (step 7, `PublicChunkSource`) reads `kb_chunks_public` BY TABLE NAME with
  no widening parameter anywhere on that path; Copilot reads the parent. Postgres requires
  the partition key inside every unique constraint, so: PRIMARY KEY (id, audience).
- **New columns:** `variant_id` uuid NOT NULL; `version_id` uuid NOT NULL (FR-14's
  version-pinned citation); `retrieval_generation` int NOT NULL (T-01); `heading_path`
  text NOT NULL default '' (FR-14/T-02); `sanitizer_flags` jsonb NOT NULL default '[]'
  (T-01's own list). `article_ref` STAYS (no FK — the seam law) and stays indexed.
- **The arbiter:** `UNIQUE (tenant_id, audience, variant_id, retrieval_generation,
  chunk_index)` — the ONLY non-PK unique. The old `kb_chunks_identity`
  (tenant, article_ref, chunk_index) is DROPPED WITH THIS AMENDMENT (STOP-A condition 3):
  it has no language and no generation, so `en`/`am` variants collided and two generations
  could not coexist. **FLAG-2 proof attached:** a static sweep asserts no `ON CONFLICT`
  anywhere in `app/` targets `kb_chunks` at all (KbIndexer's upsert is select-then-
  insert/update by design — verified in the source, `KbIndexer.php:54-63`), plus the
  `KbGrantPhysicsTest` pin that the ONLY unique indexes on `kb_chunks` are the PK and the
  new arbiter.
- **The swap (T-01, NFR-06, AC-03):** publish writes generation N+1 rows with
  `retrievable=false`; the swap is ONE statement inside the publish transaction —
  `UPDATE kb_chunks SET retrievable = (retrieval_generation = ?) WHERE tenant_id = ? AND
  variant_id = ?` — so no reader ever sees a mixed generation; `retrievable` alone encodes
  "current generation" and HybridRetriever's existing `where('retrievable', true)` already
  filters it (no extra query predicate needed — stated so the retriever packet stays
  minimal). Unpublish/archive/move-to-non-ai_usable flip all of a variant's rows to
  `retrievable=false` in-transaction (EC-02 = the same atomic path as unpublish).
- **"Delete old async" (STOP-A):** a PLATFORM-ROLE prune job `kb:chunks-prune` (Doc 01
  SEC-02's retention class; the S09 payload-reduction precedent; scoped by this filing to
  `kb_chunks` rows WHERE `retrievable = false AND retrieval_generation < (the variant's
  current generation − 1)` AND `version_id <> the variant's current_version_id`), daily.
  The app role NEVER deletes: `REVOKE DELETE` on the parent AND both partitions
  (STOP-A condition 5's proving test asserts the refusal under `app` and the prune under
  `platform_admin`).
- **Backfill (expand-contract inside step 2b, three migrations in dependency order, all
  in one release — no production data exists; countersign may order release separation):**
  (1) EXPAND — create the partitioned table as `kb_chunks_v2` with the new columns,
  partitions, RLS (parent + each partition: ENABLE + FORCE + `tenant_isolation`), grants
  (parent + partitions: INSERT column-granted minus created_at, UPDATE column-granted to
  (content, provenance, embedding, language, ai_usable, retrievable, heading_path,
  sanitizer_flags, version_id, retrieval_generation, updated_at) — `audience` and
  `variant_id` are NOT updatable: an audience change is a re-index into the other partition
  through the door (EC-11), never a row move; REVOKE DELETE on all three), and copy every
  existing row with `variant_id := article_ref`, `version_id := article_ref`,
  `retrieval_generation := 1` (fixture rows only — no seeder writes kb_chunks; verified);
  (2) SWAP — rename `kb_chunks` → `kb_chunks_legacy`, `kb_chunks_v2` → `kb_chunks`
  (partitions keep their names; indexes renamed to the canonical names); (3) CONTRACT —
  ⚑ **FLAG C:** `kb_chunks_legacy` is DROPPED in a later contract-phase migration carrying
  the `@expand-contract: contract-phase` marker, scheduled for the Doc 18 window (C-12's
  two-release rule honored literally even though the table holds no production rows).
  Countersign may order the drop now instead.
- **Grant-physics pins move in the SAME packet:** `AiGrantPhysicsTest::DOC09_DENIED_COLUMNS
  ['kb_chunks']` gains `audience`, `variant_id` in the UPDATE-denied set (a widened set is
  as red as a narrowed one — the test's own rule); `KbGrantPhysicsTest` pins the partitions.
- **Code packets riding this countersign (escalate-first, diffs shown at 2b before
  landing):** (i) `KbIndexer::index()` gains `variantId`, `versionId`, `generation`,
  `headingPath`, `sanitizerFlags` and upserts on the new arbiter; `retire()` flips by
  `variant_id`; a new `swap(variantId, generation)` is the ONE-statement door; (ii)
  `HybridRetriever` returns `version_id`, `heading_path`, `language` per chunk (FR-14) —
  no other change; (iii) `ChatbotTurn::renderAnswer` (step 7) renders the version-pinned
  citation URL through the FR-16 resolver.

**DELIBERATE NON-TABLES (so the countersign can refuse them if wrong):** locks (Redis
`volatile`, T-07); the retrieval cache (TenantCache, T-05); analytics aggregates (events
→ Doc 15; the stats header is a read model over `kb_feedback` + the events stream);
translation-state history (the audit log IS the history); `kb_media` — article images
are Doc 04 media items referenced by storage key inside `content` blocks (SEC-02: the
public-URL grant rides publish, not a table).

**Doc 10 settings registering with the schema (A-5 naming; platform-maximum validation):**
`kb.require_alt` bool true (tenant_editable); `kb.lock.heartbeat_seconds` int 90;
`kb.feedback.rate_per_hour` int 20; `kb.feedback.comment_max_chars` int 500 (max 2000);
`kb.render_cache_ttl_seconds` int 300 (max 300 — FS-03's cap is physics in the
validation rule); `kb.mega_article_warn_words` int 5000; `kb.chunk_target_tokens` int 500
(min 100, max 1000). None disables the one door, the alt gate or the sanitizer
(`FORBIDDEN_SETTING_PATTERNS` extended with `kb.*sanitizer*` and `kb.*indexer*` in the
same commit, so `registry:check` refuses such a key by mechanism).
