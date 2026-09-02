# B9-S00 — Batch 9 checklist (terminal track, the COMBINED batch) — v1

Doc 10 (Knowledge Base) staff surfaces + Doc 12 (Automation, SLA, Forms & Custom Fields) Workflow
surfaces → the design contract, organized in the 13 sections of the session prompt (A–M). Every
row carries its document + section reference. A row is covered only when a delivered screen shows
it in BOTH themes, at 1440 AND 375 (the 375 render in READ posture where editing is ≥1024, stated
in the caption, never blank), with the named states. Tokens and components are B1-DS v2 + the
B5/B6/B7 addenda verbatim (the code-side transcription `resources/css/design-tokens.css` is the
hex-for-hex map, both colourways). The AI accent family (light #F1EDFE/#5B3FD6 · dark
#292144/#BCA9FF, the "✦ AI assistant" text label) is the only AI visual language.

**Baseline law (prompt §6):** every screen starts FROM the built page (`resources/js/Pages/Admin/Kb/`
for A–D; `resources/js/Pages/Workflow/` for E–L) and is refined to full drawn quality; every
divergence from the built layout is captioned "differs from build: <why>".

**Binding seams (R-D12-2, never contradicted):** B12-S03 macro palette — variables resolve live,
missing-variable warning pill, personal-before-shared, preview, insertion never sends (I conforms);
B3-S05 pre-chat rendering of form definitions (K's widget preview conforms).

Planned files (one per section): S01 KB manager · S02 KB article editor · S03 KB version history +
tombstone · S04 KB categories + palette/nav entry · S05 Rules list · S06 Rule editor · S07 SLA
policies · S08 Business hours · S09 Macros manager · S10 Fields manager · S11 Form builder ·
S12 CSAT settings + customer renders · S13 Degraded/empty/error + cross-cutting.

## THE HARD ABSENCES — contracted as DESIGNED ABSENCES (audited on every screen)

| # | Absence | Ref |
|---|---|---|
| X1 | The starvation cap is never drawn configurable — no control, no field, no hint | Doc 12 prompt rule; Doc 11 BR |
| X2 | No frame implies a rule bypassing the transition service, or free-text SMS from a rule (the SMS card offers a template reference only) | Doc 12 BR-02, BR-04 |
| X3 | A blank variable renders as a BLOCKING pill, never as sendable text; send stays off while one is unfilled | Doc 12 BR-04, T-06; B12-S03 |
| X4 | Armed SLA instances are contracts — the policy editor states "prospective only" on its face | Doc 12 BR-03, EC-05 |
| X5 | Form versions are immutable — the history draws diffs, never an edit affordance | Doc 12 BR-06, FR-22 |
| X6 | CSAT is never drawn incentivized (no reward, no nudge copy) | Doc 12 BR-07 |
| X7 | No AI path publishes a KB article — AI results are drafts in the drawer; the publish button is a human's | Doc 10 BR-02, FR-06 |
| X8 | Retire never implies delete anywhere; the tombstone degrades with a label, never a 404 | Doc 10 FR-09, FR-16 |
| X9 | Legal next states ABSENT (never greyed); refusals inline; nothing by color alone; 44 px targets; no failure vocabulary on customer surfaces | standing rulings; Doc 10 §9, Doc 12 §9/§13 |

## A — KB manager (S01)

| # | Item | States | Ref |
|---|---|---|---|
| A01 | Three-pane manager: category tree (drag-reorder + counts) / article list / detail — the built three-pane, refined | normal · list skeleton (tree instant) | Doc 10 §8 "Manager layout", §8 Loading |
| A02 | Article list rows: status pill per workflow state (Draft · In review · Published · Archived) | all four | Doc 10 FR-07, §8 |
| A03 | Language completeness dots ● current ◐ outdated ○ missing per active language, shape+fill+tooltip text | 3 dot states | Doc 10 FR-03, §8, §9 |
| A04 | Visibility tag INTERNAL in amber; AI-usable inheritance shown on hover (from the category) | public · internal · hover | Doc 10 FR-10, §8, §9 |
| A05 | Filters: language · visibility · status; search over title/body/tags/summary | filtered · cleared | Doc 10 FR-17, §8 |
| A06 | Empty states: fresh tenant (seeded drafts + "publish your first article" checklist) · empty category ("create or move one here") · no results (query echo + "create article from this query") | 3 empties | Doc 10 §8 Empty states, FR-04, BR-07 |
| A07 | INTERNAL articles absent (not masked) for viewers without the key — captioned as absence | absent | Doc 10 FR-10, standing ruling |
| A08 | Tablet-capable manager; 375 read posture with the notice | 375 | Doc 10 §9 Responsive |
| A09 | Amharic proof: long Amharic titles in the list and tree in the same layouts | am variant | Doc 10 §14 |

## B — KB article editor (S02)

| # | Item | States | Ref |
|---|---|---|---|
| B01 | Block editor with the FR-02 block set and the block toolbar; language tabs (a proper tablist) across the top with the state dots | normal · content-first loading | Doc 10 FR-02, FR-05, §8, §13 |
| B02 | Working copy: autosave every 5 s stamp (not a history entry); dirty/saved indicator | saving · saved | Doc 10 FR-05 |
| B03 | Concurrent-edit lock: held-by notice, takeover notice for the loser | lock held · taken over | Doc 10 FR-05, §8 Error states |
| B04 | Media block: upload, alt text required (publish warns/blocks per kb.require_alt), upload failure per Doc 04 pattern | ok · missing alt · failed | Doc 10 §13, §8 Error states |
| B05 | INTERNAL banner: amber full-width, repeated above the fold on long articles, announced as a note region | internal | Doc 10 §9, §13 |
| B06 | AI authoring drawer (right side): Simplify · FAQ variants · Translate all — results stream in AI-accented blocks as DRAFTS only; no publish path in the drawer | streaming · complete · AI-off | Doc 10 FR-06, BR-02, §8 Editor |
| B07 | Sanitizer warnings inline: dotted amber underline + popover (pattern + rationale), keep/edit choices; override recorded on the version | flagged · overridden | Doc 10 FR-13, BR-06, §9, §13 |
| B08 | Review workflow: submit → in review; request-changes comments anchored to blocks; the per-tenant review checklist (fixture rows, captioned as Doc 16 content); approve/publish reflects the caller's permission | draft · in review · changes requested | Doc 10 FR-07, §8 Review |
| B09 | Publish dialog: audience, AI-usability (inherited, link to category), languages being published, sanitizer summary, and — for a source-language publish — the variants that will flip to outdated | dialog · source publish | Doc 10 §8 Editor |
| B10 | Publish pipeline: saving → sanitizing → indexing with per-stage ticks; stage failure = live-to-humans, "index pending" with retry (the split explained) | progressing · index pending | Doc 10 §8 Loading/Error, NFR-01 |
| B11 | Stats header: views-by-surface sparkline, AI citations, helpful %, deflection; feedback list (paginated, mark-actioned) | populated · empty | Doc 10 FR-11, FR-12, §8 Feedback |
| B12 | Keyboard operability: block navigation, toolbar via menus, move-up/down equivalents; sanitizer popovers keyboard-triggerable | note on frame | Doc 10 §13 |
| B13 | 375: editor READ-ONLY with the edit-requires-larger-screen notice | 375 | Doc 10 §9 Responsive |
| B14 | Amharic-first article (source language am) with LONG strings; per-language slugs with the transliteration helper | am source | Doc 10 §14 |

## C — KB version history + tombstone (S03)

| # | Item | States | Ref |
|---|---|---|---|
| C01 | Version list: author · note · badges; block-diff view added=green tint, removed=red-tint collapsed stubs (expandable), changed=paired old/new, +/− glyphs; prev/next changed-block navigation (landmarks) | list · diff | Doc 10 FR-08, §8 History, §9, §13 |
| C02 | Restore-to-draft with the confirmation explaining it creates a NEW draft (never rewrites history) | confirm | Doc 10 FR-08, §8 |
| C03 | Tombstone page: "This article was updated / removed" with the labeled degrade (current-version link when one exists; retired = absent from surfaces, never deleted) | updated · removed · superseded | Doc 10 FR-09, FR-16, X8 |
| C04 | Archive/un-archive: leaves consumption surfaces, URLs resolve to tombstones | archived | Doc 10 FR-09 |

## D — KB categories + the palette/nav entry (S04)

| # | Item | States | Ref |
|---|---|---|---|
| D01 | Category editor: name per language, slug, description, position, icon, ai_usable; single tree max depth 3 (a 4th level is unbuildable — the control is absent) | edit · depth-3 wall | Doc 10 FR-01 |
| D02 | Tree drag-reorder with move-button equivalents; counts per category | reorder | Doc 10 §8, §13 |
| D03 | ai_usable inheritance shown; the internal split explained on the category (Copilot-only index) | tooltip | Doc 10 FR-10, FR-15 |
| D04 | The shell's one KB nav entry as built: the command-palette row "Open the Knowledge Base" (the P8 precedent) and where the manager sits in the Backoffice chrome | palette row | Doc 10 §8; P8 |

## E — Rules list (S05)

| # | Item | States | Ref |
|---|---|---|---|
| E01 | Position-ordered list (position = execution order, BR-01) with drag-reorder AND move-button equivalents | normal · reordering | Doc 12 §8 Rules list, BR-01, §13 |
| E02 | Active toggles permission-gated (automation.rules.activate); confirmation weights (activate = the verb on the primary; deactivate = red primary, consequence in nouns) | active · inactive · confirm | Doc 12 FR-07, §8; B1-DS addendum B5 |
| E03 | Matched-count SPARKLINE + last run per rule — differs from build (the build shows a count only): the document names a sparkline | populated · no runs | Doc 12 §8 Rules list, FR-06 |
| E04 | Guard-trip badges: amber shield + guard name (max_rules · max_actions · chain_depth), text-paired | tripped · none | Doc 12 §9, FR-04 |
| E05 | Revision indicator on edited-active rules ("draft revision waiting") | edited-active | Doc 12 FR-07, §8 |
| E06 | SEC-01 drift warning on a rule whose activator lost a capability | drift | Doc 12 SEC-01 |
| E07 | THE PAUSE-ALL state: loud banner "ALL RULES PAUSED since {time} by {actor}" on the list when the R-04 switch is on | paused | Doc 12 R-04; prompt hard rule |
| E08 | Empty list with the "position order is execution order" explainer; list skeleton | empty · skeleton | Doc 12 §8 Empty/loading |
| E09 | Tablet-capable; 375 read posture | 375 | Doc 12 §9 Responsive |

## F — Rule editor (S06)

| # | Item | States | Ref |
|---|---|---|---|
| F01 | Three panels: Trigger → Conditions → Actions, the B5-S07 builder chrome idiom; ≥1024 surface, 375 READ posture with the notice | normal · 375 read | Doc 12 §8 Rule editor, §9 Responsive |
| F02 | Trigger panel: the 13 triggers; schedule with cron, the 5-minute floor, tenant-timezone next-run preview; cooldown; run window | event · schedule | Doc 12 FR-01, EC-10, §8 |
| F03 | Condition tree: all/any group nesting, per-row type-aware operator/value inputs, keyword-set picker; add/group/indent/outdent as BUTTONS with aria-level tree semantics | nested · keyword set | Doc 12 FR-02, §8, §13 |
| F04 | Keyword set retired → the row's warning; removed option value → EC-08 warning on the condition | 2 warnings | Doc 12 EC-08, FR-02 |
| F05 | Ordered action cards with per-action config; the external arms' CLOSED cards (SMS = template reference + the BR-04 help; webhook = destination pick from the allowlist; AI task = the three names) | 15 card types | Doc 12 FR-03, BR-04, SEC-02 |
| F06 | Inline validation (e.g., SMS action without a template chosen) blocking save | invalid | Doc 12 §8 Rule editor |
| F07 | THE DRY-RUN DRAWER alongside: sample event picker (7-day history), per-condition truth badges ✓ green / ✗ gray WITH the evaluated value, per-action would-run/skipped notes, guard badges; zero side effects stated | matched · unmatched · guard tripped | Doc 12 FR-05, §8, §9 |
| F08 | Save-as-draft vs Activate flows (FR-07): the draft revision, the atomic swap, the "activating skips the review of a dry run" nudge (§21 rec 2) | draft · activate | Doc 12 FR-07, §21 |
| F09 | Execution log (FR-06) inline: matched/no-match, per-action outcomes incl. failures, guard trips; SEC-03 elision for non-privileged viewers ([matched]/[keyword] markers) | privileged · elided | Doc 12 FR-06, SEC-03 |
| F10 | Retry-action affordance in execution detail (single action re-run, permission-gated, audited) | retry | Doc 12 §8 Empty/loading/error |
| F11 | Amharic proof: long Amharic rule names, keyword sets and note bodies | am variant | Doc 12 §14 |

## G — SLA policies (S07)

| # | Item | States | Ref |
|---|---|---|---|
| G01 | Ordered list with match summaries (first match wins); active state; move buttons | list · empty | Doc 12 FR-08, BR-01, §8 |
| G02 | Editor: targets grid per priority × stage (first response / next response / resolution), clock mode (calendar / 24×7), calendar pick, warning % | edit | Doc 12 FR-08, FR-09 |
| G03 | THE LIVE EXAMPLE SENTENCE computed from the calendar ("a High Deposits ticket created Tue 17:00 would target first response Wed 08:40"), ICU-templated en + am; the unreachable-calendar honesty line | reachable · unreachable | Doc 12 §8 SLA policies, §13, §14, AC-04 |
| G04 | Pause-condition toggles with plain-language captions (pending customer / internal / channel unreachable) | toggles | Doc 12 FR-08, §8 |
| G05 | THE RETARGET DIAGRAM: keep vs rescale-remaining explained with a drawn diagram (remaining % × new target, EC-03) — differs from build (the build explains in prose) | diagram | Doc 12 §8 SLA policies, EC-03 |
| G06 | Prospective-only statement on the editor's face (BR-03) + the breach-escalation hook pick | banner | Doc 12 BR-03, FR-08 |
| G07 | SLA chips per FR-11 tokens shown in context (green → amber step at warning → red; pause glyph + reason tooltip; NO animation) | 3 states + paused | Doc 12 FR-11, §9 |
| G08 | Phone-readable, edit on tablet+ | 375 | Doc 12 §9 Responsive |

## H — Business hours (S08)

| # | Item | States | Ref |
|---|---|---|---|
| H01 | Week-grid painter (drag intervals) — the ENHANCEMENT — with the tenant timezone labelled explicitly | painting | Doc 12 FR-12, §8, §14 |
| H02 | THE INTERVAL-LIST ALTERNATIVE EDITOR — the REQUIREMENT — same hours, fully keyboard operable; split shifts; 24:00 closes | list · split shift | Doc 12 §13, FR-12, AC-04 |
| H03 | Holiday list with recurring toggles | holidays | Doc 12 FR-12 |
| H04 | Department-override diff view ("Payments differs: Sat closed") | diff · same as default | Doc 12 §8 Business hours, FR-13 |
| H05 | The prospective-only banner: armed clocks keep their calendar (BR-03, EC-05) — both facts named on the edit screen | banner | Doc 12 BR-03, EC-05 |
| H06 | Empty state: no calendar = SLAs run 24×7 (the honest A-53 default) | empty | Doc 12 FR-13 |
| H07 | Phone-readable; edit on tablet+ | 375 | Doc 12 §9 Responsive |

## I — Macros manager (S09) — conforms to B12-S03's frozen palette law

| # | Item | States | Ref |
|---|---|---|---|
| I01 | Manager grouped by scope (personal · team · shared) with usage counts and the kind tag (macro / saved reply) | groups · empty | Doc 12 FR-14, FR-16, §8 |
| I02 | Editor: body with the variable palette; variables as PILLS — uneditable inner text, delete-as-unit, SR-announced resolution state | pills | Doc 12 §9, §13, T-06 |
| I03 | Live preview against a sample ticket — the ONE resolver (preview = send); a missing variable shows the B12-S03 warning pill and the "cannot be sent as-is" line | resolved · missing | Doc 12 FR-15, T-06, AC-05; B12-S03 |
| I04 | Bundled-action chips (set status / set priority / add tags) with EC-09's per-capability honesty stated | chips | Doc 12 FR-14, EC-09 |
| I05 | Language variants of a macro (per B12-S03's note: Doc 12's territory) — per-language bodies with completeness dots | en · am | Doc 12 §14, FR-14 |
| I06 | The palette consumer shown once (the B12-S03 frame referenced, not redrawn): what the manager's definition produces | reference | R-D12-2.3 |
| I07 | Tablet-capable; 375 read posture | 375 | Doc 12 §9 |

## J — Fields manager (S10)

| # | Item | States | Ref |
|---|---|---|---|
| J01 | Entity tabs (ticket · customer); drag-order with move buttons; type-locked badge (FR-18 physics) | tabs · reorder | Doc 12 FR-17, FR-18, §8 |
| J02 | Field editor: key (immutable after birth), label per language, the 13 types, validation, agent-editable / customer-visible; a type change is UNBUILDABLE (absent, not greyed) | new · edit | Doc 12 FR-17, FR-18, X9 |
| J03 | Option editors with the value-stability warning (EC-08: labels snapshot, rules referencing a removed value flag) | options · removed | Doc 12 EC-08, §8 |
| J04 | Retired section; reactivate; the key never re-mints | retired | Doc 12 FR-18 |
| J05 | THE WHERE-USED PANEL before deactivate (forms / rules / views), complete; the deactivate confirmation with the EC-14 consequences (rule flagged evaluates is-empty; form's next publish blocked; live version keeps rendering) | where-used · confirm | Doc 12 EC-14, §8, AC-06 |
| J06 | The Doc 08 seam stated: customer fields feed Doc 08's card (label per language) | note | Doc 12 FR-19 |
| J07 | Tablet-capable; 375 read posture | 375 | Doc 12 §9 |

## K — Form builder (S11)

| # | Item | States | Ref |
|---|---|---|---|
| K01 | Left palette (field types + existing custom fields as refs) / center canvas with step tabs / right inspector — the B5-S07 chrome; ≥1024, 375 READ posture | normal · 375 read | Doc 12 §8 Form builder, §9 |
| K02 | Canvas: step tabs with completeness dots, drag placement WITH placement menus (§13), conditional fields with the branch glyph | canvas | Doc 12 §8, §9, §13 |
| K03 | Inspector: labels/help per language with completeness dots (Doc 10 pattern), validation, required / required-when-visible, the conditional builder with the plain-sentence render ("Show when Method is not Cash Agent") | inspector · sentence | Doc 12 §8, EC-07 |
| K04 | DUAL LIVE PREVIEW toggle: widget render (conforming to B3-S05's pre-chat rendering) + Telegram chat simulation in the device-styled frame (Doc 05 §9 component), numbered-choice business mode | widget · telegram direct · telegram business | Doc 12 §8, §9, FR-21; B3-S05 |
| K05 | Publish dialog: the FR-22 validation results (retired ref, unknown field, required-when-hidden with the auto-suggest, language completeness, forward reference, unsupported upload), the usage banner (bot menus / rules / widget shortcuts), EC-06 stated | blocked · publishable | Doc 12 FR-22, EC-06, EC-07 |
| K06 | Version history with diff (fields added / removed / changed) — never an edit affordance | history | Doc 12 FR-22, BR-06, X5 |
| K07 | The seeded three forms as editable content (FR-23) in the list | seeded | Doc 12 FR-23 |
| K08 | Amharic proof: long Amharic labels in canvas, inspector, and both previews | am variant | Doc 12 §14 |

## L — CSAT settings + the customer-facing renders (S12)

| # | Item | States | Ref |
|---|---|---|---|
| L01 | Settings page in the settings-row idiom: cooldown hours, window hours, comment prompt on/off, per-language button labels | edit · saved | Doc 12 FR-24, §8 CSAT |
| L02 | The Telegram 1-tap render: the star prompt, the polite "window closed" (hour 73), the thanks; en + am | prompt · closed · thanks | Doc 12 FR-25, EC-11 |
| L03 | The widget star row with radio semantics (AA), the disabled expired state; the optional comment prompt | prompt · expired | Doc 12 FR-25, §13 |
| L04 | Event completeness note for Doc 15 (sent / received / expired) and the RG/cooldown suppression stated; never incentivized | note | Doc 12 BR-07, AC-08, X6 |
| L05 | Phone-readable | 375 | Doc 12 §9 |

## M — Degraded / empty / error states + cross-cutting (S13)

| # | Item | States | Ref |
|---|---|---|---|
| M01 | The Workflow nav chrome: the drawn Backoffice nav with the Workflow group live (Organization a real link out; SLA / Automation / Hours real; People / Channels drawn) and the sub-nav (macros · fields · forms · CSAT); the palette's one "Open Workflow" row | chrome | R-D12-2; P-NAV |
| M02 | Execution log DETAIL page: event ref, condition results, action outcomes incl. failures; SEC-03 elision states side by side (privileged vs non-privileged); the ticket timeline's rule-link rows (Doc 06 FR-26) | privileged · elided · timeline | Doc 12 FR-06, SEC-03, US-06 |
| M03 | Loading skeletons per surface (rule editor skeleton; list skeletons) | skeleton | Doc 12 §8 Empty/loading/error |
| M04 | Error states: refusal inline (RFC 9457 detail in place, role=alert), the concurrent-edit conflict with reload, a queued-action failure state with the retry affordance | refusal · conflict · failed action | Doc 12 §8; B2-S06 |
| M05 | Degraded: rules paused (E07 cross-ref), AI task unavailable (AI-off), webhook destination auto-disabled after ×8 (the amber note + re-enable) | 3 degraded | Doc 12 R-04, EC-13, FS-03 |
| M06 | Reduced-motion + both themes proof sheet: no animation on chip transitions, focus rings, 44 px targets | proof | Doc 12 §9, §13 |

## Divergences from the built pages I already know I will draw (each will be captioned)

1. E03 — the matched-count SPARKLINE (the build shows a count only; §8 names a sparkline).
2. G05 — the RETARGET DIAGRAM (the build explains EC-03 in prose; §8 says "explained with a diagram").
3. E01 / J01 / K02 — drag-reorder and drag placement drawn as the enhancement WITH the built move buttons and placement menus as the requirement (§13).
4. I05 — macro language variants (B12-S03 assigns them to Doc 12; the build has one body per macro).

## Silences I will resolve by stated assumption unless you rule otherwise

1. Doc 10 §8's review checklist is "configurable per tenant (Doc 16 content)" — I draw three fixture rows (accuracy · tone · links) captioned as tenant-configured.
2. Doc 12 §8's CSAT "customer-facing renders per FR-25 contracts" — the widget star row is Doc 13's surface; I draw it from FR-25's contract only, captioned as the contract render.

## What happens next

You approve this contract (or change it). Then S01 (the KB manager) lands as one HTML file, both
themes, 1440 + 375, every A-row state, and I stop for your verdict on that one screen.
