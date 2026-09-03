# B9-A01 — Batch 9 closing audit — v1

Every checklist row re-printed with its verdict: ✅ = delivered in an APPROVED, committed file whose frame captions carry the row id, in both themes at 1440 and 375 with the named states; ❌ = not delivered. A false ✅ is the worst failure possible — the file/version/SHA per row is the evidence.

| Row | Item | Verdict | File · version · commit |
|---|---|---|---|
| **A** | **KB manager** | | |
| A01 | Three-pane manager: category tree (drag-reorder + counts) / article list / detail — the built three-pane, refined | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A02 | Article list rows: status pill per workflow state (Draft · In review · Published · Archived) | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A03 | Language completeness dots ● current ◐ outdated ○ missing per active language, shape+fill+tooltip text | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A04 | Visibility tag INTERNAL in amber; AI-usable inheritance shown on hover (from the category) | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A05 | Filters: language · visibility · status; search over title/body/tags/summary | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A06 | Empty states: fresh tenant (seeded drafts + "publish your first article" checklist) · empty category ("create or move one here") · no results (query echo + "create article from this query") | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A07 | INTERNAL articles absent (not masked) for viewers without the key — captioned as absence | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A08 | Tablet-capable manager; 375 read posture with the notice | ✅ | S01-kb-manager v1 · 7ab4a37 |
| A09 | Amharic proof: long Amharic titles in the list and tree in the same layouts | ✅ | S01-kb-manager v1 · 7ab4a37 |
| **B** | **KB article editor** | | |
| B01 | Block editor with the FR-02 block set and the block toolbar; language tabs (a proper tablist) across the top with the state dots | ✅ | S02-kb-article-editor v1 · 525aeab |
| B02 | Working copy: autosave every 5 s stamp (not a history entry); dirty/saved indicator | ✅ | S02-kb-article-editor v1 · 525aeab |
| B03 | Concurrent-edit lock: held-by notice, takeover notice for the loser | ✅ | S02-kb-article-editor v1 · 525aeab |
| B04 | Media block: upload, alt text required (publish warns/blocks per kb.require_alt), upload failure per Doc 04 pattern | ✅ | S02-kb-article-editor v1 · 525aeab |
| B05 | INTERNAL banner: amber full-width, repeated above the fold on long articles, announced as a note region | ✅ | S02-kb-article-editor v1 · 525aeab |
| B06 | AI authoring drawer (right side): Simplify · FAQ variants · Translate all — results stream in AI-accented blocks as DRAFTS only; no publish path in the drawer | ✅ | S02-kb-article-editor v1 · 525aeab |
| B07 | Sanitizer warnings inline: dotted amber underline + popover (pattern + rationale), keep/edit choices; override recorded on the version | ✅ | S02-kb-article-editor v1 · 525aeab |
| B08 | Review workflow: submit → in review; request-changes comments anchored to blocks; the per-tenant review checklist (fixture rows, captioned as Doc 16 content); approve/publish reflects the caller's permission | ✅ | S02-kb-article-editor v1 · 525aeab |
| B09 | Publish dialog: audience, AI-usability (inherited, link to category), languages being published, sanitizer summary, and — for a source-language publish — the variants that will flip to outdated | ✅ | S02-kb-article-editor v1 · 525aeab |
| B10 | Publish pipeline: saving → sanitizing → indexing with per-stage ticks; stage failure = live-to-humans, "index pending" with retry (the split explained) | ✅ | S02-kb-article-editor v1 · 525aeab |
| B11 | Stats header: views-by-surface sparkline, AI citations, helpful %, deflection; feedback list (paginated, mark-actioned) | ✅ | S02-kb-article-editor v1 · 525aeab |
| B12 | Keyboard operability: block navigation, toolbar via menus, move-up/down equivalents; sanitizer popovers keyboard-triggerable | ✅ | S02-kb-article-editor v1 · 525aeab |
| B13 | 375: editor READ-ONLY with the edit-requires-larger-screen notice | ✅ | S02-kb-article-editor v1 · 525aeab |
| B14 | Amharic-first article (source language am) with LONG strings; per-language slugs with the transliteration helper | ✅ | S02-kb-article-editor v1 · 525aeab |
| **C** | **KB version history + tombstone** | | |
| C01 | Version list: author · note · badges; block-diff view added=green tint, removed=red-tint collapsed stubs (expandable), changed=paired old/new, +/− glyphs; prev/next changed-block navigation (landmarks) | ✅ | S03-kb-history-tombstone v1 · 362c9fd |
| C02 | Restore-to-draft with the confirmation explaining it creates a NEW draft (never rewrites history) | ✅ | S03-kb-history-tombstone v1 · 362c9fd |
| C03 | Tombstone page: "This article was updated / removed" with the labeled degrade (current-version link when one exists; retired = absent from surfaces, never deleted) | ✅ | S03-kb-history-tombstone v1 · 362c9fd |
| C04 | Archive/un-archive: leaves consumption surfaces, URLs resolve to tombstones | ✅ | S03-kb-history-tombstone v1 · 362c9fd |
| **D** | **KB categories + palette/nav entry** | | |
| D01 | Category editor: name per language, slug, description, position, icon, ai_usable; single tree max depth 3 (a 4th level is unbuildable — the control is absent) | ✅ | S04-kb-categories-nav v1 · 8a75e66 |
| D02 | Tree drag-reorder with move-button equivalents; counts per category | ✅ | S04-kb-categories-nav v1 · 8a75e66 |
| D03 | ai_usable inheritance shown; the internal split explained on the category (Copilot-only index) | ✅ | S04-kb-categories-nav v1 · 8a75e66 |
| D04 | The shell's one KB nav entry as built: the command-palette row "Open the Knowledge Base" (the P8 precedent) and where the manager sits in the Backoffice chrome | ✅ | S04-kb-categories-nav v1 · 8a75e66 |
| **E** | **Rules list** | | |
| E01 | Position-ordered list (position = execution order, BR-01) with drag-reorder AND move-button equivalents | ✅ | S05-rules-list v1 · 4ace3c4 |
| E02 | Active toggles permission-gated (automation.rules.activate); confirmation weights (activate = the verb on the primary; deactivate = red primary, consequence in nouns) | ✅ | S05-rules-list v1 · 4ace3c4 |
| E03 | Matched-count SPARKLINE + last run per rule — differs from build (the build shows a count only): the document names a sparkline | ✅ | S05-rules-list v1 · 4ace3c4 |
| E04 | Guard-trip badges: amber shield + guard name (max_rules · max_actions · chain_depth), text-paired | ✅ | S05-rules-list v1 · 4ace3c4 |
| E05 | Revision indicator on edited-active rules ("draft revision waiting") | ✅ | S05-rules-list v1 · 4ace3c4 |
| E06 | SEC-01 drift warning on a rule whose activator lost a capability | ✅ | S05-rules-list v1 · 4ace3c4 |
| E07 | THE PAUSE-ALL state: loud banner "ALL RULES PAUSED since {time} by {actor}" on the list when the R-04 switch is on | ✅ | S05-rules-list v1 · 4ace3c4 |
| E08 | Empty list with the "position order is execution order" explainer; list skeleton | ✅ | S05-rules-list v1 · 4ace3c4 |
| E09 | Tablet-capable; 375 read posture | ✅ | S05-rules-list v1 · 4ace3c4 |
| **F** | **Rule editor** | | |
| F01 | Three panels: Trigger → Conditions → Actions, the B5-S07 builder chrome idiom; ≥1024 surface, 375 READ posture with the notice | ✅ | S06-rule-editor v1 · 91d6bbd |
| F02 | Trigger panel: the 13 triggers; schedule with cron, the 5-minute floor, tenant-timezone next-run preview; cooldown; run window | ✅ | S06-rule-editor v1 · 91d6bbd |
| F03 | Condition tree: all/any group nesting, per-row type-aware operator/value inputs, keyword-set picker; add/group/indent/outdent as BUTTONS with aria-level tree semantics | ✅ | S06-rule-editor v1 · 91d6bbd |
| F04 | Keyword set retired → the row's warning; removed option value → EC-08 warning on the condition | ✅ | S06-rule-editor v1 · 91d6bbd |
| F05 | Ordered action cards with per-action config; the external arms' CLOSED cards (SMS = template reference + the BR-04 help; webhook = destination pick from the allowlist; AI task = the three names) | ✅ | S06-rule-editor v1 · 91d6bbd |
| F06 | Inline validation (e.g., SMS action without a template chosen) blocking save | ✅ | S06-rule-editor v1 · 91d6bbd |
| F07 | THE DRY-RUN DRAWER alongside: sample event picker (7-day history), per-condition truth badges ✓ green / ✗ gray WITH the evaluated value, per-action would-run/skipped notes, guard badges; zero side effects stated | ✅ | S06-rule-editor v1 · 91d6bbd |
| F08 | Save-as-draft vs Activate flows (FR-07): the draft revision, the atomic swap, the "activating skips the review of a dry run" nudge (§21 rec 2) | ✅ | S06-rule-editor v1 · 91d6bbd |
| F09 | Execution log (FR-06) inline: matched/no-match, per-action outcomes incl. failures, guard trips; SEC-03 elision for non-privileged viewers ([matched]/[keyword] markers) | ✅ | S06-rule-editor v1 · 91d6bbd |
| F10 | Retry-action affordance in execution detail (single action re-run, permission-gated, audited) | ✅ | S06-rule-editor v1 · 91d6bbd |
| F11 | Amharic proof: long Amharic rule names, keyword sets and note bodies | ✅ | S06-rule-editor v1 · 91d6bbd |
| **G** | **SLA policies** | | |
| G01 | Ordered list with match summaries (first match wins); active state; move buttons | ✅ | S07-sla-policies v1 · 216408d |
| G02 | Editor: targets grid per priority × stage (first response / next response / resolution), clock mode (calendar / 24×7), calendar pick, warning % | ✅ | S07-sla-policies v1 · 216408d |
| G03 | THE LIVE EXAMPLE SENTENCE computed from the calendar ("a High Deposits ticket created Tue 17:00 would target first response Wed 08:40"), ICU-templated en + am; the unreachable-calendar honesty line | ✅ | S07-sla-policies v1 · 216408d |
| G04 | Pause-condition toggles with plain-language captions (pending customer / internal / channel unreachable) | ✅ | S07-sla-policies v1 · 216408d |
| G05 | THE RETARGET DIAGRAM: keep vs rescale-remaining explained with a drawn diagram (remaining % × new target, EC-03) — differs from build (the build explains in prose) | ✅ | S07-sla-policies v1 · 216408d |
| G06 | Prospective-only statement on the editor's face (BR-03) + the breach-escalation hook pick | ✅ | S07-sla-policies v1 · 216408d |
| G07 | SLA chips per FR-11 tokens shown in context (green → amber step at warning → red; pause glyph + reason tooltip; NO animation) | ✅ | S07-sla-policies v1 · 216408d |
| G08 | Phone-readable, edit on tablet+ | ✅ | S07-sla-policies v1 · 216408d |
| **H** | **Business hours** | | |
| H01 | Week-grid painter (drag intervals) — the ENHANCEMENT — with the tenant timezone labelled explicitly | ✅ | S08-business-hours v1 · 29ebfc2 |
| H02 | THE INTERVAL-LIST ALTERNATIVE EDITOR — the REQUIREMENT — same hours, fully keyboard operable; split shifts; 24:00 closes | ✅ | S08-business-hours v1 · 29ebfc2 |
| H03 | Holiday list with recurring toggles | ✅ | S08-business-hours v1 · 29ebfc2 |
| H04 | Department-override diff view ("Payments differs: Sat closed") | ✅ | S08-business-hours v1 · 29ebfc2 |
| H05 | The prospective-only banner: armed clocks keep their calendar (BR-03, EC-05) — both facts named on the edit screen | ✅ | S08-business-hours v1 · 29ebfc2 |
| H06 | Empty state: no calendar = SLAs run 24×7 (the honest A-53 default) | ✅ | S08-business-hours v1 · 29ebfc2 |
| H07 | Phone-readable; edit on tablet+ | ✅ | S08-business-hours v1 · 29ebfc2 |
| **I** | **Macros manager** | | |
| I01 | Manager grouped by scope (personal · team · shared) with usage counts and the kind tag (macro / saved reply) | ✅ | S09-macros-manager v1 · a29336d |
| I02 | Editor: body with the variable palette; variables as PILLS — uneditable inner text, delete-as-unit, SR-announced resolution state | ✅ | S09-macros-manager v1 · a29336d |
| I03 | Live preview against a sample ticket — the ONE resolver (preview = send); a missing variable shows the B12-S03 warning pill and the "cannot be sent as-is" line | ✅ | S09-macros-manager v1 · a29336d |
| I04 | Bundled-action chips (set status / set priority / add tags) with EC-09's per-capability honesty stated | ✅ | S09-macros-manager v1 · a29336d |
| I05 | Language variants of a macro (per B12-S03's note: Doc 12's territory) — per-language bodies with completeness dots | ✅ | S09-macros-manager v1 · a29336d |
| I06 | The palette consumer shown once (the B12-S03 frame referenced, not redrawn): what the manager's definition produces | ✅ | S09-macros-manager v1 · a29336d |
| I07 | Tablet-capable; 375 read posture | ✅ | S09-macros-manager v1 · a29336d |
| **J** | **Fields manager** | | |
| J01 | Entity tabs (ticket · customer); drag-order with move buttons; type-locked badge (FR-18 physics) | ✅ | S10-fields-manager v1 · d393887 |
| J02 | Field editor: key (immutable after birth), label per language, the 13 types, validation, agent-editable / customer-visible; a type change is UNBUILDABLE (absent, not greyed) | ✅ | S10-fields-manager v1 · d393887 |
| J03 | Option editors with the value-stability warning (EC-08: labels snapshot, rules referencing a removed value flag) | ✅ | S10-fields-manager v1 · d393887 |
| J04 | Retired section; reactivate; the key never re-mints | ✅ | S10-fields-manager v1 · d393887 |
| J05 | THE WHERE-USED PANEL before deactivate (forms / rules / views), complete; the deactivate confirmation with the EC-14 consequences (rule flagged evaluates is-empty; form's next publish blocked; live version keeps rendering) | ✅ | S10-fields-manager v1 · d393887 |
| J06 | The Doc 08 seam stated: customer fields feed Doc 08's card (label per language) | ✅ | S10-fields-manager v1 · d393887 |
| J07 | Tablet-capable; 375 read posture | ✅ | S10-fields-manager v1 · d393887 |
| **K** | **Form builder** | | |
| K01 | Left palette (field types + existing custom fields as refs) / center canvas with step tabs / right inspector — the B5-S07 chrome; ≥1024, 375 READ posture | ✅ | S11-form-builder v1 · aaf0e14 |
| K02 | Canvas: step tabs with completeness dots, drag placement WITH placement menus (§13), conditional fields with the branch glyph | ✅ | S11-form-builder v1 · aaf0e14 |
| K03 | Inspector: labels/help per language with completeness dots (Doc 10 pattern), validation, required / required-when-visible, the conditional builder with the plain-sentence render ("Show when Method is not Cash Agent") | ✅ | S11-form-builder v1 · aaf0e14 |
| K04 | DUAL LIVE PREVIEW toggle: widget render (conforming to B3-S05's pre-chat rendering) + Telegram chat simulation in the device-styled frame (Doc 05 §9 component), numbered-choice business mode | ✅ | S11-form-builder v1 · aaf0e14 |
| K05 | Publish dialog: the FR-22 validation results (retired ref, unknown field, required-when-hidden with the auto-suggest, language completeness, forward reference, unsupported upload), the usage banner (bot menus / rules / widget shortcuts), EC-06 stated | ✅ | S11-form-builder v1 · aaf0e14 |
| K06 | Version history with diff (fields added / removed / changed) — never an edit affordance | ✅ | S11-form-builder v1 · aaf0e14 |
| K07 | The seeded three forms as editable content (FR-23) in the list | ✅ | S11-form-builder v1 · aaf0e14 |
| K08 | Amharic proof: long Amharic labels in canvas, inspector, and both previews | ✅ | S11-form-builder v1 · aaf0e14 |
| **L** | **CSAT settings + customer renders** | | |
| L01 | Settings page in the settings-row idiom: cooldown hours, window hours, comment prompt on/off, per-language button labels | ✅ | S12-csat v1 · da24964 |
| L02 | The Telegram 1-tap render: the star prompt, the polite "window closed" (hour 73), the thanks; en + am | ✅ | S12-csat v1 · da24964 |
| L03 | The widget star row with radio semantics (AA), the disabled expired state; the optional comment prompt | ✅ | S12-csat v1 · da24964 |
| L04 | Event completeness note for Doc 15 (sent / received / expired) and the RG/cooldown suppression stated; never incentivized | ✅ | S12-csat v1 · da24964 |
| L05 | Phone-readable | ✅ | S12-csat v1 · da24964 |
| **M** | **Degraded / empty / error + cross-cutting** | | |
| M01 | The Workflow nav chrome: the drawn Backoffice nav with the Workflow group live (Organization a real link out; SLA / Automation / Hours real; People / Channels drawn) and the sub-nav (macros · fields · forms · CSAT); the palette's one "Open Workflow" row | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |
| M02 | Execution log DETAIL page: event ref, condition results, action outcomes incl. failures; SEC-03 elision states side by side (privileged vs non-privileged); the ticket timeline's rule-link rows (Doc 06 FR-26) | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |
| M03 | Loading skeletons per surface (rule editor skeleton; list skeletons) | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |
| M04 | Error states: refusal inline (RFC 9457 detail in place, role=alert), the concurrent-edit conflict with reload, a queued-action failure state with the retry affordance | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |
| M05 | Degraded: rules paused (E07 cross-ref), AI task unavailable (AI-off), webhook destination auto-disabled after ×8 (the amber note + re-enable) | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |
| M06 | Reduced-motion + both themes proof sheet: no animation on chip transitions, focus rings, 44 px targets | ✅ | S13-degraded-cross-cutting v1 · a1dcc54 |

## The hard absences (X1–X9) — audited on every screen

| # | Absence | Verdict | Where stated |
|---|---|---|---|
| X1 | The starvation cap is never drawn configurable — no control, no field, no hint | ✅ | S05 (guards printed as platform limits), S06, S07, S08, S13 header absences — no starvation-cap control on any frame |
| X2 | No frame implies a rule bypassing the transition service, or free-text SMS from a rule (the SMS card offers a template reference only) | ✅ | S06 F05 (SMS card = template reference only; webhook = allowlist pick), S06 header absences |
| X3 | A blank variable renders as a BLOCKING pill, never as sendable text; send stays off while one is unfilled | ✅ | S09 I03 (missing pill, “cannot be sent as-is”, send stays off), S09 header absences |
| X4 | Armed SLA instances are contracts — the policy editor states "prospective only" on its face | ✅ | S07 G06 (prospective-only banner on the editor’s face), S08 H05 |
| X5 | Form versions are immutable — the history draws diffs, never an edit affordance | ✅ | S11 K06 (version history = diffs, no edit affordance), S11 header absences |
| X6 | CSAT is never drawn incentivized (no reward, no nudge copy) | ✅ | S12 rail “Designed absences” + L04 (no reward, nudge, cadence or setting) |
| X7 | No AI path publishes a KB article — AI results are drafts in the drawer; the publish button is a human's | ✅ | S02 B06 (no publish path in the AI drawer), S02 header absences |
| X8 | Retire never implies delete anywhere; the tombstone degrades with a label, never a 404 | ✅ | S03 C04 (Delete stated absent; archive keeps tombstones), S03 C03 (labeled degrade, never a 404) |
| X9 | Legal next states ABSENT (never greyed); refusals inline; nothing by color alone; 44 px targets; no failure vocabulary on customer surfaces | ✅ | every screen’s header “Designed absences”; S05 E02 (toggle absent, not greyed), S10 J02 (no type control), S06 F06 (refusal inline), S13 M04/M06 (refusals inline, 44 px targets) |

## Divergences from the built pages — all captioned in the files

1. E03 — the matched-count sparkline (S05).
2. G05 — the retarget diagram (S07).
3. E01 / J01 / K02 — drag as the enhancement WITH the built move buttons / placement menus (S05, S10, S11).
4. I05 — macro language variants (S09).
Additional divergences captioned per screen in each file’s header “Differs from build” block (S01–S13).

## Silences resolved by stated assumption (unchanged from the contract)

1. The per-tenant review checklist drawn as three fixture rows, captioned as Doc 16 content (S02 B08).
2. The widget star row drawn from FR-25’s contract only, captioned as the contract render (S12 L03).

## Notes for the fidelity session

- Every file is self-contained (inline CSS, no external fetches; the four https strings in S06/S13 are webhook destination TEXT, not resources).
- Chrome: the Backoffice nav from B5-S09 J01 with the Workflow group drawn as an indented sub-group (S05 onward); the built shell lists the rows flat — captioned.
- The prompt’s built-pages path `resources/js/Pages/Admin/Workflow/` does not exist; the Workflow pages live at `resources/js/Pages/Workflow/` (the checklist already cites the right path).
- Amharic on the staff surfaces is the built machine Amharic (R-14 native-review queue); article and form content Amharic is real fixture text, flagged for native review.

Rows: {len(rows)} screen rows, {len(rows)-bad} ✅, {bad} ❌ · {len(xrows)} absences ✅.
