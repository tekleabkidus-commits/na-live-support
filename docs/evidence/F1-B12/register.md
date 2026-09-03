# SESSION-F1 · [B12-batch] fidelity register — Doc 12 Workflow surfaces vs `design/frozen/B9-S05…S13` (67dc4e4)

Legend as in `docs/evidence/F1-B8/register.md`: **DONE** = built and proven (the proof named) · **PARTIAL** = only with a named follow-on · **NOT-DONE** = out of reach, reason + follow-on. Deviations are in `deviations.md` (B12-D01…D42, findings F01…F03). Build shots under `shots/`.

## 0. Proofs of record (the bucket)

| Proof | Evidence | Verdict |
|---|---|---|
| Browser record: `workflow-screens` (byte-untouched) + `f1-b12` | `docs/evidence/F1-B12/b12-specs-09.txt` | 39 passed · 0 skipped · EXIT 0 (records 01–08 are the fix-first runs; record 08 had M02 skipped for want of an execution row — record 09 produces one through the built schedule sweep) |
| Pest doors (Feature/Automation · Csat · Fields · Forms · Hours · Macros · Sla · Workflow), unmodified | `docs/evidence/F1-B12/b12-pest-02.txt` | 97 passed (2559 assertions) · EXIT 0 — `b12-pest-01` is the same slice run Workflow-first: 1 red = the static `AuditLogger::recordedCount` leaking across files in that order (not a regression; the suite's own order is green) |
| `make lint` | `docs/evidence/F1-B12/b12-lint-02.txt` | EXIT 0 (pint · phpstan max · eslint · vue-tsc) — `b12-lint-01` = pint `array_indentation` on `lang/am/workflow.php` after the single-line inserts; formatted, re-run |
| `make gates` (capture-first) | `docs/evidence/F1-B12/b12-gates-01.txt` | 14/14 PASS, EXIT 0 |
| Hex extraction (no token changed in [B12]; `--ds-*` only) | `docs/evidence/F1-B12/tokens-hex-01.txt` | 148 declarations · RESULT: PASS |
| CI look (one per bucket) | `docs/evidence/F1-B12/ci-look-01.txt` | see file |
| Full battery (`make test`) | `docs/evidence/F1-B12/battery-deferred-01.txt` | deferred on battery (rule 13) |

## X. The batch's hard absences (audited on the nine screens)

| # | Absence | Status | Proof |
|---|---|---|---|
| X1 | No type change on a saved field — unbuildable, not greyed (FR-18) | DONE | f1-b12 §J J02: `wf-field-type` count 0 on a saved field, the lock law in words |
| X2 | No free-text SMS body, no typed webhook URL, no AI task beyond the three names | DONE | f1-b12 §F F05: the three CLOSED cards; no `input[type=url]` on the actions panel |
| X3 | No send anywhere on the macros page | DONE | f1-b12 §I: `wf-no-send` states it; no send door exists on the page |
| X4 | Nothing retroactive on SLA / hours (BR-03, EC-05) | DONE | f1-b12 §G/§H: the prospective banners name both facts; no control reaches an armed instance |
| X5 | No edit affordance on a published form version (BR-06) | DONE | f1-b12 §K K06: the history holds no button/input/textarea |
| X6 | No incentive, nudge or reminder on CSAT (BR-07) | DONE | f1-b12 §L: the designed-absence card; no setting that could add one |
| — | Legal next states absent never greyed; refusals inline; 44 px; AA both themes; no failure vocabulary on the customer renders | DONE | f1-b12 §M M04 (refusal role=alert in place), M06 (44 px verbs, focus ring), axe light + dark on every screen; the CSAT renders carry no error/failed word |

## E — Rules list (S05)

| Row | Status | Proof / note |
|---|---|---|
| E01 | DONE | f1-b12 §E: position column with ↑↓ on the selected row (the reorder door); Alt+↑/↓; drag is the buttons' equivalent (D04) |
| E02 | DONE | f1-b12 §E E02: the activate dialog (position · trigger · capability floor · dry-run habit · audit), Cancel changes nothing; deactivate = red primary with stops · keeps · untouched · audited |
| E03 | DONE | f1-b12 §E: matched count + 14-day sparkline from the execution log (D02); "no runs yet" on a fresh rule |
| E04 | DONE | guard badge (amber shield + name + trips) from the last 50 executions' trips (D03); legend text-paired |
| E05 | DONE | `revision_chip` on `has_draft && active` rows (Rules.vue); no fixture carries a draft-on-active in the record — the markup is the proof |
| E06 | DONE | `drift_word` on rows whose `drift` flag is set (SEC-01 read from the floor); no fixture drifts in the record |
| E07 | DONE (half) | f1-b12 §E E07: the banner renders only from the R-04 flag; since/actor/reason/Resume absent — no door (D01, F1-FO-6) |
| E08 | DONE | `wf-rules-empty` + the explainer; the list skeleton in the row geometry during the reorder (`skl`) |
| E09 | DONE | f1-b12 §E E09: 375 read notice, cards, no switch, no overflow, axe both themes |

## F — Rule editor (S06)

| Row | Status | Proof / note |
|---|---|---|
| F01 | DONE | f1-b12 §F F01: three numbered panels; 375 read posture with the notice (`wf-read-posture`) |
| F02 | DONE | f1-b12 §F F02: 13 trigger chips; schedule → cron with the five-minute floor stated, next run in the tenant timezone; cooldown + run window hints |
| F03 | DONE | f1-b12 §F F03: `ConditionTree` (byte-untouched except the root `aria-level` → `data-level`, D-axe) — treeitems with aria-level, add/group as BUTTONS |
| F04 | DONE | inherited from `ConditionTree` (keyword_set_retired_warning · removed_option_warning strings) — proven by the built `workflow-screens` J2 path; no retired set in the record's fixtures |
| F05 | DONE | f1-b12 §F F05: all 15 types as cards with their door named; the three CLOSED arms |
| F06 | DONE | f1-b12 §F F06: the SMS card without a template blocks save inline (`wf-validation-summary`, "Cannot save yet") |
| F07 | DONE | f1-b12 §F F07: the drawer — event picker, truth badges with the evaluated value, per-action would-run, guards, zero side effects, stateless foot |
| F08 | DONE | f1-b12 §F F08: save-as-draft banner (active untouched), the dry-run nudge, the activate dialog names the atomic swap; the header verb stays direct (the built spec's law) |
| F09 | DONE | f1-b12 §F F09: the inline log with its five columns, the SEC-03 note; each row links to the execution page (M02) |
| F10 | NOT-DONE | no retry-action door (registry growth) — D07; follow-on retry-action door (Doc 12) |
| F11 | DONE | f1-b12 §F F11: the editor in am at 1440 (panels, chips), no overflow; the record's long Amharic rule name rides the list sweep (M06/L04) |

## G — SLA policies (S07)

| Row | Status | Proof / note |
|---|---|---|
| G01 | DONE | f1-b12 §G: ordered rows with match summaries, target lines, state; ↑↓ via the update door's `position` (D08); the FR-09 footer; empty state absent while policies exist |
| G02 | DONE | f1-b12 §G: the editor — FR-08 match pickers, targets per priority × stage, clock + calendar + warning % |
| G03 | DONE | f1-b12 §G G03: the live example in en + am from the clock library (optional `locale`, D09), 24 × 7 wall-clock, the unreachable honesty line on a calendar with no interval |
| G04 | DONE | three switches with plain captions + the implied "outside business hours" line + EC-04 |
| G05 | DONE | f1-b12 §G: the EC-03 diagram (before · keep · rescale) live from the targets, the chosen row follows the radio |
| G06 | DONE | the BR-03 banner on the editor's face; the breach hook (path + reason) saved as the engine reads it (D18) |
| G07 | DONE | f1-b12 §G: the FR-11 chip strip in the fixed SLA tokens, no animation (D17 — the live Doc 07 row is Doc 07's) |
| G08 | DONE | f1-b12 §G: 375 read notice, cards, policy 1's live example en + am, no overflow, axe |

## H — Business hours (S08)

| Row | Status | Proof / note |
|---|---|---|
| H01 | DONE | f1-b12 §H H01: the painter — drag paints a band with the live line, release applies, Esc cancels; the tenant timezone labelled with its offset |
| H02 | DONE | f1-b12 §H: the interval list — HH:MM fields, split shifts, a 24:00 close accepted, remove per interval |
| H03 | DONE | f1-b12 §H: the holiday table with "Every year" / "This date only", add + remove through the doors |
| H04 | DONE | f1-b12 §H H04: the override card's diff line, the side-by-side day table with the changed row worded, "Same as the workspace default" for departments without an override |
| H05 | DONE | the banner names both facts (changes apply from now; armed clocks keep their calendar) |
| H06 | DONE | `wf-hours-empty` with the A-53 line; absent while calendars exist (proven) |
| H07 | DONE | f1-b12 §H: 375 read notice, per-day hours, holidays, override summary, no overflow, axe |

## I — Macros manager (S09)

| Row | Status | Proof / note |
|---|---|---|
| I01 | DONE | f1-b12 §I: grouped by scope, personal before shared, the permission key on the group head, kind tag, bundled chips, usage (lifetime — D23); empty state |
| I02 | DONE | f1-b12 §I: the pill body — brace-free pills, uneditable text, delete-as-unit (✕ and Backspace), the announced state list |
| I03 | DONE | f1-b12 §I I03: the preview through the one resolver — values highlighted, the missing placeholder amber with the T-06 line and the pill's announced state; save-first (D26) |
| I04 | DONE | bundled actions with their values; the EC-09 notice quoting both built strings |
| I05 | NOT-DONE | one body per macro — schema amendment (D10); follow-on named in the plan |
| I06 | DONE | the B12-S03 consumer referenced (`wf-palette-ref`), not redrawn |
| I07 | DONE | f1-b12 §I: 375 read posture, grouped list, no editor, no overflow, axe |

## J — Fields manager (S10)

| Row | Status | Proof / note |
|---|---|---|
| J01 | DONE | f1-b12 §J: entity tabs with counts; ↑↓ moves; the 🔒 type badge; flags; used-in counts (where-used per row) |
| J02 | DONE | f1-b12 §J: the key derived at birth, 13 types chosen once, NO type control on a saved field, validation per type, the N-06 line |
| J03 | DONE | f1-b12 §J J03: the EC-08 removal dialog (label kept · rules reading the field · forms offering it — D30), the removed row as a snapshot |
| J04 | DONE | f1-b12 §J J04: the retired section (read-only line, keys never re-mint), reactivate; the retired-key refusal in place with "Reactivate the retired field" (F02) |
| J05 | DONE | f1-b12 §J J05: the complete where-used panel with EC-14's consequence per entry, the Kept and Audited rows; the confirm stays direct (D32) |
| J06 | DONE | the Doc 08 seam card on the customer tab (labels per language, staff-collected, never verified) |
| J07 | DONE | f1-b12 §J: 375 read posture, no editor, no overflow, axe |

## K — Form builder (S11)

| Row | Status | Proof / note |
|---|---|---|
| K01 | DONE | f1-b12 §K: the palette / canvas / inspector chrome; 375 READ posture with the notice |
| K02 | DONE | f1-b12 §K: step tabs with dots, the placement menu (Move up/down with Alt-arrows, Move to step, new step, Duplicate, Remove), the branch glyph + sentence on the card |
| K03 | DONE | f1-b12 §K: labels/help per language with dots, required-when-visible with EC-07's help, the conditional that never offers a later field, the plain sentence, the EC-08 note |
| K04 | DONE | f1-b12 §K K04: the widget pre-chat render, Telegram direct (inline buttons), Telegram business (numbered text) — drawn from the schema in the renderers' idiom (D34); the widget's own render is Doc 13's (D11) |
| K05 | DONE | f1-b12 §K K05: the block list with codes + the EC-07 "Fix it for me", the publishable state (Becomes · Changed since · 0 blocks · Audited), the usage banner, EC-06 |
| K06 | DONE | f1-b12 §K K06: the history with diffs and no edit affordance; the current draft row |
| K07 | DONE | f1-b12 §K K07: the "seeded · editable" tag from the seeder's own list, the FR-23 law line; no submissions column (D35) |
| K08 | DONE | f1-b12 §K K04: the am variant of the widget and both Telegram renders |

## L — CSAT (S12)

| Row | Status | Proof / note |
|---|---|---|
| L01 | DONE | f1-b12 §L L01: the three keys as settings rows (label · key · description · control · provenance · reset), the consequence before save (in words — D37), "Save N changes", the saved state |
| L02 | DONE | f1-b12 §L L02: the Telegram 1-tap render — five inline stars, the polite window-closed line at hour :window+1, the thanks; business mode numbered; en + am |
| L03 | DONE | f1-b12 §L L03: the widget star row as a radiogroup of five 44 px targets, the expired row dashed and disabled, the comment prompt |
| L04 | DONE | the events card (csat.sent / received / expired), suppression (cooldown + RG) stated, N-14, never incentivized |
| L05 | DONE | f1-b12 §L L05: 375 read posture, no save verb, no overflow, axe |

## M — Degraded / empty / error + cross-cutting (S13)

| Row | Status | Proof / note |
|---|---|---|
| M01 | DONE | f1-b12 §M M01: the shared Backoffice nav with the Workflow group live (`bo-nav-*`, `wf-nav-*`), the hub cards with live sub-lines, the chrome legend; the palette row is the built `inbox-palette` spec's |
| M02 | DONE | `Execution.vue` on the existing key (D13, D42): event · ticket · rule · guards · condition results · the elided twin · outcomes; f1-b12 §M M02 reaches it from the editor's log — record 09 makes a schedule-triggered proof rule due and fires it through the built `automation:schedule-sweep`, so the engine writes the row the page reads |
| M03 | NOT-DONE | skeletons need a loading phase — only the rules list has one (D40); follow-on **F1-FO-7** |
| M04 | DONE (refusal) / NOT-DONE (conflict, retry) | the refusal inline proven (§M M04); the conflict state unreachable — no concurrency check on the doors (D39, **F1-FO-8**); the queued-action retry has no door (D07) |
| M05 | DONE | the R-04 banner from the flag (E07); the AI-off tag + banner on the AI task card from Doc 09's banner door (proven absent while AI is on); the auto-disabled destination note from the row |
| M06 | DONE | f1-b12 §M M06: reduced motion (no running animations on the list and the chips), focus ring, 44 px verbs; axe both themes on every screen |

## Summary

68 rows: **62 DONE · 0 PARTIAL · 6 NOT-DONE** (F10, I05, M03, M04's conflict + retry halves, E07's door half counted within DONE-half rows are listed as their follow-ons: F1-FO-6/7/8, the retry-action door, the macro language variants). Every NOT-DONE names its follow-on; no PARTIAL outside a named follow-on.
