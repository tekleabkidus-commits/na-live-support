# SESSION-PROMPT-SPEC.md — how the advisor writes SESSION-04 (and every later session prompt)
_This file instructs the NEXT advisor chat. The deliverable it governs is
`SESSION-04-doc04-conversations.md`: the complete prompt the owner commits to `prompts/`
and a fresh Claude Code session reads from disk to build Doc 04. The bar is
`prompts/SESSION-03-doc03-rbac.md` in the repo — read it first as the exemplar
(SESSION-02 is the prior exemplar; both are committed). Nothing below is optional._

## A. Before writing a single line

1. Read **Doc 04 (Conversations & Messaging Core) end to end** — every section, including
   the adapter contract (§4.3), the pipelines (§4.4/§4.5), and the full EC/FS lists. No
   prompt content may come from memory, summary, or the handoff alone.
2. Read PROJECT-HANDOFF-v3.md §4 (inherited items), §5 (rulings + governance record), §8
   (Doc 04 traps) — these become prompt content, not background — and
   DESIGN-FIDELITY-MANDATE.md in full, because §B.8 below depends on it.
3. Enumerate Doc 04's id families yourself (FR/NFR/US/BR/T/SEC/EC/FS/SS/AC/R) and count
   them. You will NOT print your count in the prompt — the protocol makes the session
   state ITS count first, and you audit the plan against yours. Keep yours for that audit.
4. Cross-check what already exists so the prompt names real substrate, not guesses (via a
   read-only terminal request if needed — the S19 pattern): the Doc 01 outbox/EventPublisher
   and monthly-partition conventions; the frozen BR-05 contract (InboundAccrualPolicy +
   ColdSummaryStore) Doc 01 left FOR Doc 04; the S0 queue families (telegram-in/out,
   webchat-in/out, media) and mock services (mock-telegram etc. — the FAKE adapter is a
   fixture, not the mock service; keep them distinct); Doc 02's webhook auth (FR-23/24/25)
   whose ingress probes Doc 04's controllers replace or consume; Doc 03's seeded
   `conversations.*` keys, RequirePermission (now enforcing), ScopeSets and the
   MembershipSource seam, the T-04 page-capability manifest, and the Reverb channel-auth
   path that must now enforce Doc 03 scope on `conversation.{id}`; the `no-channel-
   conditionals` grep gate (S0, about to become load-bearing); the avatar seam deferred
   from Doc 02 S17 to the media pipeline.

## B. Mandatory anatomy of SESSION-04 (same blocks as SESSION-03, in this order)

1. **Role block** — principal engineer/platform architect, 25+ years, systems of this
   class; owner is a non-programmer; never claim anything works without pasted executed
   output; never simplify/drop/shorten; STOP and ask on ambiguity or conflict. Add the
   session-hygiene line: before any work, prove this is the ONLY claude process (the
   S19 concurrent-session incident).
2. **Reading list** — CLAUDE.md; Doc 04 md end to end; BUILD-STATUS (Doc 03 §S19 close +
   the standing-PARTIAL buckets); docs/ANSWERS-FROM-DOC-AUTHOR.md (now incl. Q1–Q9 and
   A-3); DESIGN-FIDELITY-MANDATE.md; the substrate files from §A.4; relevant ADRs
   (0003 grants for the new tables, 0004 traceability flip, 0007 queue-family precedent).
   Each with WHY it must be read.
3. **Stack/context notes** — Laravel 13 ruling; registry-normative rule (Q9); the
   interpretation-record precedence line (document wins, STOP); the conversation-vs-ticket
   boundary stated up front; FAKE adapter only with the 04 → 06 → 05 order and why.
4. **SCOPE** — implement all of Doc 04, enumerate every id from the document itself;
   explicit OUT-of-scope list (Telegram/Business specifics → Doc 05; widget → Doc 13;
   ticket semantics/SLA → Docs 06/12; identity MERGE rules → Doc 08 (the resolver is
   called as a seam); AI content decisions → Doc 09; inbox presentation → Doc 07); and
   **pre-agreed closure buckets** so the session cannot invent deferrals — expect at
   minimum: real-adapter re-proof rows → Docs 05/13; ticket-range transcript export and
   routing fan-out consumers → Doc 06; AI turn decision + transcription/OCR consumers →
   Doc 09; inbox rendering of the mandated behaviors → Doc 07; retention/legal-hold
   runbook + autoscale signals → Doc 18; perf budgets → Doc 19 L1. Each bucket named with
   its unblocker. Verify each against Doc 04's own text before adopting it.
5. **INHERITED items, each as a mandatory plan-table row** (from PROJECT-HANDOFF-v3 §4):
   Doc 03 FR-06's export + Reverb re-check consumers (this session closes both halves);
   Doc 03 EC-05's moved-out-of-scope UI state on conversation objects (and the EC-02
   verdict on what can land here vs Doc 06 — verified, not assumed); Doc 01 AC-03
   (worker-kill mid-burst, no loss — claim or explicitly re-bucket with reason); Doc 01
   BR-05's frozen InboundAccrualPolicy/ColdSummaryStore contract consumed by the inbound
   pipeline; the avatar-media seam from Doc 02 S17; route→permission mapping in the same
   commit (gate is blocking); and the standing rule that Doc 03's honest PARTIALs
   (SS-01/SS-02) are not re-claimed.
6. **THE SUBTLE PARTS** — spelled out from Doc 04's own text after you read it. At minimum
   expect (verify each against the document): the adapter contract as a NORMATIVE
   interface with a conformance suite that later channels must pass (T-09); notes
   structurally undeliverable at the type level (SEC-07 — assert no path exists);
   per-conversation FIFO lanes with head-block, 10-min cap, lane-skip + warning, and lane
   fairness under a hot lane; ordering = receipt-time truth with channel timestamps
   preserved and the same-second-burst fixture (FR-07/EC-01/EC-10); `sent` never rolls
   back (EC-05); ACK ≤250 ms and never-inline processing; dedupe SETNX 48 h; blocked-state
   quarantine with no retro-fan-out (FR-11); media never delivered unscanned incl. the
   ClamAV-down hold-then-quarantine (EC-11), streamed size enforcement, SSRF allowlisted
   egress, cookie-less separate media origin, infected = no download path for any role;
   signed URLs TTL with re-issue; transcription/OCR as hook events only; drafts
   last-writer-wins with the other-device toast (T-07/EC-06); poison-message dead-letter
   with re-drive (FS-05); Reverb payloads ≤32 KB, schema-versioned, Doc 03 scope on
   subscribe; retention with legal-hold pre-drop copy-out (T-08/EC-09); repository-only
   writes on partitions; the events catalog additions with closed payloads through the
   outbox.
7. **PROTOCOL — the six steps VERBATIM from SESSION-03** (which carries SESSION-02's, plus
   the standing amendments: positive control for every assertion; sabotage before
   counting — and a sabotage that stays green condemns the test, not the guard (S19 #7);
   targeted tests while iterating with full suite+gates+lint once per step-commit; push +
   CI run ID at session close per the efficiency ruling; full self-audit table whose
   summary line you, the advisor, independently recount at acceptance).
8. **DESIGN FIDELITY block** — per DESIGN-FIDELITY-MANDATE.md §5. Doc 04 is mostly a
   backend/engine document whose RENDERING lives in Doc 07, but §8/§9 mandate behaviors
   with drawn sources: derive the exact file list from PROGRAM-REPORT-v1's inventory for
   Doc 04's surfaces (candidates to verify, not assume: B12-S01-media-lightbox-v3,
   B12-S02-edit-marker-v2, delivery-glyph/tombstone patterns in the B1/B2 stacks, and
   B12-A01's nine behaviour-acceptance rows — each B12-A01 row that names a Doc 04
   behavior binds as an executed test when its surface is built here). Design files are
   ON-DISK at `design/frozen/` — name the exact frozen versions, include the access
   wrapper (read ONLY the named files; screen/audit files: highest version frozen; design
   system: v2 + all three addenda together), require frame-mapping rows for any surface
   the session builds, and include the mandate's fidelity sentence VERBATIM in SCOPE. If
   the session builds no designed surface (everything deferred to Doc 07), the block
   reduces to one line saying so — but that scoping decision must be verified against
   Doc 04 §8/§9 and stated, not assumed.

## C. Quality bar and prohibitions

- The prompt is **complete and self-contained** — the terminal session must need nothing
  beyond it, the repo, and the attached docs. Never "see previous discussion".
- Nothing skipped, shortened, summarized, or re-litigated: every ruling in force that
  touches Doc 04 appears in the prompt itself; every inherited item is a row, not a
  footnote; the protocol is never abbreviated "as before".
- No invented requirements: every SCOPE and SUBTLE-PARTS line traces to Doc 04's text, a
  filed ruling, or the handoff. If you find yourself writing a requirement you cannot
  cite, stop and check.
- Owner-facing delivery: produce the prompt as a downloadable .md; the owner commits it to
  `prompts/` and starts a FRESH terminal session that reads it from disk; the integrity
  check is "last line is exactly 'Then STOP.' + state the number of PROTOCOL steps"; the
  session proves it is the only claude process before work; the plan table comes back to
  you for audit BEFORE the owner replies APPROVED.

## D. After SESSION-04 is delivered

The same loop as Docs 02–03: audit the plan table against your own id count; hand the
owner APPROVED-with-rulings; rule on questions (file interpretations in the record, which
is canonical); audit the close-out — recounting the self-audit summary yourself; then
write the next four-file handoff (PROJECT-HANDOFF v4, bootstrap, this spec updated with
Doc 06's traps, and DESIGN-FIDELITY-MANDATE.md carried forward unchanged unless the owner
amends it) for your successor, and have the owner VERIFY the commit landed. This spec file
is reusable: for Doc 06 and beyond, section B's Doc-04-specific content — including
§B.8's design files, drawn from PROGRAM-REPORT-v1's inventory for that document's
surfaces — is regenerated from that document and the then-current handoff; the anatomy,
protocol, bar, and prohibitions never change.
