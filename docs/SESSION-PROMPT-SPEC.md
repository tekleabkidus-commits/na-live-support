# SESSION-PROMPT-SPEC (v10) — how to write SESSION-11

SESSION-11 is a single markdown file, committed to `prompts/`, that a fresh solo Claude
Code session reads FROM DISK and follows completely. Exemplars:
`prompts/SESSION-09-doc09-ai.md` and `prompts/SESSION-10-doc10-kb.md` — mirror their
structure, not their content. Content comes from Doc 11's own text, which you (the
advisor) read first and verify every claim against.

## REQUIRED STRUCTURE, IN ORDER

1. **ROLE + VERIFICATION CONTRACT** — principal engineer framing; the owner is not a
   programmer; nothing claimed working without pasted executed output; ambiguity =
   STOP and ask.
2. **SOLO-PROCESS GUARD** — before ANY work including reading; raw process list, own
   PID by ancestry; repeats at every resume; the stale-vs-transport discriminator;
   and the S10 additions: a detached bg-pty-host session has NO tty and survives a
   closed window; `claude daemon run` RESPAWNS what you kill; the Windows
   Claude-Desktop bridge respawns transports (`taskkill.exe /IM "Claude.exe" /F`).
   Verify every claimed kill against `ps` before believing it.
3. **PROMPT INTEGRITY CHECK** — read from disk at its exact path; state the PROTOCOL
   step count; confirm the last line is exactly `Then STOP.` All three or stop.
4. **STANDING RULES FROM MINUTE ONE** — at minimum rules 15 (CI once, never polled;
   billing annotation before triage; 0i/0k thresholds), 17 (evidence as pasted text or
   screenshots — attachments arrive empty), 18 (grounding both ways; captures over
   prose; rows over summaries; a TRUNCATED ruling is not a ruling — ask for the tail,
   never guess it).
5. **STEP 0 — RULINGS-FILED PROOF + THE [D11] CLOSING SET** (no external credential
   gate: routing is internal). Prove in-repo, each cited to its commit:
   ADVISOR-RULINGS-D11 + S10-ACCEPTANCE, SCHEMA AMENDMENTS 2/3/4, ADRs 0015–0019, the
   catalog count EXECUTED (114 — state it; every permission key the document routes to
   must EXIST or STOP). Then enumerate ALL [D11]-bucketed rows from BUILD-STATUS's own
   rows — each a plan row naming the line it flips at close.
6. **READING LIST** — each file with WHY; no plan row from memory or this prompt's
   summaries; the substrate read as CODE: Doc 03's scope resolver and its fail-closed
   T-03 membership seam, Doc 06's ticket assignment + transition service, Doc 07's
   inbox views and the Unassigned×Own-scope pin, Doc 08's RG law, Doc 09's handoff
   wait-estimate seam, ConsumerRegistry + the relay, the settings/quota engines.
7. **ESCALATE-FIRST, stated BEFORE the scope** — Docs 01–10 all core; name the
   pressure points found while reading Doc 11 (the scope resolver above all — Doc 11
   is what makes Team/Dept scopes non-empty for the first time); the S09/S10 record is
   the exemplar: packets proposed → countersigned with proving obligations → landed
   with fail-first controls.
8. **SCOPE** — implement all of Doc 11; the session enumerates every id family from
   the document itself and STATES ITS COUNT BY FAMILY (you reconcile privately);
   out-of-scope handoffs named per document; pre-agreed closure buckets in a table,
   each with its unblocker; the [D11] closing set restated as plan rows.
9. **INHERITED ROWS** — handoff v10 §4 rows 0a–11, each with a disposition
   (carry / discharge-at-step-N / owner-action).
10. **THE SUBTLE PARTS** — verified against Doc 11's text, each citing its id; written
    only after reading the document. At minimum: the scope-widening moment (empty sets
    become real rows — prove the transition deliberately), the per-ticket routing
    decision record as an audit surface, the absolute starvation cap, RG-routing
    finality.
11. **PROTOCOL — exactly 6 steps**, the S08/S09/S10 lineage: (1) plan-first with
    PLAN-TIME STOPS raised before step 1 (the S10 shape — a contradiction found while
    reading is a stop, not a guess), safety tests sequenced BEFORE the features they
    constrain (at minimum: the scope-resolution proof and the starvation-cap property
    test before any routing path), canonical suites command defined at plan time, WAIT
    for approval, approved plan committed at approval; (2) one commit per step,
    registries as you go, gates every commit, enumerated lists grow only by filed
    amendment, schema changes = R-19b + grant physics + filed amendment countersigned
    BEFORE the migration; (3) sabotage floor of TEN grown only by amendment (include
    at least one scope-widening falsification and one starvation-cap falsification),
    positive control per assertion, captures first, no pipes on gates, invalid-greens
    filed honestly and re-executed hardened; (4) i18n en+am, R-14 queue,
    destructive-tier flags; (5) full correctness battery every step-commit regardless
    of BAT1, timing legs on wall power only ("timing not taken" said plainly; a second
    re-run to chase a green is verdict-shopping), CI once per checkpoint, browser
    fixtures under the 0l heal+reproject law, and **never Pest and gates
    concurrently**; (6) the close-out: full self-audit table EVERY id itemized, ledger
    item-by-item, closing-set flips, deviations, inherited outcomes, core-edit
    enumeration, honest trail, the close-out SHA grounded by the owner BEFORE the
    advisor recount.
12. **CADENCE** — batches, one checkpoint per batch, the seven mandatory mid-batch
    stops (ruling needed; guard conflict; red battery; core-edit boundary; enumeration
    amendment; schema countersign; design conflict/gap).
13. **DESIGN FIDELITY** — mandate v10 binds; Doc 11's frames per the design decision
    (Option A precedent if undesigned); frame-mapping rows mandatory when frames
    exist; deviations in exactly three classes, all filed.
14. **HARD PROHIBITIONS** — no secrets/PII in prompts, commits, or captures; no
    invented permission keys (catalog closed at 114); no document edits without
    escalate-first; no green without pasted output; no CI polling; no second Claude
    process; no second implementation of an existing check; no PARTIAL outside a named
    bucket; a false DONE is a Sev-1.
15. **LAST LINE, exactly:** `Then STOP.`

## RULES FOR YOU WHILE WRITING IT

- Every factual claim traces to the document, the repo, or a filed ruling. If you
  cannot cite it, cut it. **Two of the previous generation's kit claims were false and
  the session caught both** — verify against the repo before you assert.
- The prompt states integrity facts about itself (step count 6; last line exact).
- Deliver it as a chat download with a rename-safe wildcard copy command; require
  commit + git-verify BEFORE any session reads it.
