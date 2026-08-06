# SESSION-PROMPT-SPEC.md — how the advisor writes SESSION-06 (and every later session prompt)
_This file instructs the NEXT advisor chat. The deliverable it governs is
`SESSION-06-doc06-ticketing.md`: the complete prompt the owner commits to `prompts/` and a
fresh Claude Code session reads from disk to build Doc 06. The bar is
`prompts/SESSION-04-doc04-conversations.md` in the repo — read it first as the exemplar
(SESSION-03 is the prior one; both are committed). Nothing below is optional._

## A. Before writing a single line

0. **R-18's gate shapes the prompt's opening**: the session's step 0 — BEFORE its plan —
   is (a) root-causing the Doc 04 AC-03 soak (`LaneOrderingSoakTest`; captured evidence
   at `docs/evidence/S20-soak-isolation/`) to a deterministic result with `make test`
   and CI reconciled (most likely a dedicated CI job), and (b) implementing RISK 0b's
   capture-first protocol. The prompt orders step 0's evidence pasted and the advisor's
   confirmation received before the plan table is even produced. No Doc 06 plan is
   approved before the soak is deterministic. This is an owner ruling; it is not
   negotiable and not summarizable.
1. Read **Doc 06 (Ticketing System) end to end** — every section, including the §4.2
   state diagram, §4.5's operation semantics, FR-24's timestamp definitions, and the full
   EC/FS lists. No prompt content may come from memory, summary, or the handoff alone.
2. Read PROJECT-HANDOFF-v4.md §4 (inherited items 0–10), §5 (rulings incl. R-15…R-18 and
   the six governance incidents), §8 (Doc 06 traps) — these become prompt content, not
   background — and DESIGN-FIDELITY-MANDATE.md in full, because §B.8 depends on it.
3. Enumerate Doc 06's id families yourself (FR/NFR/US/BR/T/SEC/EC/FS/SS/AC/R) and count
   them. You will NOT print your count in the prompt — the protocol makes the session
   state ITS count first, and you audit the plan against yours. Keep yours for that
   audit. (For calibration only, after your own count: the outgoing advisor's private
   enumeration found 26+6+7+7+9+6+12+5+4+9+5 across FR-01…26, NFR-01…06, US-01…07,
   BR-01…07, T-01…09, SEC-01…06, EC-01…12, FS-01…05, SS-01…04, AC-01…09, R-01…05. If
   your count differs, YOUR fresh count against the text governs the audit — investigate
   the difference, don't average it.)
4. Cross-check what already exists so the prompt names real substrate, not guesses (via a
   read-only terminal request if needed): Doc 04's shipped `open_ticket_id` +
   `open_ticket_exists` refusal and where its enforcement lives; the `message.received`
   outbox event Doc 06's reopen consumer subscribes to; Doc 04's conversation-block seam
   (FR-03) the spam dialog must call; the transcript exporter the ticket-range half
   extends; tenant_sequences (Doc 01 T-02) for numbering; the seeded `tickets.*` keys in
   `registries/permissions.php` (verify every key Doc 06 names inline exists; BR-01's
   `tickets.delete` platform-disabled state); the Doc 04 dead-letter/re-drive machinery
   (A-6's `tickets.reply` mapping — Doc 06's real reply route arrives now); Reverb
   `user.{id}` (bulk progress); the node-kill drill (extended for the tickets count,
   inherited row 6); the settings registry conventions for the four `tickets.*` settings;
   ADRs 0003 (grants for new tables — tickets never deleted is grant physics again),
   0004, 0007, 0008/0009.

## B. Mandatory anatomy of SESSION-06 (same blocks as SESSION-04, in this order)

1. **Role block** — principal engineer, 25+ years, systems of this class; owner is a
   non-programmer; never claim anything works without pasted executed output; never
   simplify/drop/shorten; STOP and ask on ambiguity or conflict. Session hygiene: before
   ANY work, prove this is the ONLY claude process (the guard has both failed-by-absence
   at SESSION-03 and fired-correctly at SESSION-04 — it stays). Then **STEP 0 = R-18's
   gate** (soak determinism + capture protocol), evidence pasted, advisor confirmation
   awaited, before the plan.
2. **Reading list** — CLAUDE.md; Doc 06 md end to end; BUILD-STATUS (Doc 04 §S20 close,
   RISK 0a/0b, DESIGN-DEVIATIONS, standing-PARTIAL buckets); docs/ANSWERS-FROM-DOC-AUTHOR
   .md (Q1–Q9, A-1…A-6, R-9…R-18); DESIGN-FIDELITY-MANDATE.md; the substrate files from
   §A.4; relevant ADRs. Each with WHY.
3. **Stack/context notes** — Laravel 13; registry-normative (Q9); interpretation-record
   precedence (document wins, STOP); the **ticket-consumes-conversation boundary** up
   front (messages belong to conversations, tickets reference ranges — BR-02/Doc 04
   primacy; merge/split move links, never rows); consumers not yet built are SEAMS with
   fixtures (Doc 09 handoff packet, Doc 11 routing, Doc 12 SLA/automation, Doc 14 SMS,
   Doc 15 metrics — contract tests against their fixtures per NFR-06, never their
   logic); statuses platform-fixed v1 (R-01).
4. **SCOPE** — implement all of Doc 06, enumerate every id from the document itself;
   explicit OUT-of-scope list (queue/routing algorithms → Doc 11; SLA policy definition
   and timers UI → Doc 12; custom fields/forms → Doc 12; inbox layout → Doc 07; CSAT →
   Docs 12/15; reporting formulas → Doc 15; Doc 05 channel semantics —
   `channel_unreachable` is schema + BR-07 orthogonality only); and **pre-agreed closure
   buckets** verified against Doc 06's own text — expect at minimum: FR-11's live
   caller + packet rendering → Doc 09/07 (the fixture-proven `createFromHandoff` seam is
   DONE-able); FR-14 offline-form creation path → Doc 13 (validation path built, caller
   waits); FR-15/EC-06 re-route halves → Doc 11; FR-06 CSAT trigger + FR-17 SLA
   re-target + EC-08's rule source → Doc 12; resolved-SMS event consumer → Doc 14;
   FR-09/AC-06 metric-exclusion CONSUMER assertions → Doc 15 (the exclusion flags and
   golden fixtures are this session's); property-panel/dialog RENDERING per §8's opening
   → Doc 07 or built here per the §B.8 verification; perf numbers (§12/NFR p-values) →
   Doc 19. Each bucket named with its unblocker.
5. **INHERITED items, each as a mandatory plan-table row** (PROJECT-HANDOFF-v4 §4,
   items 0–10): R-18 step 0 (satisfied before the plan; its row cites the evidence);
   Doc 04 FR-24's ticket-range export; Doc 02 AC-05's literal fixture on the real
   `tickets.reply` route; the `message.received` reopen/creation consumer; the Doc 04
   spam-block seam consumption; the open-family invariant reconciliation with Doc 04's
   shipped enforcement (the DB refusal is ALREADY BUILT — reconcile, never rebuild);
   Doc 04 EC-04's rule-trigger seam claimed explicitly through Doc 06's close/resolve
   events; Doc 01 AC-03's tickets-count extension of the node-kill drill;
   Doc 03 EC-02's granter-naming proof on a ticket action; route→permission mapping
   same-commit from the existing catalog only; standing PARTIALs untouched.
6. **THE SUBTLE PARTS** — spelled out from Doc 06's own text after you read it. At
   minimum expect (verify each against the document): TicketTransitionService as sole
   writer with the FR-04 matrix as data + the table-driven every-cell test (AC-01) built
   FIRST (§21), same-state no-op included; the T-02 partial unique index behind the
   service check (AC-02's direct-SQL bypass proof) reconciled with Doc 04's layer;
   FR-10's open-family definition with resolved excluded and FR-08 reopen beating
   duplicate; split's `linked_work=true` cap-3 as the one sanctioned multi-open (EC-04);
   merge same-customer-only as a hard server rule (SEC-06), link-rewrite in one
   transaction, the 24 h byte-identical unmerge snapshot (AC-04, FS-04's
   snapshot-is-authority race), transitive chain resolution (EC-03); timers as ZSET +
   column truth with in-transaction sweep re-check and event-driven cancel winning EC-01,
   FS-01's rebuild-delayed-never-premature, EC-02's inclusive DB-time boundary, R-03's
   DST fixtures; FR-24 timestamps computed transactionally with golden fixtures shared
   with Doc 15 (AC-09); the immutable insert-only handoff packet proven at the DB layer
   (AC-08, SEC-02); bulk as individually-validated queued items with live per-item
   permissions, no averaging, honest partial results, progress events (FR-25, SEC-04,
   T-06, EC-12, AC-07); spam's metric exclusion/restore/terminality with the separate
   Doc 04 block confirm (FR-09, AC-06, EC-09); claim race first-commit-wins with
   `already_claimed` (FR-16); scope re-checks on TARGET objects (SEC-01); escalation
   reason ≥10 chars + named-user fallback (FR-23, EC-05); assignee-deactivated batch
   seam (EC-06); numbering under concurrency with backpressure-never-duplicates (FR-02,
   NFR-05, FS-03); FR-26's full event family versioned with consumer contract tests
   against Docs 12/15/17 fixtures (NFR-06, T-09, SS-04); FS-05's nightly invariant
   auditor that pages; BR-01's platform-disabled `tickets.delete` as grant/flag physics;
   the four `tickets.*` settings with SS-03's armed-timers-keep-value semantics.
7. **PROTOCOL — the six steps VERBATIM from SESSION-04** (which carries the lineage plus
   the standing amendments: positive control for every assertion; sabotage before
   counting; a green sabotage condemns the test (S19 #7); **the sabotage ledger is
   reconciled against the plan's enumerated list at close, never against a count**
   (governance #5); **capture-first on any diagnostic run** (governance #6 / RISK 0b);
   targeted tests while iterating with full suite+gates+lint once per step-commit; push +
   CI run ID at session close per the efficiency ruling; full self-audit table whose
   summary the advisor independently recounts). Doc 06 sabotage minimums to name in the
   prompt after verifying: illegal transition permitted → AC-01 matrix red; T-02 index
   dropped → AC-02's concurrent-create/direct-SQL red; merge without snapshot → AC-04
   red; per-item bulk permission averaged → AC-07/SEC-04 red; spam exclusion flag
   ignored → AC-06 fixtures red; timer sweep skipping the in-transaction re-check →
   EC-01 red; timestamps derived post-hoc → AC-09 golden fixtures red; cross-customer
   merge permitted → SEC-06 red; status written outside the service → NFR-03 grep/
   encapsulation red.
8. **DESIGN FIDELITY block** — per DESIGN-FIDELITY-MANDATE.md §5. Doc 06 §8 opens "(The
   inbox/thread layout is Doc 07; this section is the ticket-property contract Doc 07
   renders.)" — the same scoping question Doc 04 had, but with substantially more drawn
   material: derive the exact file list from PROGRAM-REPORT-v1's inventory (candidates
   to verify, not assume: **B1-S04-ticket-operations-v1**, **B2-S03-ticket-properties-
   v2**, **B2-S04-state-affordances-v2**, **B2-S05-states-v2**, and the ticket-behavior
   rows of B12-A01 — AC-08's merge-chip half AND AC-09's bulk-progress-at-25% clause,
   which Doc 06 §13 also requires; each such row binds as an executed test when its
   surface is built and gets an explicit disposition either way; always the design
   system: B1-DS-design-system-v2 + all three addenda). Verify against Doc 06 §8/§9 what this session builds versus defers to Doc
   07, STATE the verification in the plan, and scope the block accordingly — expect it
   NON-trivial. Design files are ON-DISK at `design/frozen/` — exact frozen versions,
   the access wrapper, frame-mapping rows for any surface built, the mandate's fidelity
   sentence VERBATIM in SCOPE. Two RECORDED design gaps touch Doc 06 and must be
   pre-ruled by the owner or planned as filed class-2 fills, never improvised: tag
   rename/merge was never designed (Doc 06 FR-19/T-07 require it); the bulk-bar
   disabled-reason is hover-only against Doc 06 §13 (open designer question — surface it
   to the owner before the plan is approved).

## C. Quality bar and prohibitions

- The prompt is **complete and self-contained** — the terminal session must need nothing
  beyond it, the repo, and the attached docs. Never "see previous discussion".
- Nothing skipped, shortened, summarized, or re-litigated: every ruling in force that
  touches Doc 06 appears in the prompt itself; every inherited item is a row; the
  protocol is never abbreviated "as before"; R-18's step 0 is stated in full.
- No invented requirements: every SCOPE and SUBTLE-PARTS line traces to Doc 06's text, a
  filed ruling, or the handoff. If you find yourself writing a requirement you cannot
  cite, stop and check.
- Owner-facing delivery: produce the prompt as a downloadable .md; the owner commits it
  to `prompts/` and starts a FRESH terminal session that reads it from disk; the
  integrity check is "last line is exactly 'Then STOP.' + state the number of PROTOCOL
  steps"; the session proves it is the only claude process, then runs STEP 0 (R-18) and
  waits for advisor confirmation; the plan table comes back to the advisor for audit
  BEFORE the owner replies APPROVED.

## D. After SESSION-06 is delivered

The same loop as Docs 02–04: audit the plan table against your own id count; hand the
owner APPROVED-with-rulings; rule on questions (file interpretations in the record, which
is canonical); audit the close-out — recounting the self-audit summary AND reconciling
the sabotage ledger against the plan's list; then write the next four-file handoff
(PROJECT-HANDOFF v5, bootstrap, this spec updated with Doc 05's traps, and
DESIGN-FIDELITY-MANDATE.md carried forward unchanged unless the owner amends it) for your
successor, and have the owner VERIFY the commit landed (caught twice already). This spec
file is reusable: for Doc 05 and beyond, section B's Doc-06-specific content — including
§B.8's design files, drawn from PROGRAM-REPORT-v1's inventory for that document's
surfaces — is regenerated from that document and the then-current handoff; the anatomy,
protocol, bar, and prohibitions never change. Note for the v5 spec: Doc 05 needs real
Telegram credentials (BotFather bot + Business account) — its §A must add a
prerequisites check with the owner before any session starts.
