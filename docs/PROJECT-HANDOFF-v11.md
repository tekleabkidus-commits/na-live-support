# PROJECT-HANDOFF v11 — Na Live Support
_Written by the retiring Doc 11 advisor at S11 acceptance (2026-08-31). The next
document is **Doc 12 — Automation, SLA, Forms & Custom Fields**; you are the
EIGHTH advisor. Everything here was true at 55dbf01; VERIFY the load-bearing
claims against the repo before ruling on them — this generation caught kit-claim
errors for the third generation running (Doc 11 "undesigned" was partly false;
the pin's filed location was wrong; the routing record WAS drawn). Cite the
repo, not the handoff._

## 1. What this project is
Na Live Support — multi-tenant, AI-first, mobile-first ticket support for
betting companies ("the next Zendesk for betting companies"). Three channels:
Telegram (built), website live chat (Doc 13), SMS notify-only (Doc 14).
Laravel/Postgres/Redis/Reverb + Inertia/Vue3/TS + Meilisearch. 19-document spec
series; the docs are law; registries normative (Q9). The owner is a
non-programmer PM who verifies via pasted terminal output; one command block +
one sentence per message; he runs ONE Claude Code session at a time.

## 2. State at handoff — verified, not asserted
- **Docs 01–11 CLOSED AND ACCEPTED.** Doc 11: acceptance 55dbf01 (S11-ACCEPTANCE
  filed + top-table row flipped, one commit), close-out 2f95c67, CI verdict of
  record 33366254848 GREEN on e01f2ad (the close's first look was red on a REAL
  fixture-shape defect — fixed at cause, the fix's run became the verdict, the
  closure law working). Final audit 87 DONE / 3 PARTIAL (all [D19], figures
  stated plainly: NFR-05 68.0 s vs L2 60 s among them) / 0 NOT DONE over 90 ids;
  sabotage ledger 14/14; the full [D11] closing set 12/12 flipped.
- ~82% to MVP. Permission catalog CLOSED at **114** (verify EXECUTED:
  `php -r "echo count(require 'registries/permissions.php');"` — never read a
  number from a file). SCHEMA AMENDMENTS 2–5 landed (5 = Doc 11's eleven
  tables + the rescue_flags addition). ADRs 0003–0019. Event catalog grew by
  Doc 11's FR-19 family — verify the executed count.
- Doc 11 delivered: the 5-step routing pipeline with per-ticket decision
  records, RR/load/skill strategies (T-08 determinism harness incl.
  same-second ties), the starvation cap as property-tested physics, RG
  finality with its one audited exception, real Team/Dept membership (A-50
  user_departments; THE scope-widening transition proven), temporary
  membership with dual-path expiry, rescue (heartbeat/orphan/courtesy-hold),
  restructure wizards, structure-admin UI at full fidelity (B5-S09/S10), the
  routing-record timeline expandable (B5-S02 §D), estimator bands (SEC-05
  absence-proven).

## 3. The repo and how truth works
github.com/tekleabkidus-commits/na-live-support (private; robots block advisor
fetch — the owner's plain-terminal `git log` paste is the ONLY grounding
channel). BUILD-STATUS.md is the register (rows over summaries, always);
ANSWERS-FROM-DOC-AUTHOR.md holds Q1–Q9, A-1…A-58, R-1…R-26, the amendments.
docs/ADVISOR-RULINGS-D11.md + docs/S11-ACCEPTANCE.md are this generation's
rulings. CI = one look per checkpoint, verdicts owed forward, billing
annotation read first.

## 4. Inherited rows — Doc 12's opening table (verify each against BUILD-STATUS)
| Row | Item |
|---|---|
| **0a** | **[B8]/[B9] fidelity debt — TRIGGERED.** R-D11-2's own term: the first post-S11 window is NOW, so this kit carries it as row 0. Your early duty: put the sequencing to the owner — (i) run the Batch 9 design track (R-D10-3 machinery, DESIGN-SESSION-B8.md as template) then ONE combined [B8]+[B9] fidelity session BEFORE/beside SESSION-12, or (ii) an explicit owner-signed deferral with a new trigger. Silence is not an option; the debt never goes quiet. |
| 0b | Staging-key rotation before any production key (incident #16) — owner, standing at every checkpoint. |
| 0c | Addis AI DPA-or-switch before production egress (ADR 0019) — owner, [OA]. |
| 0d | Staging Telegram Business account for Doc 05's [OA] rows — owner; rows stay parked. |
| 1 | The [D12] closing set is the heart of Doc 12 — enumerate from the register's OWN rows. Known families to sweep (verify, don't inherit): Doc 11's hours seams (A-53 HoursContract fixture → real calendars; FR-07 automation trigger; FR-12 SLA-at-risk input; rule-driven tag matching; pull-in shift-end default), Doc 08 FR-15's automation-suppression half, Doc 10 P7's MenuDesigner tap-flow engine, Doc 05's connection-tag reopener (C.3 fixture seam, resolver returns null in production), Doc 06's transfer/SLA touchpoints. |
| 2 | Risks 0a–0m stand. 0k/0m annotated RESOLVED-BY-ISOLATION at cab5cf5 (letters stay; recurrence re-opens). ViewDeltaProtocolTest = Doc 07 first sighting, filed-not-lettered, letters on recurrence. 0l binds every browser fixture (dedicated fixed-id rows + heal-by-reprojection; S11's close-out red was this class's fixture-shape cousin — scene budgets matter too). |
| 3 | R-14 Amharic native-review queue (cumulative; +214 from Doc 11, destructive tier flagged) — verify totals from the queue file. |
| 4 | A-57 (one live pull-in per agent) and A-58 (10-min depth-only estimator constant) — filed interpretations with reopeners. |
| 5 | Platform NNRR items: per-door shouldRenderJsonWhen interim (evidence accumulating), partition-maintenance fold-in, Doc 01 QuotaTest order-dependence, the Doc 07 channel-health ↔ AI-provider cross-link, FrankenPHP nightly note, index DDL migrator-owned. |
| 6 | WSL: /etc/resolv.conf vanishes after `wsl --shutdown`; the working fix is the WINDOWS GATEWAY as nameserver (external DNS blocked on this network). The permanent fix (/etc/wsl.conf `generateResolvConf=false`) is STILL OWED — recurred again this generation. eth0 MTU 1350 fix on TLS hangs; a third MTU recurrence makes it permanent per the standing note. |

## 5. Decision log highlights this generation (full texts in ANSWERS + ADVISOR-RULINGS-D11)
R-D11-1 (0i pair FIXED — 13 insertions, three first-attempt greens ×proofs;
Stage 4 unmasked for every push since); R-D11-2 ([B8]/[B9] debt, now row 0a);
R-D11-3 (design split — B5 drew Doc 11's admin+wizards; corrected twice more by
session evidence); R-D11-4 (closing-set sweep; the pin's true locations);
A-49 assignBySystem + A-50 user_departments (the derivation self-correction is
the model for honest interpretation work); A-51…A-58; STOP-E: the
Unassigned×Own pin KEPT by owner override, revisit CLOSED; P-C1…P-C6 packets +
the Doc 02 test-isolation side-row (all countersigned-before-landing —
escalate-first honored SEVEN for seven this generation, zero landed-then-
escalated).

## 6. Standing rules — EIGHTEEN, unchanged
The authoritative list stays docs/PROJECT-HANDOFF-v7.md §6 (in-repo). Re-read
it there. This generation's demonstrations: rule 18 both ways (the session
refused MY truncated rulings twice and was right both times — answer the tail,
never guess); rule 11 (one false green self-caught in under a minute, amended,
filed); rule 15 (a red close-out look → fix-first → the fix's run = verdict).
The paste channel truncates MID-RULING as well as mid-report: end every ruling
block with an explicit "END OF RULING — nothing follows this line."

## 7. Design state
design/frozen/ = 230 files (217 + Batch 8's 13; ls-verify yourself). Batches
frozen: B1–B7, B12, B8. **Doc 12's surfaces: the program report's undesigned
list names Docs 09, 10, 12, 14, 17 — so expect the Option A shape (backend-
first + parallel design track + UI on a [B-batch] bucket). VERIFY both
directions against PROGRAM-REPORT-v1 + the frozen listing before ruling; that
verification found drawn Doc 11 work three times this generation.** Nearest
drawn material for Doc 12 reference idioms: B5-S07-dashboard-builder-v1 (Doc
15's builder — an idiom source, NOT Doc 12's form builder), the B5-S10 wizard
patterns, B12's timeline idiom. The Batch 9 design track (row 0a) may run in
the same window — sequence deliberately.

## 8. Doc 12 traps — verify each against the document text before the prompt
- The rule engine CALLS Doc 11's routing actions — it must consume
  RoutingPipeline/PlaceInQueue as doors, never re-implement placement; the
  decision record must show "automation" triggers distinctly (FR-17's trigger
  vocabulary already carries it).
- SLA clock math meets business-hours calendars: A-53's HoursContract is the
  seam Doc 12 makes real — pause/resume semantics are property-test territory
  (the starvation-cap precedent for "physics, not configuration").
- Doc 08 FR-15's automation-suppression half closes here; Doc 14's SMS half
  does NOT — keep the split.
- The MenuDesigner flow engine (Doc 10 P7) has both halves waiting; the
  binding contract is already committed.
- Custom fields will meet the form builders and Doc 06's ticket schema —
  expect a SCHEMA AMENDMENT with grant physics early, and watch for EAV-vs-
  jsonb being a filed interpretation, not a silent choice.
- Automation is where "second implementation of an existing check" pressure
  peaks: every condition the engine evaluates that Doc 03/06/11 already
  enforces must call the owning door.

## 9. The advisor's duties, in order (do not reorder)
1. Kit grounding: owner commits these four files, pastes
   `git log --oneline -3` + `git merge-base --is-ancestor 55dbf01 HEAD &&
   echo ANCESTRY-OK`.
2. Rule on row 0a (the [B8]/[B9] sequencing) with the owner.
3. Read the record; build your PRIVATE Doc 12 enumeration (never shown to any
   session; family counts from section text, wary of the cross-doc citation
   trap).
4. Design verification both directions; put the design ruling to the owner.
5. Enumerate the [D12] closing set from the register's rows.
6. Write SESSION-12 per docs/SESSION-PROMPT-SPEC.md + your rulings file;
   deliver as downloads; owner commits; grounding; fresh solo terminal reads
   the prompt FROM DISK.
7. Audit the plan family-exact against your private enumeration; then the
   batch rhythm: report → owner grounding → acceptance; countersign-before-
   landing for every core edit; the close gate (owner grounds the close-out
   SHA BEFORE your recount issues; acceptance conditional on a green verdict
   of record).
