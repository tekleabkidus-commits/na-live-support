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
