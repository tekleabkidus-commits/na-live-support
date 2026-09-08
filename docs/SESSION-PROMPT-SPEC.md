# SESSION-PROMPT-SPEC.md — how the advisor writes SESSION-14 (and every later session prompt)
_v13, updated at the Doc 13 acceptance. The exemplars are
`prompts/SESSION-12-doc12-automation.md` and `prompts/SESSION-13-doc13-widget.md`
(in-repo) — mirror their structure; SESSION-13 ran 13 steps / 3 batches + three
post-close steps to a 96-id recount with ten countersigned packets and zero
landed-then-escalated, and its §10 SUBTLE PARTS (22 items verified against
the document text) is the current best shape. `prompts/SESSION-F1-fidelity.md`
remains the exemplar for a fidelity-only session._

## Non-negotiable mechanics
- The session reads the prompt FROM DISK (`prompts/SESSION-14-doc14-<slug>.md`),
  launched with: `Read prompts/SESSION-14-doc14-<slug>.md from disk and follow
  it completely.`
- Integrity check inside the prompt: exactly **6 PROTOCOL steps**; last line
  exactly `Then STOP.`; the session states both in its first report.
- The prompt commits BEFORE launch (with the advisor rulings file); the owner
  grounds the commit.
- The plan is delivered UNCOMMITTED (rule 12), audited family-exact against
  the advisor's PRIVATE enumeration, commits as the plan of record at
  approval. One row per id; counts by family; a mismatch is investigated,
  never averaged.
- **Cadence is R-D13-6 unless the owner re-directs**: targeted suites per
  step (the DOC suites + every touched document's suites + the CONTRACT
  suites of every registry the step writes + the shared-shell axe sweep when
  the shell is touched + gates + lint); the FULL battery at each batch
  checkpoint and at the close; CI one look per checkpoint. Two stops survive
  any cadence: core-edit packets stop for countersign; the close-out stops
  for owner review + grounding.
- **Power (R-D13-6 as amended)**: never ask the owner to plug in; never wait
  for power; run every battery, browser leg and timing leg when reached;
  state the power line in each report; discharging timing figures are
  labelled "battery-run, indicative"; a laptop-killed run is relaunched,
  never salvaged.

## The prompt's required blocks (SESSION-13's §1–§14 shape)
1. Role + verification contract (the session may correct the advisor's kit
   with repo evidence — say so).
2. Solo-process guard — the FULL rival recipes (bg-pty-host; `claude daemon
   run`; the Windows Desktop bridge + `taskkill.exe`; transports with no
   child CLI are NOT sessions; two spaced looks; /proc sweep; NEVER Pest
   beside gates; scratchpad while a battery runs).
3. Prompt integrity check.
4. Standing rules from minute one (18; restate 11/13/15/17/18 — 15 with the
   CI shape as it now is: the harness stack in Stage 4; no artifacts upload;
   the job exits 1 on any flaky even at 0 failed — read the Playwright
   summary line; a startup failure with the billing annotation is the
   billing class, not a verdict; a red in another document's file gets the
   Option-A PROOF before it is called theirs).
5. STEP 0: the prerequisite ruling of record (row 0a — gateway sandbox; if
   absent, the [OA] bucket named plainly with mock-sms as a FIXTURE) +
   rulings-filed proof + the [D14] closing set enumerated from the register's
   OWN rows + catalog count EXECUTED (114) + every `sms.*` key the document
   routes to verified EXISTING or STOP (the catalog holds eight sms.* keys —
   verify which the document actually names) + the settings-vs-permissions
   split stated (sms.quiet_hours, sms.quota.segments etc. are SETTINGS).
6. Reading list with WHY per file — the document end-to-end; BUILD-STATUS's
   S13 close + 13b/13c addenda + the findings block (D07/D09/F-13b) + risks
   0a–0m; ANSWERS (A-59/A-60, A-61/A-62, RIDERS 1–2, ADR 0021); the substrate
   AS CODE (Doc 04's delivery state machine + dead-letter/re-drive — the
   DLR machine wires INTO it; the egress proxy allowlist for gateway hosts;
   Doc 12's HoursContract for quiet hours and its SMS action seam; Doc 08's
   marketing-suppression consumers + MetricExclusion's two halves; Doc 16's
   notification registry contracts; Doc 13's offline-form ticket-created
   seam; `vault_secrets` + VaultInterim for gateway credentials; the
   `sms` Horizon family since S0; docker/mocks/sms; sidecars/smpp); both
   prior approved plans.
7. Escalate-first, stated BEFORE scope, with Doc 14's pressure points named
   (Doc 04's delivery state machine above all — `sent` never rolls back;
   Doc 08's customers for audiences; Doc 12's calendars; the vault interim;
   the notification registry).
8. Scope: the fidelity sentence verbatim (Option A shape if undesigned);
   out-of-scope handoffs; pre-agreed buckets AS A TABLE each with its
   unblocker ([OA] gateway; [D15]; [D16]; [D18] the smpp sidecar and the
   real vault; [D19]); dev-scale figures for every §12 row, misses flagged.
9. Inherited rows table with dispositions (handoff v13 §4 rows 0a–7).
10. THE SUBTLE PARTS — each verified against the document text with ids
    (notification-only as structure; the guard truth table; the honest
    `expired`; UCS-2 segments and ተወው byte-exact; opt-out absolute; quiet
    hours on real calendars; broadcast audiences under suppression + N-14;
    cost accrual and correction at DLR; vault refs only).
11. PROTOCOL (exactly 6): plan-first with plan-time STOPs; build (one commit
    per step, registries as you go, schema = filed amendment countersigned
    BEFORE migration; a secret-material rider = countersign-before-migration
    per R-D13-7); sabotage floor of TEN with the document's mandated
    falsifications (include one free-text-send falsification and one
    opt-out-bypass falsification); i18n en+am through R-14; batteries per
    R-D13-6; close-out ending with its SHA, grounded BEFORE the recount,
    recount FROM ROWS, every PARTIAL in a named bucket (an untagged PARTIAL
    is a prohibited row — the S13 recount lesson).
12. Cadence: R-D13-6; the seven mandatory stops.
13. Design fidelity: per the mandate + the sitting advisor's ruling.
14. Hard prohibitions (+ no free-text send path at any permission level; no
    inferred `delivered`; no gateway credential in any file, fixture or
    capture; no real phone numbers in any non-production origin).

## Session-side disciplines proven at S13 — bake them in
- Fail-first legs land RED before the fix, captured — including for
  assertions the plan named and never executed (five such executed at 13b
  found two real defects).
- A CI red is diagnosed from the run's own log; when the artifact is
  missing, the state is reproduced locally in CI's shape (fresh DB, CI's
  order); the cause is reported BEFORE landing.
- Never `git add -A` unread; F1's specs now write shots to an untracked path
  (P-F1-SHOTS) — the drift rule is retired, the habit stays.
- After any Lighthouse/Chrome tooling run: `git status --short` for stray
  Windows-path directories at the repo root (ignore rules exist since
  3b7c0c2; verify they hold).
- The owner reads the run page; the session records what he reads plus what
  the API shows — one look per order, never a poll, never a look on a run
  that does not exist.
