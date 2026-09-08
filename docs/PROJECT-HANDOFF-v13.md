# PROJECT-HANDOFF v13 — Na Live Support
_Written by the retiring Doc 13 advisor AT acceptance — not before. Doc 13
accepted 00e2473 (docs/S13-ACCEPTANCE.md), HEAD = origin/main at grounding,
2026-09-08. The next document is **Doc 14 — SMS Notification Module**; you
are the TENTH advisor. Everything here was true at 00e2473. VERIFY every
load-bearing claim against the repo before ruling — this generation's own kit
(v12) carried two false design claims ("B3-S06 closing audit"; "three B3-A01
rows") and the advisor's own recount miscounted once ("nine" for eight). Cite
the repo, not the handoff, including this one._

## 1. What this project is
Na Live Support — multi-tenant, AI-first, mobile-first ticket support for
betting companies. Three channels: Telegram (built), website live chat
(built, Doc 13), SMS notify-only (**Doc 14 — yours**). Laravel/Postgres/
Redis/Reverb + Inertia/Vue3/TS + Meilisearch + a Preact widget package.
19-document series; docs are law; registries normative (Q9). The owner
(Kidus) is a non-programmer PM who verifies via pasted terminal output; one
command block + one sentence per message; ONE Claude Code session at a time.

## 2. State at handoff — verified at 00e2473
- **Docs 01–13 CLOSED AND ACCEPTED.** Doc 13: 96 ids 72 DONE / 24 PARTIAL /
  0 NOT DONE (every PARTIAL bucketed: [OA] 13, [D19] 6, [D18] 3, [D15] 2,
  [D14] 2, [D09] 2, [D07] 1, [OA-voice] 1); frame register 75 rows 69 PROVEN
  / 4 PARTIAL / 1 BUILT / 1 NOT BUILT; ledger 12/12; [D13] closing set 15/15;
  ten core-edit packets landed under countersign, one refused (P-STREAM), one
  withdrawn, ZERO landed-then-escalated. Verdicts of record: Batch C run
  33990000043 on f72da6d; 13b run 34155857302; 13c run 34180127974 (#320).
  ~92% to MVP.
- Permission catalog CLOSED at **114** (verify EXECUTED). SCHEMA AMENDMENTS
  2–7 (7 = Doc 13's tables + RIDERS 1–2; RIDER 1 = `vault_secrets`, the C-13
  interim, ADR 0021 — Doc 18 retires it). ADRs 0003–0021. A-1…A-62 (A-61 the
  webchat anchor; A-62 the ContrastValidator shared kernel — Doc 16 consumes).
  T-03 ENUMERATION AMENDMENT 1 (Doc 13). Doc 11 CHANGE-LOG entry (P-QPOS).
- Doc 13 delivered: the webchat adapter through the conformance suite with
  zero core edits; the visitor API on Doc 02's token stack; signed identity
  with rotation; pre-chat/offline forms on Doc 12's contracts; the queue-
  position feed (P-QPOS); the loader + iframe isolation + hostile-host
  suite; the panel at Batch 3 fidelity (loader-drawn launcher ≤ 300 ms);
  uploads on Doc 04's chain; Help on Doc 10's mounted API; the Widget
  Designer (B3-S08 v3) with the contrast hard gate; the HARNESS (two local
  origins over TLS) in dev AND in CI's Stage 4; the SEC-06 transcript door.

## 3. The repo and how truth works
github.com/tekleabkidus-commits/na-live-support (private; the owner's plain-
terminal `git log` paste is the ONLY grounding channel). BUILD-STATUS.md is
the register (rows over summaries — the recount caught eight untagged
PARTIALs the close-out prose had named honestly; rows are the record).
ANSWERS-FROM-DOC-AUTHOR.md: Q1–Q9, A-1…A-62, R-1…R-26, amendments, riders.
docs/ADVISOR-RULINGS-D13.md + S13-ACCEPTANCE.md = this generation's rulings.
docs/evidence/S13-design/register.md = the frame register (recounted from
rows, twice corrected downward by the session itself — honest).

## 4. Inherited rows — Doc 14's opening table (verify each against BUILD-STATUS)
| Row | Item |
|---|---|
| **0a** | **PREREQUISITE GATE: SMS gateway sandbox credentials.** HOW-TO-USE names them owner-supplied for Doc 14. The mock-sms service (docker/mocks/sms) and the SMPP sidecar dir exist since S0. Put it to the owner FIRST: a sandbox (any local aggregator with a test account, or an SMPP simulator) → live legs proven; none → the Doc 05/Doc 13 [OA] shape (mock-sms as a FIXTURE, never called proven; a named [OA] bucket with the credential as unblocker). Silence is not an option. |
| 0b | **The public origin for Doc 13's [OA] rows** (R-D13-1): 13 id rows + frame rows 30/52 wait on a staging subdomain + TLS (+ the Android WebView build). A short owner-assisted addendum session closes them — never folded into Doc 14. The owner declined to provision it during S13 ("don't want to test now"); carry it, do not let it go silent. |
| 0c | Staging-key rotation before any production key (incident #16) — owner, standing. |
| 0d | Addis AI DPA-or-switch before production egress (ADR 0019) — owner, [OA]. |
| 0e | Doc 05 [OA] rows (staging Telegram Business account) — parked. |
| 0f | Wall-power figures owed: three p99 tails (Rbac 0.364 vs 0.3 ms = 0d; Foundation events-insert 1.138 vs 1.0; Doc 09 decision write 4.127 vs 3.0 — named for Doc 09); the /inbox Lighthouse 2.0 s vs 1.5 s (dev-indicative). |
| 1 | **The [D14] closing set — enumerate from the register's OWN rows.** Literal `[D14]` hits at 00e2473: 8 (verify). Known shapes (verify, don't inherit): Doc 13 FR-15 + US-03 (the ticket-created SMS on the offline form); Doc 08 FR-15's [D14] SMS half (marketing suppression consumers); Doc 12 FR-03's SMS action (template-triggering seam — Doc 12's engine calls, Doc 14 sends); Doc 06's notification touchpoints; Doc 16's notification registry deliveries. |
| 2 | **Named findings for OTHER windows, carried by name:** D07-N1 (no staff read door for the widget's page context), D07-N2 (`inbox:views-rebuild` throws on a null SLA timestamp — also breaks the 0l heal on warm DBs), the inbox-ticket-panel.spec.ts:334 flake (R-D11-1's fix did not hold); D09-N1 (disclosure line on a kill-switch handoff), **D09-N2 (the switch grid names telegram|web — an operator cannot kill the chatbot on the widget alone; RISK)**; F-13b-5, F-13b-6 (Doc 12 test debt: an unawaited confirm-count made F1's K05 leg skippable); the AC-03 soak (Doc 04 — documented flaky in the Makefile, "not claimed as proven"); F-C4 (F1-B12 fresh-DB order dependence); the three Doc 12 door findings from F1; F1-FO-1…8. Not yours to build unless Doc 14 touches them. |
| 3 | Doc 13's own carried items: P-STREAM refused (T-04 widget half PARTIAL [D09] — reopener = Doc 09's per-sentence trap); two 0i-class sightings (widget-csat 375 — a 403 on the score POST; widget-acceptance S11 focus trap 375) — threshold 3-in-10, the instrument arms at three; the 21 ms paint margin / 24 B launcher-document margin (the loader budget is the document's law, not re-settable). |
| 4 | Risks 0a–0m stand; 0l binds every browser fixture AND scene budget (dedicated customers/VISITORS per scene; the full battery drifts shared fixtures; reseed + `inbox:views-rebuild` is the sanctioned heal — see D07-N2). **New this generation:** the HARNESS is part of CI's Stage 4 (5a125ba, c5d7053 healthchecks, f72da6d bucket + media host); CI Stage 4 uploads no Playwright artifacts (log downloads time out on this network — the owner reads the run page); the job exit is 1 on ANY flaky even when all tests pass on retry (the accepted policy — read the Playwright summary line, not the job colour). |
| 5 | R-14 Amharic native review: cumulative, +336 keys from Doc 13 (23 destructive). Owner action. |
| 6 | Platform NNRR unchanged + new: F-C3 (a shared-shell axe sweep runs whenever a step touches the Backoffice shell); the targeted set ALWAYS includes the contract suites of every registry a step writes (R-D13-6 amendment); a publisher called outside a transaction is invisible to Feature tests (P-KB-VIEWED's lesson); Octane's long-lived workers serve stale translations until reloaded; Vue changes reach the container only through `make up`; the CI Node 20 deprecation notices (Doc 18). |
| 7 | WSL/hygiene: MTU 1350 OWED PERMANENT (applied on nearly every push this generation); `/etc/wsl.conf generateResolvConf=false` still owed; HTTP pushes over ~10 MiB fail with 408 on this network — push commits one at a time; Lighthouse drops Chrome temp profiles under Windows paths into the repo root (two were committed and removed at 3b7c0c2; ignore rules landed). |

## 5. Decision log highlights this generation
R-D13-1 ([OA] for live-origin legs; the local two-origin HARNESS named as a
harness — held); R-D13-2 (FULL fidelity, Batch 3; the two kit claims
corrected; 36/44 px apply button ruled system-wins); R-D13-3 (no external
credential gate); R-D13-4 (closing set 15/15 — the ChannelIdentityMap
packet STRUCK on repo evidence, A-61 remained); R-D13-5 (no failure
vocabulary as a gate; Ethiopic at 375 on fallback fonts first); **R-D13-6
(owner directives: PER-BATCH cadence — targeted suites per step, full
battery at each checkpoint; the PC-POWER rule as amended: never ask the
owner to plug in, never wait for power, state the power line, label
discharging timing figures "battery-run, indicative")**; **R-D13-7 (a rider
whose table holds secret material, PII or money-adjacent data is a
countersign-BEFORE-migration stop)**; A-61; A-62; ADR 0020 (preact +
signals, hand-rolled Pusher client); ADR 0021 (vault_secrets interim).
Packets P-WIDGET, P-N14, P-MEDIA-URL, P-QPOS, P-KB-VIEWED, P-F1-SHOTS,
P-RIDER1-FIX, P-NAV-WIDGETS, P-FRAME-ANCESTORS, P-CAL-WEEKDAY — every one
countersigned BEFORE landing; P-STREAM REFUSED by its own safety condition
(the visitor must never see text Doc 09's traps would refuse).

## 6. Standing rules — EIGHTEEN, unchanged
Authoritative list: docs/PROJECT-HANDOFF-v7.md §6 (in-repo). This
generation's demonstrations: rule 18 both ways (the session held on a
stray "calculator" line and on a look order pasted with a literal `<SHA>`;
the session corrected the advisor's arithmetic); rule 15 (six CI reds, six
causes, six fixes — fix-first every time; Option A applied only after the
cause was PROVEN independent of Doc 13); rule 11 (a first battery killed by
the host for low memory relaunched, never salvaged). Rule 13 as the owner
now has it: the correctness battery per R-D13-6's cadence, regardless of
power; no power requests.

## 7. Design state
`design/frozen/` = 204 at 00e2473 (ls-verify). Batches frozen: B1–B9, B12;
every built surface at frozen fidelity. **Doc 14 is on PROGRAM-REPORT-v1's
undesigned list (Docs 09, 10, 12, 14, 17)** — expect the Option A shape
(R-D09-2/R-D10-3/R-D12-2: backend-first, functional UI from design-system
components riding a [B-batch] bucket, a design track when the owner orders
one). VERIFY both directions anyway — the drawn-work check has never come
back empty-handed in six generations (Doc 13's found two false kit claims).
Nearest drawn idioms for Doc 14's screens: B5-S07 builder chrome, B5-S10
wizard/confirmation, B9's Workflow list/editor shells (Doc 12's, frozen at
67dc4e4), B2-S06 inline refusals, the Doc 10 per-language completeness dots.

## 8. Doc 14 traps — verify each against the document text before the prompt
- **Notification-only is STRUCTURAL** (BR-01): no free-text send exists at
  any permission level; inbound is STOP-parsing only; the sabotage is a
  route that could carry free text → red. The catalog carries `sms.send`
  (verify its semantics in Doc 03's text — template-triggering, not
  composing).
- **Guard-function truth table** (FR-03/FR-11): ordered guards with
  skip{reason} logged per message; every branch a test; opt-out ABSOLUTE
  (FR-14) except the document's named exceptions — read them exactly.
- **DLR state machine with an honest `expired`** (FR-11/FR-12): `sent` never
  rolls back (Doc 04's law), `delivered` only on a real DLR, `expired` when
  the carrier never answers — never inferred as delivered.
- **Amharic = UCS-2 = 3–4× segments** (FR-04/FR-05): the editor shows the
  encoding badge and segment boundaries; cost = segments × rate card; the
  stop-word ተወው (FR-15) is the ONE Ethiopic string in a source document —
  it must survive every layer byte-exact.
- **Gateways hold credentials as vault refs** (FR-07) — RIDER 1's
  `vault_secrets` interim is the store until Doc 18; R-D13-7 makes any new
  secret-material table a countersign-before-migration stop.
- **Quiet hours read Doc 12's REAL calendars** (FR-16) — the HoursContract
  is live; consume, never re-implement.
- **Broadcast audience filters over customers** (FR-17) ride Doc 08's
  marketing-suppression consumers (Doc 08 FR-15's [D14] half closes here)
  and N-14's spam/test exclusion (the widget is the test-traffic carrier —
  MetricExclusion's second half, P-N14).
- **SMPP is NOT in PHP** (ADR 0001): the smpp sidecar dir exists since S0;
  a named-aggregator/http driver is the v1 path; the sidecar is [D18]-shaped
  unless the owner supplies an SMPP simulator.
- **Every SMS consumer is a durable consumer** (ADR 0011): registry + drill
  pin + the Doc 06 NFR-06 fixture in the SAME commit — and the contract
  suite of every registry the step writes runs in the targeted set.
- **Cost is a budget axis** (FR-19): per-tenant monthly caps and the
  threshold alerts through Doc 16's notification registry (a seam).

## 9. The advisor's duties, in order (do not reorder)
1. Kit grounding: owner commits these four files, pastes `git log --oneline
   -3` + `git merge-base --is-ancestor 00e2473 HEAD && echo ANCESTRY-OK`.
2. Rule on row 0a (the gateway sandbox) with the owner; restate row 0b once.
3. Read the record; build your PRIVATE Doc 14 enumeration (calibration from
   an automated pull of the HIGHEST id per family, gaplessness UNVERIFIED:
   FR-24, NFR-06, US-07, BR-07, T-08, SEC-06, EC-12, FS-05, SS-04, AC-10,
   R-05 — your count from the text governs).
4. Design verification both directions; put the design ruling to the owner.
5. Enumerate the [D14] closing set from the register's rows.
6. Write SESSION-14 per docs/SESSION-PROMPT-SPEC.md + your rulings file;
   deliver as downloads into C:\Users\Kidus\Downloads\NASUPPORT\14\; owner
   commits; grounding; fresh solo terminal reads FROM DISK.
7. Audit the plan family-exact; batch rhythm per R-D13-6; countersign-
   before-landing; the close gate; the recount FROM ROWS; the acceptance;
   the v14 kit AT acceptance.
