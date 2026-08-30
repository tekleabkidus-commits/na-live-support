# ADVISOR-RULINGS-D11 — standing rulings of the Doc 11 advisor
_Filed at the opening of the Doc 11 inter-document window (2026-08-30), each
owner-confirmed in the advisor chat ("approved"). This file is the ruling record
STEP 0 of SESSION-11 cites; it commits together with the session prompt. Kit
grounding of record: v10 kit at 77fb302 (HEAD=origin/main), 052e3e4 in ancestry,
ANCESTRY-OK from the owner's terminal._

## R-D11-1 — Row 0a, the 0i browser pair: ORDER THE FIX (owner: "approved")

`tests/Browser/inbox-ticket-panel.spec.ts:333` (four consecutive sightings:
f81e9b4, 2a9d676, f62f1e4 + the S10 batch-C window) and
`tests/Browser/inbox-mobile-interactions.spec.ts:233` fail first-attempt and pass
on retry, failing the CI job by policy and masking Stage 4 on every push. S10
diagnosed them as timing waits in Doc 07 files and filed without touching them
(the Option-B refusal). A fourth generation does not inherit them.

Ruling: a **wait-hardening packet** lands as an EARLY SANCTIONED SIDE-ROW of
SESSION-11 (the S10 step-0.4 shape), escalate-first on Doc 07's files:
1. The session diagnoses both specs at cause and proposes the diff —
   **wait/setup hardening only; assertions byte-untouched or explicitly
   flagged** (the 7b2120a precedent).
2. The advisor countersigns BEFORE the packet lands.
3. Proof: both specs green **first-attempt on three consecutive local full
   browser runs** (repetition is the honest proof for a flake; it is not
   verdict-shopping because the fix precedes the runs), then the next
   checkpoint's single CI look shows Stage 4 without the pair's retry-red.
4. If diagnosis finds a PRODUCT defect rather than a test-timing defect, that is
   a MANDATORY STOP and a separate countersign — the packet does not absorb it.
5. BUILD-STATUS's 0i ledger for the pair is annotated at landing, citing this
   ruling and the fix commit.

## R-D11-2 — Row 0d, the [B8]/[B9] fidelity passes: NAMED DEBT, post-S11 window (owner: "approved")

Reasons: the one-Claude-session law means a fidelity-pass build session cannot
run beside SESSION-11; [B9] is blocked on a Batch 9 design freeze that was
ordered at R-D10-3 and never launched; [B8] is pixel debt on proven-functional
Doc 09 screens — real, not routing-blocking.

Terms:
1. Both passes file as **named debt rows** in BUILD-STATUS (SESSION-11 step 0
   writes the annotations, citing this ruling): [B8] = the fidelity pass over
   Doc 09's shipped screens against the frozen Batch 8 record (cfe305b, audit
   99/99); [B9] = the Batch 9 design track (R-D10-3 machinery) followed by the
   fidelity pass over Doc 10's four staff surfaces.
2. Trigger: the FIRST inter-document window after S11's acceptance. The sitting
   advisor launches the Batch 9 design track first, then orders ONE combined
   [B8]+[B9] fidelity build session and audits it.
3. The debt never goes silent: if that window closes without them, the v11 kit
   MUST carry both as its own row 0.

## R-D11-3 — Doc 11 design: SPLIT ruling; no new design batch (owner: "approved")

Finding, verified by the advisor against PROGRAM-REPORT-v1 and the owner's
ls-verified frozen listing (230 files total = the 217 of the v8-kit correction
+ the 13 Batch 8 files; count reconciled exactly): **the v10 kit's claim that
Doc 11's surfaces are undesigned is corrected on the record.** The program
report's undesigned list names Docs 09, 10, 12, 14, 17 — Doc 11 is not on it.
Batch 5 (which attached Doc 11) designed and froze:
- `B5-S09-organization-v1` (rows J01–J15) — Doc 11 §8's structure
  administration (Backoffice → Organization: Departments / Teams / Queues);
- `B5-S10-restructure-wizards-v1` (rows J16–J21) — FR-18's restructure
  wizards, carrying the ALREADY-FILED Batch-5 mobile deviation (Doc 11 §9
  wizard execution, overridden by the v3 every-feature-on-a-phone ruling,
  recorded in B5-S00-checklist-v3 and B5-S11-closing-audit-v2);
- `B5-S02-interventions-v2` (rows C01–C14) — supervisor interventions and
  explainability, most of whose RENDERING Doc 11 §3 assigns to Doc 15/07.
Filed as honest trail, no blame row (the verify-your-own-kit law working).

Ruling:
1. SESSION-11 builds **backend-first** regardless — matching Doc 11 §21's own
   build order (routing engine and its record before surfaces).
2. Doc 11's own UI steps (structure admin; wizards) bind at **FULL mandate
   fidelity** against `B5-S09-organization-v1` and `B5-S10-restructure-wizards-v1`
   with frame-mapping rows. The recorded Batch-5 mobile deviation rides as
   already-filed — cited, not re-filed.
3. Genuine gaps — the routing-record timeline expandable, any queue-detail
   state no frame draws — are **class-2 fills from the nearest drawn patterns**
   (the B12 timeline system-event idiom for the record; B5-S02's drawn rows
   where applicable), each owner-ruled at its mandatory batch stop. This is not
   a whole-document gap: no Option A bucket, no Batch 10.
4. B5-S02 surfaces whose rendering belongs to Doc 15/07 stay SEAMS: SESSION-11
   supplies the data contracts; the frozen frames bind those documents'
   sessions later.

## R-D11-4 — [D11] closing-set enumeration (register over summary)

The advisor's sweep of BUILD-STATUS's own rows finds the [D11] inheritance in
three shapes (anchor line numbers recorded at sweep time; the session
RE-LOCATES every row BY ITS TEXT at flip time — the S10 anchor-drift law):

**Tagged [D11] register rows (flip at close):**
1. Doc 09 FR-12 — PARTIAL [D11] (near BS:205): the live queue-wait estimate is
   Doc 11 data (Doc 11 FR-13/T-06 feed Doc 09's handoff copy and FR-28
   `{{queue_wait}}` seam).
2. Doc 09 FR-13 — PARTIAL [D11] (near BS:206): live RG-queue delivery (Doc 11
   `is_rg` queue + FR-08 step-2 finality).
3. Doc 08 FR-15 — PARTIAL [D11]/[D12] (near BS:822): the Doc 11 HALF only —
   RG-queue routing; the [D12]/[D14] halves remain.

**Doc 03 re-proof bucket rows (flip at close):**
4. Doc 03 FR-03 — PARTIAL (near BS:4863): REAL membership re-proof.
5. Doc 03 T-03 — PARTIAL (near BS:4895): Doc 11 binds real membership sources.
6. Doc 03 AC-03 — PARTIAL (near BS:4925): the re-proof bucket, plus the
   top-table row-11 "AC-03 partial (Doc 11 re-proof bucket)" annotation.

**Seam obligations recorded on DONE rows (mandatory plan rows; annotation
updates, not status flips):**
7. Doc 03 FR-09 (near BS:4869) — queue pull-in grants become real (Doc 11
   FR-15/T-07/AC-05).
8. Doc 03 EC-04 (near BS:4909) — the drawn membership warning state arrives
   with Doc 11's FR-04 config-error badges.
9. Doc 07 FR-05 (near BS:1593) — queue-view TYPE currently rides
   `fixtures/inbox/seams/doc11-queue-views.json`; Doc 11 makes queue views real.
10. Doc 07 FR-28 (near BS:1616) — `agent.status_changed` gets its first real
    consumer (Doc 11 FR-05/T-03).
11. Doc 06 FR-22 (near BS:4058) — transfer's fail-closed pre-Doc 11 scope
    answers become real (rides rows 4–6's re-proof).

**The pin (ruling owed at plan time):**
12. Doc 07's Unassigned×Own-scope pin — PINNED empty at S07 with the owner
    override left open BY NAME, Doc 11 to revisit. It lives in the S07 close
    record as prose, NOT as a tagged register row — the session locates the
    filed text, quotes it, and raises the revisit as a PLAN-TIME STOP for an
    owner ruling (the natural Doc 11-era answer — queue-membership-scoped
    visibility for self-claim — is an option to present, not a decision made).

SESSION-11's step-0 enumeration is checked against the register's rows, never
this list's summary; a mismatch is investigated, never averaged.

## Standing context this generation inherits unchanged

Docs 01–10 closed and owner-accepted (Doc 10 acceptance 052e3e4; close-out
03cdfea; v10 kit grounded 77fb302); escalate-first over all of Docs 01–10;
permission catalog CLOSED at 114 (executed count, never read); standing rules
EIGHTEEN (docs/PROJECT-HANDOFF-v7.md §6 in-repo copy authoritative); governance
incidents SIXTEEN plus S10's three named session-discipline findings (never
Pest beside gates; the rival-session recipes; KEEP-AND-VERIFY ×4); risks 0a–0l
standing (0k escalate-first; 0l heal+reproject binds all new browser fixtures);
the paste channel is a known adversary — rule-18 grounding both ways, register
rows over summary lines, captures over prose, a truncated ruling is not a
ruling. Open owner items at every checkpoint: 0b staging-key rotation before
any production key (incident #16); 0c Addis AI DPA-or-switch before production
egress (ADR 0019, [OA]); `[OA]` Doc 05 rows stay parked.
