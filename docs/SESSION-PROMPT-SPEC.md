# SESSION-PROMPT-SPEC.md — how the advisor writes SESSION-05 (and every later session prompt)
_This file instructs the NEXT advisor chat. The deliverable it governs is
`SESSION-05-doc05-telegram.md` (match the repo's actual Doc 05 filename): the complete
prompt the owner commits to `prompts/` and a fresh Claude Code session reads from disk to
build Doc 05. The bar is `prompts/SESSION-06-doc06-ticketing.md` in the repo — read it
first as the exemplar (SESSION-04 is the prior one; both are committed). Nothing below is
optional._

## A. Before writing a single line

0. **THE PREREQUISITE GATE shapes the prompt's opening** (Doc 05 has no R-18-style
   engineering gate — R-18 is CLOSED — but it has a credentials gate): before SESSION-05
   is released for launch, the OWNER confirms a real BotFather bot token and the Telegram
   Business account phone exist in the dev environment. The prompt's STEP 0 — before the
   plan — orders the session to PROVE them: a real `getMe` call pasted (token redacted in
   the paste, never in a committed file), the Business connection state shown, and the
   egress proxy's allowlist confirmed to carry Telegram's API domains (calls go THROUGH
   the proxy — a direct call is a violation, not a convenience). If only the bot exists
   and not the Business account, the session states which halves of Doc 05 are provable
   and plans an honest credential-blocked bucket for the rest — it does not fake the
   Business half against mocks and call it proven. Evidence pasted, advisor confirmation
   received, before the plan table is produced.
1. Read **Doc 05 (Telegram Channel) end to end** — every section, including the webhook
   text (reconcile against the standing webhook rulings 1–7 BEFORE writing the prompt),
   the Business-vs-Direct connection semantics, the media flows, the `channel_unreachable`
   set/clear rules, and the full EC/FS lists. No prompt content may come from memory,
   summary, or the handoff alone.
2. Read PROJECT-HANDOFF-v5.md §4 (inherited items 0–10), §5 (rulings incl. R-19…R-23,
   ADRs 0010/0011, and the NINE governance incidents), §8 (Doc 05 traps — the floor, not
   the ceiling) — these become prompt content, not background — and
   DESIGN-FIDELITY-MANDATE.md in full, because §B.8 depends on it.
3. Enumerate Doc 05's id families yourself (FR/NFR/US/BR/T/SEC/EC/FS/SS/AC/R) and count
   them. You will NOT print your count in the prompt — the protocol makes the session
   state ITS count first, and you audit the plan against yours. Keep yours for that
   audit. **The outgoing advisor has NOT enumerated Doc 05 — there is no calibration
   count this time. Your fresh count against the text is the only one and it governs the
   audit; the discipline (count from the document, investigate any mismatch with the
   session's count, never average) is unchanged.**
4. Cross-check what already exists so the prompt names real substrate, not guesses (via a
   read-only terminal request if needed): the ChannelAdapter contract + the 32-combination
   conformance suite (the 12th gate) and the two fake adapters that prove it; the inbound
   discipline (ACK ≤250 ms never-inline, SETNX dedupe, quarantine, one-transaction
   persist+outbox) and the exact door Doc 05's webhook enters; the delivery state machine
   and its dead-letter/re-drive machinery (R-17/A-6 — Doc 05 wires into it, never builds a
   parallel retry stack); the FULL media chain incl. the egress proxy's allowlist config;
   `channel.health.changed` (A-4, transition-only) in the catalog; Doc 06's
   `channel_unreachable` column + the orthogonality tests that must stay green; the
   ConsumerRegistry (ADR 0011) if Doc 05 subscribes to anything; `registries/
   permissions.php` for every channel key Doc 05 names inline (catalog CLOSED — verify,
   never invent, NFR-03); the settings registry conventions (the connection method IS a
   setting); the secrets discipline in CLAUDE.md for the bot token; ADRs 0003 (grants for
   any new tables), 0004, 0007, 0008 (the egress proxy Doc 05 now truly exercises),
   0010/0011 (both bind Doc 05 directly — R-19b for every new ordering/metric timestamp,
   registry-entry consumers only).

## B. Mandatory anatomy of SESSION-05 (same blocks as SESSION-06, in this order)

1. **Role block** — principal engineer, 25+ years, systems of this class; owner is a
   non-programmer; never claim anything works without pasted executed output; never
   simplify/drop/shorten; STOP and ask on ambiguity or conflict. Session hygiene: before
   ANY work, prove this is the ONLY claude process (the guard has fired correctly FOUR
   times — it stays, verbatim, with the history stated). Then **STEP 0 = the prerequisite
   proof** (real `getMe` through the proxy, Business state, token-redaction discipline),
   evidence pasted, advisor confirmation awaited, before the plan.
2. **Reading list** — CLAUDE.md; Doc 05 md end to end; BUILD-STATUS (the S22 close-out,
   RISK 0a/0b/0d, the events.created_at deferral row, DESIGN-DEVIATIONS, standing-PARTIAL
   buckets, the canonical-suites-definition precedent); docs/ANSWERS-FROM-DOC-AUTHOR.md
   (Q1–Q9, A-1…A-18, R-1…R-23 — the webhook rulings and A-4 matter most here);
   DESIGN-FIDELITY-MANDATE.md; the substrate files from §A.4; ADRs 0003/0007/0008/0010/
   0011. Each with WHY.
3. **Stack/context notes** — Laravel 13; registry-normative (Q9); interpretation-record
   precedence (document wins, STOP); **the adapter-consumes-core boundary up front**: the
   Telegram adapter implements the ChannelAdapter contract and touches ZERO core files —
   R-16's zero-core-edits architecture proof is now tested for real, and needing a core
   edit is an escalation, not a workaround; channel timestamps are METADATA (receipt-time
   ordering + R-19 physics — ordering never reads Telegram's `date`); Business-vs-Direct
   is a capability-declared SETTING, one adapter, never two drifting paths; consumers not
   yet built are SEAMS with fixtures (Doc 08 identity mapping above all — Telegram user →
   customer is Doc 08's, fixture-proven here); statuses/sources remain platform-fixed.
4. **SCOPE** — implement all of Doc 05, enumerate every id from the document itself;
   explicit OUT-of-scope list (identity resolution/merge → Doc 08; inbox rendering →
   Doc 07; AI replies → Doc 09; routing → Doc 11; SMS → Doc 14; the widget → Doc 13); and
   **pre-agreed closure buckets** verified against Doc 05's own text — expect at minimum:
   the Doc 08 identity seam (mapping built against fixtures, resolution deferred); any
   Doc 07 rendering of channel state; perf p-values → Doc 19; and — ONLY if the owner
   could not provide the Business account — an honest credential-blocked bucket whose
   unblocker is the credential, stated plainly. Each bucket named with its unblocker.
5. **INHERITED items, each as a mandatory plan-table row** (PROJECT-HANDOFF-v5 §4, items
   0–10): the prerequisite proof (satisfied before the plan; its row cites the pasted
   evidence); the conformance-suite pass on the REAL adapter with zero core edits (Doc 04
   AC-01's re-proof bucket firing); `channel_unreachable` semantics with Doc 06's
   orthogonality tests kept green; the messaging pipeline consumed through its existing
   doors (state which door each flow enters); R-19b on every new table; `channel.health.
   changed` produced per A-4; the events.created_at deferral check (if Doc 05 orders any
   read over events, the deferral FIRES first); route→permission mapping same-commit from
   the existing catalog; standing PARTIALs untouched; the three known intermittents
   acknowledged (a CI red on 0d is re-run once and recorded, never loosened); the
   consumer-fixture tightening note if Doc 05 consumes any ticket.* event.
6. **THE SUBTLE PARTS** — spelled out from Doc 05's own text after you read it. The
   handoff §8 traps are the floor; verify each against the document and expect at
   minimum: webhook verification reconciled with rulings 1–7 (secret token, replay
   posture, error responses); update_id dedupe key shape; ACK-then-queue with the ≤250 ms
   proof; the Business connected-bot handshake and its failure modes; media through the
   existing chain with Telegram's file API behind the egress proxy; rate-limit handling
   through the EXISTING retry/dead-letter path; `channel_unreachable` set/clear with
   no-transition-ever proven; outbound send through the delivery state machine with
   `sent`-never-rolls-back intact; token secrecy incl. log/evidence scrubbing; the
   identity seam's fixture shape for Doc 08.
7. **PROTOCOL — the six steps VERBATIM from SESSION-06** (which carries the lineage plus
   ALL standing amendments: positive control for every assertion; sabotage before
   counting; a green sabotage condemns the test (S19 #7) — and its inverse, a by-design
   green is EXPLAINED, never "strengthened" into a worse test (S22's FS-04 precedent);
   the sabotage ledger reconciled against the plan's enumerated list at close, never a
   count (governance #5 — the ledger's own row-7/row-10 migrate:fresh trap is now
   precedent: a live-DB sabotage wiped by migrate:fresh is INVALID and redone at the
   migration level); capture-first on any diagnostic run (governance #6) with **verdicts
   only from unfiltered capture summaries and exit codes** (standing rule 11); targeted
   tests while iterating with full suite+gates+lint once per step-commit; push + CI run
   ID per the efficiency ruling; **the approved plan table committed to the repo at
   approval, before step 1's first commit** (governance #9 / standing rule 12); **the
   document's canonical suites command defined in BUILD-STATUS at plan time and used for
   every cited figure** (standing rule 14); full self-audit table whose summary the
   advisor independently recounts MECHANICALLY). Doc 05 sabotage minimums to name in the
   prompt after verifying against the text (the handoff §8 list is the expected floor):
   capability lie → conformance red; dedupe dropped → duplicate-inbound red; ACK inline →
   latency red; media chain bypassed → infected-fixture red; ordering on a channel
   timestamp → the inverted R-19 controls red; webhook secret validation removed → the
   webhook-ruling test red.
8. **DESIGN FIDELITY block** — per DESIGN-FIDELITY-MANDATE.md §5. Doc 05 is expected to
   be surface-THIN (adapter/server work), but Doc 06's lesson is that the scoping
   verification is cheap and the assumption is not: derive Doc 05's drawn inventory from
   PROGRAM-REPORT-v1 (candidate surfaces: Backoffice channel-setup/connection screens, if
   the program drew any), verify against Doc 05's own §8/§9 what this session builds
   versus defers, STATE the verification in the plan as its own row, and scope the block
   accordingly. If NO drawn surface is in scope, the prompt still carries the mandate's
   §9 token obligations for anything channel-state-visible that later documents render,
   and the close-out's DESIGN-DEVIATIONS states "no drawn surface built" if true. If ANY
   surface IS built: exact frozen versions at `design/frozen/`, the access wrapper,
   frame-mapping rows, the mandate's fidelity sentence VERBATIM in SCOPE. One design item
   is already on the owner's desk with a deadline: **R-22 (tag rename/merge surface) is
   due before Doc 07, not Doc 05** — do not let it leak into this prompt.

## C. Quality bar and prohibitions

- The prompt is **complete and self-contained** — the terminal session must need nothing
  beyond it, the repo, and the attached docs. Never "see previous discussion".
- Nothing skipped, shortened, summarized, or re-litigated: every ruling in force that
  touches Doc 05 appears in the prompt itself; every inherited item is a row; the
  protocol is never abbreviated "as before"; the prerequisite gate is stated in full.
- No invented requirements: every SCOPE and SUBTLE-PARTS line traces to Doc 05's text, a
  filed ruling, or the handoff. If you find yourself writing a requirement you cannot
  cite, stop and check.
- No secrets in artifacts: the prompt orders token redaction in every paste and a scrub
  check on every evidence capture. A bot token in a committed file is a Sev-1.
- Owner-facing delivery: produce the prompt as a downloadable .md; the owner commits it
  to `prompts/` and starts a FRESH terminal session that reads it from disk (old session
  `exit`ed FIRST — the guard has fired four times on this); the integrity check is "last
  line is exactly 'Then STOP.' + state the number of PROTOCOL steps"; the session proves
  it is the only claude process, then runs STEP 0 (the prerequisite proof) and waits for
  advisor confirmation; the plan table comes back to the advisor for audit BEFORE the
  owner replies APPROVED, and is COMMITTED at approval.

## D. After SESSION-05 is delivered

The same loop as Docs 02–06: audit the plan table against your own id count; hand the
owner APPROVED-with-rulings AND have the plan committed at approval; rule on questions
(file interpretations in the record, which is canonical; countersign the session's filed
interpretations at checkpoints); audit every checkpoint FROM YOUR CHAT ONLY (governance
#7/#8); audit the close-out — recounting the self-audit summary mechanically AND
reconciling the sabotage ledger against the plan's list; then write the next four-file
handoff (PROJECT-HANDOFF v6, bootstrap, this spec updated with Doc 07's traps, and
DESIGN-FIDELITY-MANDATE.md carried forward unchanged unless the owner amends it) for your
successor, and have the owner VERIFY the commit landed AND the spec generation is current
(both failure modes have occurred). This spec file is reusable: for Doc 07 and beyond,
section B's Doc-05-specific content is regenerated from that document and the then-current
handoff; the anatomy, protocol, bar, and prohibitions never change. Notes for the v6 spec:
**Doc 07 is the design track's richest territory** — R-22 must be RESOLVED by the owner
before its plan, the B12-A01 deferred rows and the Doc 06 PARTIALs bucketed to Doc 07
(T-08 read models, SEC-05's property-export surface, EC-08's prompt, EC-11's affordances,
AC-08's rendering, the merge banners/dialogs/preview) all come due, and its §B.8 will be
the heaviest fidelity block yet — expect the full frozen inventory, frame-mapping rows
throughout, and the accessibility obligations (§13's live regions, bulk progress at 25%
increments) as executed tests, not intentions.
