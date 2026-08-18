# SESSION-PROMPT-SPEC (v8) — how to write SESSION-09

SESSION-09 is a single markdown file, committed to prompts/, that a fresh solo Claude
Code session reads FROM DISK and follows completely. The exemplar is
prompts/SESSION-08-doc08-customers.md (687 lines): mirror its structure, not its
content. Content comes from Doc 09's own text, which you (the advisor) read first and
verify every claim against.

## REQUIRED STRUCTURE, IN ORDER

1. **ROLE + VERIFICATION CONTRACT** — principal engineer framing; the owner is not a
   programmer; nothing claimed working without pasted executed output; ambiguity = STOP
   and ask.
2. **SOLO-PROCESS GUARD** — before ANY work including reading; raw process list, own
   PID by ancestry; repeats at every resume; the stale-vs-transport discriminator.
3. **PROMPT INTEGRITY CHECK** — read from disk at its exact path; state the PROTOCOL
   step count; confirm the last line is exactly `Then STOP.` All three or stop.
4. **THE STANDING RULES CARRIED FROM MINUTE ONE** — at minimum rules 15 (CI once,
   never polled), 17 (evidence as pasted text/screenshots), 18 (the grounding rhythm,
   verify-the-advisor's-SHA included — incidents #14/#15 are the reason).
5. **STEP 0 — THE CREDENTIALS GATE (hard for Doc 09)** — the Addis AI staging key:
   a real authenticated call executed, output pasted, BEFORE the rulings-filed proof.
   Then the rulings-filed proof: A-38…A-42, ADR 0014, SCHEMA AMENDMENT 2 + additions,
   each cited to its commit. A missing filing is a STOP. Include the provider
   CAPABILITY CHECK as step 0's second half: fixture conversations through Addis AI,
   scored on structured output, English, Amharic; Gemini free tier is the named
   fallback; the result decides the plan's provider rows.
6. **READING LIST** — each file with WHY; no plan row from memory or from this
   prompt's summaries; the substrate Doc 09 consumes read as CODE (the Doc 08 RG seam,
   ChannelIdentityMap, ConsumerRegistry, the live relay, the outbox).
7. **ESCALATE-FIRST, stated BEFORE the scope** — Docs 01–08 all core; name the
   predictable pressure points you found while reading Doc 09; cite core edits #6 vs
   #7 as the contrast (be #7).
8. **SCOPE** — implement all of Doc 09; the session enumerates every id family from
   the document itself and STATES ITS COUNT BY FAMILY (you reconcile privately);
   out-of-scope handoffs named per document; pre-agreed closure buckets in a table,
   each with its unblocker; the [D09] CLOSING SET the session enumerates from
   BUILD-STATUS itself.
9. **INHERITED ROWS** — handoff v8 §4 rows 0a–12, each a mandatory plan row with a
   disposition (carry / discharge-at-step-N / owner-action).
10. **THE SUBTLE PARTS** — the traps you verified against Doc 09's text, each citing
    its id. Write these only after reading the document; never inherit them from this
    spec.
11. **PROTOCOL — exactly 6 steps**, the S08 lineage: (1) plan-first with safety tests
    sequenced BEFORE the features they constrain, canonical suites command defined at
    plan time, WAIT for approval, approved plan committed at approval; (2) one commit
    per step, registries as you go, gates every commit, enumerated lists grow only by
    filed amendment, schema changes = R-19b + grant physics + filed amendment
    countersigned BEFORE the migration; (3) sabotage with a floor of TEN (list them,
    verified against Doc 09's text — include at least one prompt-injection-shaped
    falsification), positive control per assertion, captures first, no pipes on gates;
    (4) i18n en+am, R-14 queue, destructive-tier flags; (5) full correctness battery
    every step-commit regardless of BAT1 (the split is owner law), timing legs on wall
    power only, CI once per checkpoint; (6) the close-out: full self-audit table, ledger
    item-by-item, closing-set outcomes, deviations, inherited outcomes, core-edit
    enumeration, honest trail, the close-out SHA grounded by the owner BEFORE the
    advisor recount.
12. **CADENCE** — batches, one checkpoint per batch, the seven mandatory mid-batch
    stops (ruling needed; guard conflict; red battery; core-edit boundary; enumeration
    amendment; schema countersign; design conflict/gap).
13. **DESIGN FIDELITY** — the mandate binds; the Doc 09 batch file list DERIVED
    INDEPENDENTLY from PROGRAM-REPORT-v1 and cross-checked both directions; frame-
    mapping rows mandatory; deviations in exactly three classes, all filed.
14. **HARD PROHIBITIONS** — no secrets/PII in prompts, commits, or captures (and for
    Doc 09: NO REAL CUSTOMER TEXT TO THE PROVIDER outside production — the Doc 05
    incident at higher stakes); no invented permission keys (catalog closed at 113);
    no document edits without escalate-first; no green without pasted output; no CI
    polling; no second Claude process; no second implementation of an existing check;
    no PARTIAL outside a named bucket; a false DONE is a Sev-1.
15. **LAST LINE, exactly:** `Then STOP.`

## RULES FOR YOU WHILE WRITING IT

- Every factual claim in the prompt traces to the document, the repo, or a filed
  ruling. If you cannot cite it, cut it.
- The prompt states integrity facts about itself (line count is checked by the session;
  keep the step count at 6 and the last line exact).
- Deliver it to the owner's machine directly (Filesystem tools), give him a rename-safe
  copy command, and require the commit + git-verify BEFORE any session reads it.
