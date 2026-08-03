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
