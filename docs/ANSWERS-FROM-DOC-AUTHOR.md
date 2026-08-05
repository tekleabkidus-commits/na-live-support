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
