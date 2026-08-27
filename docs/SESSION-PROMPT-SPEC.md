# SESSION-PROMPT-SPEC (v9) — how to write SESSION-10

SESSION-10 is a single markdown file, committed to prompts/, that a fresh solo Claude
Code session reads FROM DISK and follows completely. Exemplars:
`prompts/SESSION-08-doc08-customers.md` and `prompts/SESSION-09-doc09-ai.md` — mirror
their structure, not their content. Content comes from Doc 10's own text, which you
(the advisor) read first and verify every claim against.

## REQUIRED STRUCTURE, IN ORDER

1. **ROLE + VERIFICATION CONTRACT** — principal engineer framing; the owner is not a
   programmer; nothing claimed working without pasted executed output; ambiguity =
   STOP and ask.
2. **SOLO-PROCESS GUARD** — before ANY work including reading; raw process list, own
   PID by ancestry; repeats at every resume; the stale-vs-transport discriminator;
   the Windows Claude-Desktop respawner note (taskkill on the Windows side).
3. **PROMPT INTEGRITY CHECK** — read from disk at its exact path; state the PROTOCOL
   step count; confirm the last line is exactly `Then STOP.` All three or stop.
4. **STANDING RULES FROM MINUTE ONE** — at minimum rules 15 (CI once, never polled;
   billing-annotation check before triage; 0i/0k thresholds), 17 (evidence as pasted
   text/screenshots), 18 (grounding both ways — incidents #14/#15/#16 and the S09
   garbled-plan paste are the reason; captures over prose, rows over summaries).
5. **STEP 0 — RULINGS-FILED PROOF + THE [D10] CLOSING SET** (no external credential
   gate: the KB is internal and embeddings ride the ADR 0018 local sidecar, whose
   probe the session runs once as its liveness check). Prove in-repo, each cited to
   its commit: ADVISOR-RULINGS-D09 + S09-ACCEPTANCE, SCHEMA AMENDMENTS 2 and 3, ADRs
   0015–0019, the catalog count EXECUTED (113, or 114 if row 0a already landed —
   state which and why; every kb.* key the document routes to must EXIST or STOP).
   Then the closing set: enumerate ALL [D10]-bucketed rows from BUILD-STATUS's Doc 09
   close section — each a plan row naming the line it flips at close. If row 0a's
   ai.providers.manage side-row is assigned to this session, it is an early sanctioned
   side-row here with the owner's sign-off cited.
6. **READING LIST** — each file with WHY; no plan row from memory or this prompt's
   summaries; the substrate read as CODE: KbIndexer + the kb_chunks contract,
   HybridRetriever, the embeddings sidecar client, the golden + red-team suites (the
   no-regression floor), the fixtures-only egress guard, ConsumerRegistry + relay,
   the top-10 draft-KB seam, EC-06's tombstone half, the settings/quota engines.
7. **ESCALATE-FIRST, stated BEFORE the scope** — Docs 01–09 all core; name the
   pressure points found while reading Doc 10 (the retrieval stack, the one-indexer
   door, the golden suites, Doc 09's Backoffice shell if KB admin lives beside it);
   the S09 record is the exemplar: packets proposed → countersigned with proving
   obligations → landed with fail-first controls.
8. **SCOPE** — implement all of Doc 10; the session enumerates every id family from
   the document itself and STATES ITS COUNT BY FAMILY (you reconcile privately);
   out-of-scope handoffs named per document; pre-agreed closure buckets in a table,
   each with its unblocker; the [D10] closing set from step 0 restated as plan rows.
9. **INHERITED ROWS** — handoff v9 §4 rows 0a–11, each with a disposition
   (carry / discharge-at-step-N / owner-action).
10. **THE SUBTLE PARTS** — verified against Doc 10's text, each citing its id;
    written only after reading the document.
11. **PROTOCOL — exactly 6 steps**, the S08/S09 lineage: (1) plan-first, safety tests
    sequenced BEFORE the features they constrain (at minimum: the one-indexer-door
    proof and instruction-strip no-second-route sweep BEFORE any authoring path; the
    golden+red-team no-regression floor wired into the canonical command from step
    one; publish/retire round-trip proven BEFORE live index maintenance), canonical
    suites command defined at plan time, WAIT for approval, approved plan committed
    at approval; (2) one commit per step, registries as you go, gates every commit,
    enumerated lists grow only by filed amendment, schema changes = R-19b + grant
    physics + filed amendment countersigned BEFORE the migration; (3) sabotage floor
    of TEN grown only by amendment (verified against Doc 10's text; include at least
    one unstripped-authoring-route falsification and one retire-that-deletes
    falsification), positive control per assertion, captures first, no pipes on
    gates, invalid-greens filed honestly and re-executed hardened; (4) i18n en+am,
    R-14 queue, destructive-tier flags; (5) full correctness battery every
    step-commit regardless of BAT1, timing legs on wall power only, CI once per
    checkpoint, browser fixtures under the 0l heal+reproject law; (6) the close-out:
    full self-audit table EVERY id itemized (the grouped-row form deviation of S09 is
    tolerated, not preferred), ledger item-by-item, closing-set flips, deviations,
    inherited outcomes, core-edit enumeration, honest trail, the close-out SHA
    grounded by the owner BEFORE the advisor recount.
12. **CADENCE** — batches, one checkpoint per batch, the seven mandatory mid-batch
    stops (ruling needed; guard conflict; red battery; core-edit boundary;
    enumeration amendment; schema countersign; design conflict/gap).
13. **DESIGN FIDELITY** — mandate v9 binds; Doc 10 frames per the Batch 9 decision
    (Option A precedent); frame-mapping rows mandatory when frames exist; deviations
    in exactly three classes, all filed.
14. **HARD PROHIBITIONS** — no secrets/PII in prompts, commits, or captures; NO REAL
    CUSTOMER TEXT TO ANY PROVIDER outside production (the egress guard is law — KB
    fixture content is authored, never harvested from real conversations outside the
    ids-only top-10 seam); no invented permission keys; no document edits without
    escalate-first; no second indexer door; no green without pasted output; no CI
    polling; no second Claude process; no PARTIAL outside a named bucket; a false
    DONE is a Sev-1.
15. **LAST LINE, exactly:** `Then STOP.`

## RULES FOR YOU WHILE WRITING IT

- Every factual claim traces to the document, the repo, or a filed ruling. If you
  cannot cite it, cut it.
- The prompt states integrity facts about itself (step count 6; last line exact).
- Deliver it to the owner's machine (Filesystem tools when responsive; chat download
  + rename-safe wildcard copy otherwise), require commit + git-verify BEFORE any
  session reads it.
