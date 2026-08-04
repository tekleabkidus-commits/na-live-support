# PROJECT HANDOFF v3 — Na Live Support
_Written at Doc 03 close (2026-08-04). Supersedes PROJECT-HANDOFF v2 (written at Doc 02 close).
This file + NEXT-ADVISOR-BOOTSTRAP.md + SESSION-PROMPT-SPEC.md + DESIGN-FIDELITY-MANDATE.md
(carried forward unchanged) are the complete four-file kit for resuming the process in a fresh
advisor chat. Commit all four to `docs/` in the repo — and VERIFY the commit landed: v2's
mandate was never committed and had to be recovered mid-Doc-03 (see §5, governance)._

---

## 1. What this is and how to use it

The owner (a non-programmer product manager) is building **Na Live Support** module-by-module
with three AI tracks: a **build track** (Claude Code terminal sessions, one per specification
document), a **design track** (Claude Design chats, currently dormant — program complete),
and an **advisor chat** (this role) that audits both, issues rulings, and writes the strict
session prompts. The advisor chat is replaced at each document boundary; this file is what
the replacement reads.

To resume: open a fresh Claude chat, attach this file, and paste NEXT-ADVISOR-BOOTSTRAP.md.
The new advisor's first real deliverable is SESSION-04 (the Doc 04 terminal prompt), written
per SESSION-PROMPT-SPEC.md.

## 2. The project and its main concept (the frame every fix must stay inside)

AI-first, white-label customer-support platform for betting companies. Ethiopia first:
English + Amharic, Telegram-dominant (Telegram Business connected-bot: player → support
account → bot → system), plus a web chat widget; SMS is notification-only, never a support
channel. Mobile-first for customer surfaces; desktop-primary agent app. Fully
operator-configurable from the Backoffice — nothing hardcoded, everything build-gated.

Non-negotiables (from the spec program; violating any is a concept change and only the owner
can rule on one): no customer identity verification ("staff-collected", never "verified");
AI grounding-or-handoff, never states transaction facts, always discloses it's AI, RG
responses template-only; Copilot never auto-sends; **internal notes structurally
undeliverable** (Doc 04 SEC-07 makes this type-level: a DeliverablePayload that notes cannot
produce); tickets never deleted (erasure workflow only); starvation cap and RG-routing
finality; spam/test traffic excluded from metrics; append-only audit; opt-out absolute; **no
impersonation feature, ever** (Doc 02 SEC-07, structurally swept — re-run green through
Doc 03's override machinery); tenancy isolation as physics (FORCE RLS + scope), not policy.

**Source of truth:** the 19-document specification series (docx in `docs/_source/`,
verified md in `docs/`) + `CLAUDE.md` (the constitution) are the ONLY law. The ~34-page
master design document is **permanently superseded** by the series (doc-author ruling Q1;
CONFLICTS.md gap closed). Prose counts anywhere are descriptive; **code registries are
normative** (doc-author ruling Q9) — proven again in Doc 03: §4.1 enumerates 111 keys, the
registry seeds exactly 111 (38 high-risk), and the 118/130 prose figures stay descriptive.

## 3. State of the build (all CI-proven; run IDs are the evidence)

| Phase | Status | Evidence |
|---|---|---|
| S0 bootstrap | DONE | Laravel 13.23, 14-service Docker stack (TLS end-to-end since Doc 02 S4), 7-stage CI, 11 blocking gates. Runs 30393265519, 30492124123 |
| Doc 01 Platform Foundation & Multitenancy | CLOSED | Final 69-id audit: 37 DONE / 30 PARTIAL (bucketed by unblocker) / 2 NOT DONE by design. Run 30613246335 on c76bf7c |
| Doc 02 Authentication, Sessions & Security Core | **CLOSED — COMPLETE.** FR-09 + SEC-05 closed in S19 with the real role resolver (TwoFactorRoleEnforcementTest; IpAllowlistTest + `auth.ip.blocked`) | Final 85-id audit: 61 DONE / 22 PARTIAL / 2 NOT DONE (S18). Close run 30850307901 on 66f2b63 |
| Doc 03 RBAC, Roles, Permissions & Overrides | **CLOSED** | Final 75-id audit: **59 DONE / 15 PARTIAL / 1 NOT DONE** (count corrected at acceptance — see §5 governance). Close run 30909832800 on 349cbf0; wrap-up run 30912681250 on 4ea891f. 12 commits efd496b…349cbf0 + 4ea891f; 11 sabotages proven; pest 513/7,481 assertions, gates 11/11, browser 32/32 |
| Docs 04–19 | NOT STARTED | Build order: 04 (fake adapter) → 06 → 05 → 07 → rest |

**The security core (Docs 01–03) is complete.** Doc 03 highlights, all falsification-proven:
the 111-key catalog with additively-extended registry schema (risk, description, scope
dimension) and the T-05(b) snapshot + T-05(c) template-drift never-rename gates; storage with
RLS+FORCE, one-role-per-user as column physics (no pivot), role soft-delete under ADR 0003's
DELETE-revoked grants, four-eyes self-approval refused by DB CHECK; the resolver as an
explicit rung sequence (User Deny > User Allow > Role > implicit deny) with expiry decided in
SQL against DB time; ScopeSets once per module, fail-closed everywhere (empty-membership and
missing-filter sabotages both red); capability cache `t:{tenant}:cap:{user}` TTL 10 s,
primitives-only entries (gadget-chain defense), R-04 bypass keys (rg_flag/audit always
fresh), composed staleness bound 10 s session / 20 s token; nine-template seeder through real
provisioning (Appendix B ratified, `docs/Doc03-AppendixB-role-templates.md`), EC-07
never-overwrite-edited incl. the dangerous quadrant; THE FLIP (RequirePermission
declare→enforce, stub deleted, TokenScopeGate untouched); last-admin guard on three paths;
four-eyes with the EC-08 deadlock guard; the four B6 screens on the first design-token
encoding (v2 §2 both colourways), T-04 per-page capability manifest, AC-08 DOM parity, AC-04
≤10 s propagation in the browser, axe light+dark+Amharic. Four real defects found by the
session's own tests (connection-per-worker exhaustion the nastiest). Sabotage #7's lesson is
culture: a guard-removal that stays green means the TEST was weak — strengthen the test
(the dangerous quadrant), keep the stronger one.

## 4. Inherited into Doc 04 (its plan table MUST claim every one of these as a row)

1. **Doc 03 FR-06's export + Reverb re-check consumers** — the mechanisms are built and
   mechanism-tested; Doc 04's transcript export (its FR-24, `conversations.transcript.export`)
   and Reverb channel auth (its T-06: Doc 03 scope enforced on `conversation.{id}` subscribe)
   are the FIRST real consumers. Doc 03's FR-06 row upgrades on their proof.
2. **Doc 03 EC-05 UI state** — "object moved out of scope → next action re-checks" needs real
   scoped objects; conversations are the first. (EC-02's mid-action-expiry UI naming the
   granter mostly needs ticket actions — verify against Doc 04's surfaces; what cannot land
   here carries to Doc 06 explicitly.)
3. **Doc 01 AC-03** — kill each worker class mid-burst → no lost messages/events after drain.
   NOT DONE since Doc 01 for lack of real traffic; Doc 04's pipelines are the traffic. Claim
   it or re-bucket it explicitly to Doc 06 with a reason; silence is a violation.
4. **Doc 01 BR-05 retention contract** — InboundAccrualPolicy + ColdSummaryStore were frozen
   at Doc 01 FOR Doc 04: suspended-tenant inbound is accepted/queued (nothing lost), stored
   in cold summary form past the threshold. Doc 04's inbound pipeline must consume the
   frozen contract, not reinvent it.
5. **Doc 03 SS-02's auditor drill** (four-layers-per-permission ×10) remains a demonstration
   script, not automated; SS-01's composite journey unscripted — standing honest PARTIALs;
   Doc 04's sessions must not re-claim them.
6. **Permission enforcement on the new routes**: `conversations.*` keys are already seeded
   and the permission-mapping gate is blocking — every Doc 04 route ships mapped in the same
   commit. Doc 02 AC-05's literal fixture stays PARTIAL until Doc 06's reply route (ruling
   Q5 + filed mapping R-12: `tickets.read` ≙ `tickets.view`; no key is ever invented).

Standing PARTIALs bucketed to other docs (do NOT let any session re-claim or silently drop
them): Doc 03 AC-03 real membership + queue pull-in grants → Doc 11; Doc 03 AC-08 pixel half
+ Doc 02 AC-09 pixel baselines → the owner's design exports; R-05's "≥3 admins" tip + the
teamless-warning drawn state → Docs 16/11; monthly high-risk report + SEC-03 audit view →
Docs 15/17/18; EC-08/T-07 widget-frame half → Doc 13; T-03/EC-03 new-device notice → Docs
07/14; T-08 production MMDB + vault migration + D-1 Redis eviction handoff → Doc 18; all
perf budgets → Doc 19 L1; Doc 01 SEC-04 → Doc 18.

## 5. Decision log (rulings in force; re-litigating any needs a new owner ruling + ADR)

**Interpretation record**: `docs/ANSWERS-FROM-DOC-AUTHOR.md` (+ owner-ruling annotations) is
where every doc-silence fill is filed — now including the doc-author Q1–Q9 section (merged
in S19 after the audit found the repo copy lacked it) and A-3 (FR-09 grace anchor). Where
that file and a numbered document requirement ever disagree, **the document wins and the
session STOPs and asks**.

Doc-author confirmations (Q1–Q9, filed): master doc superseded (Q1); `resolve_staff_login`
permanent v1 design (Q2); one reset token per matching account, uniform response, lockout
counts every candidate (Q3); bearer tokens never RLS-exempt, lookup-prefix routing (Q4);
SEC-06 literal in every environment (Q5); SEC-03 wins over stock Fortify (Q6); auth events =
the ONE synchronous audit path, bus payloads identifiers-only (Q7); AC-09 structurally
PARTIAL until baselines (Q8); registries normative over prose counts (Q9).

Owner rulings during Doc 02 (all standing): four SECURITY DEFINER doors as the permanent
ceiling; `ambiguous_tenant` reason; AC-01 timing across 0/1/N candidates; recovery banner
from `used_at IS NULL`; **efficiency ruling** (targeted tests while iterating, full
suite+gates+lint once per step-commit, push + CI watch at session close only; exceptions:
CI/infra changes and rulings naming CI); ADR 0007 mail supervisor family; help-copy escape
hatch in runbook only; T-01 deviation (Fortify drives, our persistence owns); FR-20's closed
three-code set; BR-04 DB-atomic disable; widget rulings D-1..D-5 (volatile visitor tokens +
Doc 18 eviction handoff; rotate-with-grace; mismatch = fresh visitor; header names;
allowlist matcher rules); webhook rulings 1–7 (volatile nonce cache; hash_equals +
functional sabotage; **replay-cache outage fails closed** — filed interpretation;
`webhook_rejected` single code; hmac_secret encrypted cast + vault handoff); scrubber
rulings (null/emergency channels exempt with pin test; seeded-value scan is THE guarantee;
gate fails closed); screens rulings (server-authoritative deadline; open-redirect fixtures
incl. protocol-relative; 401 sentinel staff-Inertia-only; enrolment OR-gate; avatar deferred
to Docs 04/08 media pipeline — **Doc 04 note**: the avatar seam lands in your media
pipeline; every new string leaves the enumeration suite untouched); `failOnFlakyTests` stays.

Owner rulings during Doc 03 (R-9…R-14, filed in the interpretation record):
- **R-9**: `admin.roles.approve` REJECTED — catalog stays closed at 111; four-eyes approval
  rights = holding `admin.roles.manage` / `admin.overrides.manage` with the distinct-person
  rule; the drawn key in B6-S10 is a filed class-3 design defect.
- **R-10**: Appendix B ratified (`docs/Doc03-AppendixB-role-templates.md`): agent-tier
  templates carry zero high-risk keys; Read Only = exactly 18 `.view`-suffix non-high keys
  (kb.view_internal excluded, audit.view excluded).
- **R-11**: role deletion = soft-delete under ADR 0003 grant physics; capability cache key
  `t:{tenant}:cap:{user}` per C-07 (T-02's literal read descriptive); `rbac.roles.soft_limit`
  platform-only.
- **R-12**: Doc 02 AC-05's `tickets.read` ≙ catalog `tickets.view`; the catalog governs; Doc
  06 builds the fixture with catalog keys.
- **R-13**: B6-S10's "recent decisions" OPTION frame excluded (the frame's own marking).
- **R-14 / A-3**: FR-09 grace anchor = when enforcement first applied to the user, stamped
  by middleware on first subject encounter (nullable, expand-contract).
- **High-risk count**: 38 per §4.1's text (an advisor prompt error said 20; corrected on the
  record — the "count from the text" instruction is what caught it).
- **Label-gray override, owner-ratified at acceptance**: Doc 03 §13's AA floor outranks the
  drawn #9A9588; #6F6A61 light / #A6A296 dark (system palette) stand until the design track
  re-issues the label gray. Precedent: S17 handled sub-4.5:1 the same way.

**Governance record (do not repeat these)**: (1) v2's handoff files were never committed to
the repo — the mandate's absence surfaced as a session blocker mid-Doc-03; the successor
verifies the four-file commit as its first act. (2) SESSION-03's launch ran in CONCURRENT
terminal sessions (a lost paste landed in a window presumed dead) — sessions overwrote each
other, suites collided; resolved by sole-survivor + solo re-proof of the step-0 commit
(ba014d9) + an incident note in BUILD-STATUS. Rule: before any session starts work, one
claude process only, verified. (3) The close-out summary's arithmetic (61/12/2) contradicted
its own table (59/15/1) — caught by the advisor's independent recount; ALWAYS recount the
table yourself. (4) The interpretation record must be checked for completeness at handoff —
a filed ruling that exists only in a chat or a docblock is not filed.

## 6. Standing rules (bind the advisor, the design track, and every terminal session)

1. Documents are law. Nothing against the 19-doc series/CLAUDE.md is accepted from anyone.
   Contradictions rejected and escalated to owner ruling + ADR. Silence-fills labeled
   interpretation and filed in the record (not only in code comments).
2. Fixes stay inside the main concept (§2). Concept-touching suggestions go to the owner as
   options with what they'd break — never as rulings.
3. No skipping, shortening, summarizing-away, or re-litigating. Settled = settled.
4. One fresh terminal session per document; **verify it is the ONLY session before work
   starts** (governance record #2); plan table first, WAIT for APPROVED; one commit per step.
5. Closure requires pasted executed output + a green CI run ID. A false DONE is a Sev-1.
   Sabotage-before-counting with a positive control for every assertion; a sabotage that
   stays green condemns the test, not the guard (S19 sabotage #7).
6. Advisor RULING UPDATEs reach the terminal before its next work message.
7. Paste-integrity checks on long pastes ("ends with 'Then STOP.'" + protocol-step count).
   Prefer read-from-committed-file delivery for session prompts (S19 practice): commit the
   prompt to `prompts/`, have the session read it from disk, verify last line + step count.
8. Old reports' claims are read against today's registry and repo. The advisor recounts
   every self-audit table independently before acceptance (governance record #3).
9. **Design fidelity is law** (DESIGN-FIDELITY-MANDATE.md, committed in `docs/`): 100%
   fidelity, tokens from the B1-DS lineage only, deviations only in the three named classes
   and every one filed in BUILD-STATUS's DESIGN-DEVIATIONS. Design-file delivery is now
   ON-DISK: the full frozen program lives at `design/frozen/` in the repo (committed
   2026-08-04); session prompts point at the exact frozen files there and the access wrapper
   restricts reading to them. Version rule (owner-adopted): screen/audit files — highest
   version is the frozen one; the design system is v2 as base PLUS all three addenda read
   together. A document-override of a drawn value (the label-gray pattern) is applied only
   with explicit owner ratification, filed in DESIGN-DEVIATIONS.

## 7. Design track state (dormant; owner routes everything)

The seven-batch program is complete and now archived in-repo at `design/frozen/` (219 files,
all versions; the frozen-version restriction is enforced at read time). PROGRAM-REPORT-v1
(.md alongside) is the authoritative map. Open design-track items, all owner's: the Amharic
destructive-tier clearance (now with Doc 03's priority list: role delete, override deny,
four-eyes reject, last-admin block); firing the AC-09 export (44 auth frames per
`B7-AC09-manifest.json`) and the Batch 6 export (unblocks Doc 03 AC-08's pixel half);
**re-issuing the label gray** (filed class-3, S19); the B6-S10 `admin.roles.approve` chip
correction (filed class-3, R-9); the earlier report items (B3-S08 apply button, B5 approval
chip, bulk-bar tooltip, Batches 5–7 acceptance files); the Batches 8+ scope decision for the
undesigned Docs 09/10/12/14/17 surfaces — when the build reaches one, the owner decides
design-first or functional-first; the class-2 gap rule does NOT cover whole undesigned
documents.

For Doc 04 specifically: most rendering belongs to Doc 07's inbox surfaces, but the B12
files (media lightbox v3, edit-marker v2) and parts of B1/B2 draw Doc-04-owned behaviors
(delivery glyphs, tombstones, media states). SESSION-PROMPT-SPEC's §B.8 requires the next
advisor to derive the exact file list from PROGRAM-REPORT-v1's inventory and verify against
Doc 04 §8/§9 which surfaces its session actually builds versus defers to Doc 07 — B12-A01's
nine behaviour-acceptance rows bind as executed tests when their surfaces are built.

## 8. Known traps for Doc 04 (bake these into SESSION-04)

- **FAKE adapter only.** No real channel this session (CLAUDE.md §7 order: 04 → 06 → 05).
  The adapter-contract conformance suite (Doc 04 T-09) runs the full matrix against a fake
  channel fixture — it is the CI gate later adapters (Docs 05/13) must pass. Any plan row
  touching Telegram/widget specifics is scope creep.
- **Notes are structurally undeliverable** (SEC-07): type-level — a DeliverablePayload that
  note kinds cannot produce; the test asserts no send path EXISTS, not that one is guarded.
- **Per-conversation FIFO lanes** (FR-15/T-02): Redis lanes, failed-retryable head blocks
  the lane, 10-min cap then lane-skip + warning event; lane fairness under a hot lane (§12).
- **No channel conditionals in core** — the S0 grep gate goes from dormant to load-bearing.
- **Ordering is receipt-time truth** (FR-07/EC-10): display order (created_at, uuidv7),
  channel clock skew normalized with the channel timestamp preserved; same-second-burst
  fixture; EC-05: `sent` stays truthful to what we knew when we knew it — no rollback.
- **Inbound discipline**: ACK ≤250 ms, never process inline; dedupe SETNX 48 h; identity via
  the Doc 08 resolver SEAM (match-or-create shell — merge rules are Doc 08's, do not build
  them); blocked-state quarantine with NO retro-fan-out on unblock.
- **Media is a security surface**: never delivered unscanned (EC-11 ClamAV-down holds then
  quarantines); streamed size enforcement; SSRF allowlisted egress; separate cookie-less
  media origin; infected = no download path for ANY role. Transcription/OCR are HOOKS only
  (events out, refs back) — AI behavior is Doc 09's.
- **Conversation ≠ ticket**: conversations are permanent per-customer-per-endpoint threads;
  tickets (Doc 06) are bounded ranges. Doc 04 must not grow ticket semantics.
- **Doc 01 contracts to consume, not reinvent**: BR-05's InboundAccrualPolicy/
  ColdSummaryStore (suspended-tenant accrual); the outbox for every event; monthly message
  partitions per Doc 01 conventions with repository-only writes (no ad-hoc Eloquent on
  partitions); retention + legal-hold (EC-09) hands its runbook to Doc 18.
- **Reverb subscribe enforces Doc 03 scope** (T-06) — this closes Doc 03 FR-06's Reverb
  half; the transcript export closes the export half. Both are inherited rows (§4.1–4.2).

## 9. Open items on the owner's desk

Firing the AC-09 baseline export AND the Batch 6 export (Doc 03 AC-08's pixel half waits on
it). Priority Amharic native review (Doc 03's destructive-tier list + the standing queue).
Design-track re-issues: label gray, B6-S10 approver chip. MaxMind license key (staging
GeoIP). The earlier design-report rulings (B3-S08 button, B5 chip, bulk-bar tooltip,
Batches 5–7 acceptance files, Batches 8+ scope). Later docs' prerequisites (BotFather +
Business account phone for Doc 05, SMS gateway sandbox for Doc 14, AI provider keys for
Doc 09) — Doc 05 is only two documents away; start lining up the dedicated phone.

## 10. Forward plan

Doc 04 (Conversations & Messaging Core, fake adapter) next, per the build order 04 → 06 →
05 → 07 → rest. Advisor writes SESSION-04 per SESSION-PROMPT-SPEC.md; owner commits it to
`prompts/` and starts a fresh, verified-solo terminal session that reads it from disk; plan
table back to the advisor for independent audit (including a recount of the session's id
enumeration); APPROVED with rulings; build under the efficiency ruling; close-out audited —
full self-audit table recounted — before acceptance. Repeat per document. First usable
milestone (agents serving players on Telegram) needs Docs 04, 06, 05, 07 plus a careful
Doc 09 first pass on top of the finished security core — the owner has been told ~20–25%
toward MVP as of Doc 03 close.
