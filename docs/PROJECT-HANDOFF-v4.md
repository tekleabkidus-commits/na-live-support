# PROJECT HANDOFF v4 — Na Live Support
_Written at Doc 04 close (2026-08-05). Supersedes PROJECT-HANDOFF v3 (written at Doc 03 close).
This file + NEXT-ADVISOR-BOOTSTRAP.md + SESSION-PROMPT-SPEC.md + DESIGN-FIDELITY-MANDATE.md
(carried forward unchanged) are the complete four-file kit for resuming the process in a fresh
advisor chat. Commit all four to `docs/` in the repo — and VERIFY the commit landed: this check
has now caught a missing kit TWICE (v2's mandate; v3's three kit files at SESSION-04 launch)._

---

## 1. What this is and how to use it

The owner (a non-programmer product manager) is building **Na Live Support** module-by-module
with three AI tracks: a **build track** (Claude Code terminal sessions, one per specification
document), a **design track** (Claude Design chats, dormant — program complete), and an
**advisor chat** (this role) that audits both, issues rulings, and writes the strict session
prompts. The advisor chat is replaced at each document boundary; this file is what the
replacement reads.

To resume: open a fresh Claude chat, attach this file, and paste NEXT-ADVISOR-BOOTSTRAP.md.
The new advisor's first real deliverable is SESSION-06 (the Doc 06 terminal prompt), written
per SESSION-PROMPT-SPEC.md — **but its step 0 is R-18's gate, not the prompt** (see §5).

## 2. The project and its main concept (the frame every fix must stay inside)

AI-first, white-label customer-support platform for betting companies. Ethiopia first:
English + Amharic, Telegram-dominant (Telegram Business connected-bot), plus a web chat
widget; SMS is notification-only, never a support channel. Mobile-first for customer
surfaces; desktop-primary agent app. Fully operator-configurable from the Backoffice.

Non-negotiables (violating any is a concept change; only the owner rules on one): no customer
identity verification ("staff-collected", never "verified"); AI grounding-or-handoff, never
states transaction facts, always discloses it's AI, RG responses template-only; Copilot never
auto-sends; **internal notes structurally undeliverable** (Doc 04 SEC-07 — now BUILT as
type-level + runtime + DB CHECK, three layers, sabotage-proven); tickets never deleted
(erasure workflow only — Doc 06 BR-01: `tickets.delete` exists in the catalog but is
platform-disabled); **conversation ≠ ticket** (conversations are permanent threads, tickets
are bounded ranges over them — the boundary Doc 04 §3 held and Doc 06 now builds on);
starvation cap and RG-routing finality; spam/test traffic excluded from
metrics; append-only audit; opt-out absolute; **no impersonation feature, ever** (structural
sweep still green through Doc 04); tenancy isolation as physics (FORCE RLS + scope).

**Source of truth:** the 19-document series (docx in `docs/_source/`, verified md in `docs/`)
+ `CLAUDE.md` are the ONLY law. The master design document is permanently superseded (Q1).
Prose counts are descriptive; **code registries are normative** (Q9) — held again in Doc 04:
the plan's "16 events" figure reconciled with nothing and the enumerated catalog (20 events
landed) governed.

## 3. State of the build (all CI-proven; run IDs are the evidence)

| Phase | Status | Evidence |
|---|---|---|
| S0 bootstrap | DONE | Laravel 13.23, Docker stack (now incl. egress-proxy per ADR 0008), 7-stage CI, **12 blocking gates** (the adapter conformance suite is the 12th). Runs 30393265519 / 30492124123 |
| Doc 01 Platform Foundation | CLOSED | 69-id audit: 37 DONE / 30 PARTIAL / 2 NOT DONE by design. Run 30613246335. **Doc 01 AC-03 now CLAIMED in S20** (messages+events halves; tickets half → Doc 06) |
| Doc 02 Authentication | CLOSED — COMPLETE | 85-id audit: 61/22/2. Close run 30850307901. AC-05's literal fixture still PARTIAL → Doc 06 (Q5/R-12) |
| Doc 03 RBAC | CLOSED | 75-id audit: 59/15/1. Close run 30909832800; wrap-up 4ea891f. **FR-06 both halves now CLOSED by Doc 04's executed consumers** (transcript export; Reverb subscribe scope) |
| Doc 04 Conversations & Messaging Core | **CLOSED** | Final 96-id audit: **79 DONE / 17 PARTIAL / 0 NOT DONE** (recounted at acceptance, held). Close run 31051334145 on e05df89; wrap-up run 31053343339 on e0655a0. 12 sabotages proven (plan's 9 + 3 beyond); 2 real defects found by the drills (media lost on storage blip; redelivery-as-edit → 78 spurious revisions) — both fixed with positive controls |
| Docs 05–19 | NOT STARTED | Build order: **06 → 05 → 07 → rest** |

**The messaging engine is complete.** Doc 04 highlights, all falsification-proven: the
normative ChannelAdapter contract + conformance suite across 32 capability combinations as a
blocking CI gate (two fake adapters pass; zero core edits for the second — the US-05/SS-02
architecture proof); notes undeliverable at three layers; the delivery state machine (9 legal
edges, all 33 illegal pairs rejected; `sent` never rolls back — proven as edge-absence);
per-conversation FIFO lanes with head-block/cap/skip + fairness; receipt-time ordering with
channel timestamps as metadata; inbound discipline (ACK ≤250 ms never-inline, SETNX dedupe
48 h, identity SEAM to Doc 08, one-transaction persist+outbox, quarantine with no
retro-fan-out); the media security chain end to end (streamed size abort, ClamAV via sidecar,
EC-11 hold-then-quarantine, SSRF-allowlisted egress proxy, cookie-less MinIO origin with
single-tenant 10-min signed URLs, EXIF strip, infected = no path for any role); T-05
transcription/OCR hooks; drafts LWW with conflict toast; revisions/tombstones gated by
`conversations.history.full` with audited access; conversation-range transcript export
(watermarked, Amharic renders in PDF); Reverb v:1 ≤32 KB with emit-time tenant fence and
scope-on-subscribe; 20 closed-payload events; retention with legal-hold pre-drop copy-out;
FS-05 dead-letter parking with the ops re-drive view (the session's one class-2 design fill,
R-17); the node-kill replay drill (zero lost / zero duplicated across 1k).

**The one honest hole:** Doc 04 AC-03's 10k ordering soak is NOT proven — flaky (two green
runs, one red with 50 violations on a clean DB; not root-caused; excluded from `make test`;
CI ran it green once, which does not overturn a local red). See R-18 in §5 — it gates Doc 06.

## 4. Inherited into Doc 06 (its plan table MUST claim every one of these as a row)

0. **R-18's STEP 0 (blocking, before the plan):** root-cause the AC-03 soak
   (`LaneOrderingSoakTest` — verify its exact path in the repo; the captured evidence
   lives at `docs/evidence/S20-soak-isolation/`) to a deterministic result, reconcile
   `make test` with CI (most likely a dedicated CI job), and implement RISK 0b's
   capture-first protocol. **No Doc 06 plan may be approved before the soak is
   deterministic.** The next engineering session starts here even though it is Doc 04
   code. This is a section-grade obligation, not a checklist line: step 0's evidence is
   pasted and advisor-confirmed before the plan table is even produced.
1. **Doc 04 FR-24's ticket-range half**: transcript export for a ticket's message range,
   same gate (`conversations.transcript.export`), same watermark/audit path. Upgrades Doc
   04's FR-24 row on proof.
2. **Doc 02 AC-05's literal fixture** (Q5 + R-12): the 403-naming-the-missing-permission
   fixture built against Doc 06's real `tickets.reply` route with catalog keys
   (`tickets.read` ≙ `tickets.view`). Closes a PARTIAL standing since Doc 02.
3. **Doc 04 FR-10 step 6's routing/ticket fan-out consumer**: the `message.received`
   subscriber that drives FR-08 reopen-on-customer-reply, EC-01's cancel-wins race, and
   closed-beyond-window → new ticket with `previous_ticket_id`. Doc 04 emits; Doc 06 is the
   first real subscriber.
4. **Doc 04 FR-03's spam disposition**: Doc 06 FR-09 consumes the conversation-block seam —
   the spam dialog's separate, separately-confirmed block checkbox calls Doc 04's block,
   never its own mechanism.
5. **Doc 04 BR-05/FR-04's invariant, consumed not rebuilt — the DB refusal is ALREADY
   BUILT**: Doc 04 shipped and proved `open_ticket_id` + the `open_ticket_exists`
   rejection at app AND db layer. Doc 06's T-02 partial unique index and FR-10
   open-family definition must RECONCILE with that shipped enforcement (one invariant,
   two layers — the session states which layer owns what; split's `linked_work=true`
   cap-3 is the one sanctioned multi-open). Rebuilding it is a violation.
6. **Doc 01 AC-03's tickets remainder** (filed in the Doc 04 close): the node-kill drill
   reconciles "tickets/messages/events" — messages and events are claimed; the TICKETS
   count lands with Doc 06's transition pipeline. Extend the drill, cite the A-6-adjacent
   filing.
7. **Doc 03 EC-02's remainder**: mid-action permission expiry naming the granter — Doc 04
   verified it needs ticket actions; prove it on a Doc 06 transition.
8. **Doc 04 EC-04's rule trigger**: the queued→canceled transition for
   blocked-between-compose-and-send exists and is tested; the "ticket closed by rule"
   TRIGGER source wires through Doc 06's close/resolve events toward Doc 12's rule
   engine — the seam is claimed here explicitly, never left implicit.
9. **Route→permission mapping same-commit** (gate blocking): Doc 06 names its keys inline
   (`tickets.view/create/edit/assign/assign_self/priority.change/department.change/
   escalate/merge/split/follow/spam.mark/reopen/reply/export`) — all must already exist in
   the 111-key catalog; verify against the registry, never invent (NFR-03). The second
   advisor's audit counted **21 seeded `tickets.*` keys** — treat that as a claim to
   VERIFY against the registry, which is normative (Q9). `tickets.delete` stays
   platform-disabled (BR-01).
10. **Standing honest PARTIALs are not re-claimed**: Doc 03 SS-01/SS-02; Doc 04's SS-01
   (L2 profile → Doc 19), SS-02/US-05 (WhatsApp literal → §19), SS-04's literal-SIGKILL
   remainder (→ Doc 18 FR-23 staging drill), AC-01 re-proofs (→ Docs 05/13), AC-07/AC-08
   renderer halves (→ Docs 19/07), SEC-01 inert-rendering half (→ Doc 07), EC-08 UI
   (→ 07), NFR-06 budget doc (→ 18), R-03 lifecycle (→ 18). Doc 04's deferred B12-A01
   dispositions travel WITH their buckets — including AC-08's merge-chip half (lands
   Doc 06/07) and AC-09's bulk-progress-at-25% clause, which Doc 06 §13 also requires:
   each gets a stated disposition in the plan, none silently dropped.

Standing PARTIALs bucketed elsewhere (do not re-claim or drop): Doc 03 AC-03 membership →
Doc 11; pixel halves (Doc 03 AC-08, Doc 02 AC-09) → owner's design exports; R-05 admin tips →
Docs 16/11; high-risk report + SEC-03 audit view → Docs 15/17/18; widget-frame halves → Doc
13; new-device notice → Docs 07/14; MMDB/vault/Redis-eviction → Doc 18; perf → Doc 19; Doc 01
SEC-04 → Doc 18.

## 5. Decision log (rulings in force; re-litigating any needs a new owner ruling + ADR)

**Interpretation record**: `docs/ANSWERS-FROM-DOC-AUTHOR.md` is canonical — Q1–Q9, A-1…A-6,
R-9…R-18. Where it and a numbered requirement disagree, the document wins and the session
STOPs. Doc-author confirmations Q1–Q9 and the Doc 02 rulings stand exactly as v3 recorded
them (four SECURITY DEFINER doors ceiling; efficiency ruling — targeted tests while
iterating, full suite+gates+lint once per step-commit, push+CI at session close, exceptions
for CI/infra changes; widget D-1..D-5; webhook rulings 1–7; scrubber rulings; screens
rulings; `failOnFlakyTests` stays). Doc 03's R-9…R-14 stand (catalog closed at 111; Appendix
B; soft-delete; `tickets.read` ≙ `tickets.view`; B6-S10 exclusions; FR-09 grace anchor;
label-gray override).

New in Doc 04 (all filed):
- **R-15**: stack growth approved — ADR 0008 (egress-proxy service, flysystem-s3,
  intervention/image GD, lane workers per 0007 precedent, hand-rolled clamd client) and ADR
  0009 (dompdf + embedded Noto Sans Ethiopic).
- **R-16**: SS-02/US-05 claimed via the second fake adapter zero-core-edits proof; the
  WhatsApp literal re-proves if ever built (§19). Never a bare DONE.
- **R-17**: FS-05's ops re-drive view = the mandate's class-2 gap fill (no drawn file
  exists), design-system patterns only, filed in DESIGN-DEVIATIONS.
- **A-4**: document-required unnamed event keys — `note.mentioned`, `conversation.
  lane_skipped`, `channel.health.changed` (transition-only).
- **A-5**: FR-16 rate-bucket setting names under `messaging.*`.
- **A-6** (owner-ratified): FS-05 re-drive view permissions — list = `conversations.view`,
  re-drive = `tickets.reply` (FR-13's manual-retry precedent applied to the inbound side).
  No key invented. Stands unless the doc author overrules; only two middleware lines + the
  manifest entry would change.
- **R-18 (the gate)**: "Doc 04 accepted with AC-03 PARTIAL. Root-causing the AC-03 soak
  (deterministic result + make test/CI reconciled, most likely a dedicated CI job) and the
  RISK 0b capture protocol are STEP 0 of the next build session; no Doc 06 plan may be
  approved before the soak is deterministic. A flaky test is not a proof, and an ordering
  guarantee that is only probably true is not a guarantee."

**Governance record (do not repeat these)**: (1) v2's kit never committed → mid-session
blocker; (2) SESSION-03's concurrent launch → half a day lost; solo-process proof now
mandatory and it FIRED correctly at SESSION-04 launch (a stale morning session was caught
and killed before work); (3) Doc 03's close-out arithmetic error → the advisor recounts
every table (Doc 04's recount held: 79/17/0); (4) a ruling not in the record is not filed;
(5) **NEW — sabotage-ledger drift**: Doc 04's mid-build checkpoint claimed "eight of nine"
sabotages but they were not the PLAN's first eight — two of the plan's nine had never run
and were caught only by reconciling the ledger against the plan row-by-row at close. Audit
sabotage ledgers against the plan's list, never against a count. (6) **NEW — evidence
destroyed by pipes**: an intermittent failure's only log went through `grep`/`| tail -4`
and the detail was lost, twice. Standing order (RISK 0b): CAPTURE FIRST (full run redirected
to a file, committed under `docs/evidence/`), rerun second. (7) **NEW — parallel advisor
chats**: during Doc 04, the owner mistakenly issued the mid-build checkpoint audit and the
close-out audit from a SECOND advisor chat while the advisor of record held the plan audit
and rulings — the chain of custody split mid-document and was discovered only at handoff.
The second chat's audit happened to be sound, and a post-hoc independent re-verification
(chat-level re-audit + a read-only repo verification against the traceability gate)
confirmed the close. Rule: **ONE advisor chat per document, exactly as one terminal
session per document** — when a document's advisor exists, every audit, ruling, and
paste-ready block for that document comes from that chat only; if a second chat is opened
by mistake, its instructions are invalid until re-issued by the advisor of record, and the
mix-up is disclosed to the advisor immediately, not at handoff.

**Known intermittents (both open, both in BUILD-STATUS §S20):** RISK 0a — the AC-03 soak
(R-18 gates on it). RISK 0b — `PasswordResetFlowTest` ("keeps the token alive…"): recurred
twice, undiagnosed, log never captured, NOT attributable to the soak (predates it), sits on
Doc 02's reset surface. Any recurrence: capture first, then rerun.

## 6. Standing rules (bind the advisor, the design track, and every terminal session)

Unchanged from v3, restated in short: (1) documents are law; silence-fills filed in the
record; (2) fixes stay inside the main concept; (3) nothing skipped/shortened/re-litigated;
(4) one fresh session per document, solo-process proven before work; plan table first, WAIT;
one commit per step; (5) closure = pasted executed output + green CI run ID; false DONE =
Sev-1; sabotage-before-counting with positive controls; a green sabotage condemns the test;
**sabotage ledger reconciled against the plan's enumerated list** (governance #5); (6)
RULING UPDATEs reach the terminal before its next work message; (7) prompt delivery is
read-from-committed-file (`prompts/`), integrity check = last line "Then STOP." + protocol
step count; (8) the advisor recounts every self-audit table; old claims read against
today's registry; (9) design fidelity is law — frozen program at `design/frozen/` (219
files), screen/audit files: highest version frozen; design system: v2 + all three addenda;
deviations only in the three classes, filed in DESIGN-DEVIATIONS; (10) **evidence capture
first** (governance #6): no diagnostic run is ever piped through filters before a full copy
is committed.

## 7. Design track state (dormant; owner routes everything)

The frozen program (`design/frozen/`, PROGRAM-REPORT-v1 alongside) is the map. Doc 04 built
ONE rendered surface (the R-17 class-2 dead-letter view); everything else deferred to Doc 07
with the B12-A01 dispositions filed row-by-row in traceability.

For Doc 06 specifically, the drawn inventory is rich — candidates the next advisor verifies
against PROGRAM-REPORT-v1 and Doc 06 §8/§9: **B1-S04-ticket-operations-v1** (merge / split /
escalate / spam dialogs), **B2-S03-ticket-properties-v2** (property panel, pending reason,
timeline, subject, mobile sheet), **B2-S04-state-affordances-v2** (resolved/closed/spam
countdowns + bulk tail), **B2-S05-states-v2** (panel states, claim races, sheets), plus
B12-A01 row AC-08 (draft survives merge — its Doc 04 half is proven, the merge chip half
lands with Doc 06/07). NOTE Doc 06 §8's opening: "(The inbox/thread layout is Doc 07; this
section is the ticket-property contract Doc 07 renders.)" — the same scoping question as
Doc 04, but with far more drawn material in scope-adjacent files; verify, don't assume, and
expect a NON-trivial fidelity block this time.

Two design gaps the program itself recorded, both touching Doc 06 — on the owner's desk:
**tag rename/merge was never designed** (B2-S06 audit: "no rename or merge designed") while
Doc 06 FR-19/T-07 require it — expect a class-2 fill or an owner design decision; and the
**bulk-bar disabled reason is hover-only** (recorded open designer question, against Doc 06
§13's keyboard/SR requirements) — needs one owner ruling, then a scope-limited B1-S02 reopen
or a class-2 accessible rendering.

Other open design items (owner's): AC-09 + Batch 6 pixel exports; Amharic destructive-tier
review (add Doc 04's queue: media_rejected, quarantine, dead-letter re-drive; Doc 06 will
add merge/spam/escalate); label-gray re-issue; B6-S10 approver chip; Batches 5–7 acceptance
files; Batches 8+ scope decision.

## 8. Known traps for Doc 06 (bake these into SESSION-06)

- **TicketTransitionService is the SOLE status writer** (T-01/NFR-03): legality (FR-04's
  matrix as data) → permission → invariants (FR-10/BR-07) → FR-24 timestamps → persist +
  outbox, ONE transaction. CI grep + repository encapsulation enforce "no status writes
  elsewhere". Build it + the table-driven matrix test FIRST (§21) — everything composes on
  it. Same-state calls no-op successfully (AC-01/EC-07).
- **Open-family is defined, not assumed** (FR-10): {new, open, pending_*, escalated};
  resolved is NOT open-family — a customer message on resolved REOPENS (FR-08 beats
  new-ticket creation). T-02's partial unique index (`WHERE status IN open-family AND
  linked_work = false`) must reconcile with Doc 04's shipped invariant; direct-SQL bypass
  fails on the index (AC-02).
- **Merge moves links, never messages** (BR-02/FR-20): same-customer only (SEC-06, hard
  server rule — cross-customer needs Doc 08 first); source → closed with `merged_into`;
  24 h unmerge from an immutable snapshot restoring byte-identical state (AC-04, snapshot
  diff = ∅); chains resolve transitively (EC-03); "ship merge with unmerge or don't ship
  merge" (§21).
- **Timers: ZSET + column truth** (T-03): 1-min sweep re-checks status in-transaction;
  customer-reply cancel WINS the exact-moment race (EC-01) — never close-then-reopen
  flicker; lost ZSET rebuilds from columns, delayed never premature (FS-01). Reopen window
  is DB-time, inclusive, fixture-pinned (EC-02); DST fixtures (R-03).
- **Metric timestamps are transactional** (FR-24/AC-09): computed WITH the transition,
  never derived later; reopen clears resolved_at; golden lifecycle fixtures shared with
  Doc 15 from day one (§21) even though Doc 15 isn't built.
- **Handoff packet: immutable insert-only** (T-05/SEC-02/AC-08): built and proven against
  a Doc 09 FIXTURE — Doc 09 is not built; `createFromHandoff` is the seam it will call.
- **Bulk = per-item everything** (FR-25/SEC-04/T-06/EC-12): individually validated queued
  operations, permissions checked live at execution, no batch-level averaging, partial
  results honest, 200 cap, progress via Reverb `user.{id}`.
- **Spam interplay** (FR-09/AC-06/EC-09): metric exclusion on mark, restoration ≤7 d,
  terminal after; the conversation block is Doc 04's, separately confirmed; spam never
  blocks the conversation by itself.
- **channel_unreachable is orthogonal** (BR-07/EC-10): Doc 05's field, schema-only now —
  it never auto-resolves/auto-closes; humans decide. Do not build Doc 05 semantics.
- **Numbering** (FR-02/NFR-05/FS-03): tenant_sequences (Doc 01 T-02), gaps acceptable,
  never reused; 100 parallel creates → 100 unique numbers; backpressure 429 beyond
  threshold, never duplicates.
- **Escalation/assignment seams**: assignee must hold view scope (Doc 03 intersection,
  `assignee_out_of_scope`); SEC-01 re-checks scope on TARGET objects (merge target,
  transfer destination); named-user escalation falls back to supervisor_review on
  disable (EC-05); deactivated-assignee batch → `ticket.assignee_deactivated` → Doc 11
  re-routes (seam — Doc 11 not built); claim race first-commit-wins with in-place UI
  contract (FR-16/US-01).
- **Events**: FR-26's 21-event family, versioned schemas, **consumer contract tests per
  consuming doc against fixtures** (NFR-06/T-09 — Docs 12/15/17 aren't built; their
  fixtures are). FS-05's nightly invariant auditor exists precisely because it should
  never fire.
- **Statuses are platform-fixed v1** (R-01): "one more status" requests are absorbed by
  future display vocabularies (§19), never by forking the machine.
- **Settings**: `tickets.implicit_claim`, `tickets.resolve.requires_reply`,
  `tickets.autoclose.hours` (48), `tickets.reopen.days` (7) — armed timers keep their
  value on change (SS-03).

## 9. Open items on the owner's desk

R-18's step 0 happens FIRST in the next terminal session (it is Doc 04 code but gates
Doc 06). Design: the two Doc 06 gaps in §7 (tag rename/merge; bulk-bar reason) want rulings
BEFORE the Doc 06 plan lands; pixel exports; Amharic queue (now three documents deep);
label-gray; B6-S10 chip; Batches 5–7 acceptance files. Prerequisites approaching fast:
**Doc 05 is next after Doc 06** — the BotFather bot + Telegram Business account phone must
exist when its session starts; SMS gateway sandbox (Doc 14) and AI provider keys (Doc 09)
follow. MaxMind key (staging GeoIP) still open.

## 10. Forward plan

Doc 06 (Ticketing) next, per the build order 06 → 05 → 07 → rest. The next build session
runs R-18's step 0 (soak determinism + capture protocol) BEFORE its plan; the advisor
approves no plan until step 0's evidence is green. Then the standard loop: SESSION-06 per
SESSION-PROMPT-SPEC.md; committed to `prompts/`; fresh solo-verified terminal; plan table
independently audited (id recount; sabotage list; inherited rows §4.0–4.10; fidelity
scoping); APPROVED with rulings; build under the efficiency ruling; close-out audited with
a full recount before acceptance; then the v5 kit. First usable milestone (agents serving
players on Telegram) needs Docs 06, 05, 07 and a careful Doc 09 first pass on the finished
core — the owner has been told **~30% toward MVP** as of Doc 04 close.
