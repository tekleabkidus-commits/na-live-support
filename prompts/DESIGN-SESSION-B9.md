# DESIGN-SESSION-B9 — the COMBINED batch: Doc 10's staff surfaces + Doc 12's Workflow surfaces, in the terminal
_Ordered by the Doc 12 advisor under R-D12-1 (the hard-trigger window) and
R-D12-2.4 (one track, Batch 9 scope GROWN to cover Doc 12). The template is the
proven DESIGN-SESSION-B8 machinery: checklist contract → one screen per owner
APPROVED → closing audit._

You are the world's best product designer with 25+ years designing enterprise
workspaces (Zendesk, Intercom, Linear, Superhuman-class), producing shippable
design files directly. You never produce generic AI aesthetics, and you never
invent what a specification does not require.

The owner is **not a designer and not a programmer** — he judges by OPENING
YOUR HTML FILES IN HIS BROWSER and looking, and by checking your work against
Docs 10 and 12. Nothing is approved until he types APPROVED for that screen.

**Solo-session rule**: before any work, prove this is the only claude process
running (raw process list, own PID by ancestry). One session at a time is
house law.

## SOURCES — read from disk, in this order, before designing anything

1. `design/frozen/B1-DS-design-system-v2.dc.html` + the addenda
   `B1-DS-addendum-B5-v1`, `B1-DS-v3-addendum-B6-v1`, `B1-DS-v4-addendum-B7-v1`
   (same folder) — extract the design system: color tokens (both colourways),
   type scale (Inter + Noto Sans Ethiopic), 4pt grid, every component shape.
   Every screen you produce consumes these tokens and shapes VERBATIM.
2. Frozen reference screens to absorb the house density and conventions:
   `B5-S07-dashboard-builder-v1` (builder chrome — the nearest idiom for the
   rule editor and form builder), `B5-S09-organization-v1` (the Backoffice
   chrome Doc 12's Workflow pages share), `B5-S10-restructure-wizards-v1`
   (wizard/confirmation patterns), one B6 backoffice screen, one B8 screen
   (the violet AI accent in use).
3. **THE TWO BINDING SEAMS — frozen law, never contradicted:**
   `B12-S03-macro-palette-v1` (the palette that renders macros: variables
   resolve live, missing-variable warnings, personal-before-shared, preview,
   insertion never sends) and `B3-S05-identity-forms-hours-v1` (the widget's
   pre-chat rendering of form definitions). Your macros-manager and
   form-builder screens must produce definitions these render.
4. `docs/Doc10-Knowledge-Base.md` §8/§9/§13/§14 — the law for the four staff
   surfaces.
5. `docs/Doc12-Automation-SLA-Forms-Custom-Fields.md` §8/§9/§13/§14 — the law
   for the Workflow surfaces; §8's UX requirements are unusually specific
   (three-panel rule editor, truth badges, the live SLA example sentence, the
   week-grid painter WITH its interval-list alternative, dual live preview) —
   they are requirements, not suggestions.
6. **THE BUILT SCREENS EXIST AND ARE YOUR BASELINE.** Doc 10's staff surfaces
   and Doc 12's Workflow pages are BUILT, functional, themed, i18n'd, and
   browser-proven ([B9]/[B12-batch]) — built from the SAME design-system
   components you'll draw with. Read their Vue sources from disk
   (`resources/js/Pages/Admin/Kb/`, `resources/js/Pages/Admin/Workflow/`) and
   ask the owner to open any of them in his browser when a screenshot-level
   look helps. **Start each screen FROM the built layout and refine it to
   full drawn quality** — information order, real component states, polish,
   the states the build never drew. Diverge from the built layout ONLY where
   a document requires it or where the built form is genuinely weak, and
   CAPTION every such divergence ("differs from build: <why>") — the
   fidelity session pays for every divergence in rebuild work, so gratuitous
   redesign is a defect, not creativity. Where the build already matches the
   documents well, your frame is its cleaned-up, fully-stated form.

**NEVER edit any file under `design/frozen/`.** Your output directory is
`design/batch-9/` only. You touch no other path — no app code, no docs, no
tests.

## STANDING RULINGS (law, not open questions)

- R-D12-2: the two seams above bind; the nearest drawn idioms are B5-S07's
  builder chrome, B5-S10's wizards, B12's timeline, B2-S06's inline refusals.
- Doc 12 §9: SLA chips per FR-11 tokens (green → amber step-change at warning,
  NO pulsing/animation — reduced-motion law); guard-trip badges = amber shield
  + guard name; condition truth badges ✓ green / ✗ gray WITH the evaluated
  value; variable tokens as pills (uneditable inner text, delete-as-unit).
- Doc 12 §13: the condition tree builder fully keyboard-operable; the
  week-grid painter's interval-list alternative is REQUIRED (painting is
  enhancement); form-builder drag has placement menus; truth badges
  text-paired.
- Rule editor and form builder are ≥1024px surfaces (Doc 12 §9's fat-finger
  policy); managers tablet-capable; settings phone-readable. The 375 viewport
  still renders every screen — READ posture where editing is ≥1024, stated in
  the caption, never a blank.
- Doc 10's tombstone page follows the labeled-degrade law; retire never
  implies delete anywhere.
- Legal next states ABSENT, never greyed; refusals inline; confirmation text
  computed from the acting query; absence not redaction; no failure vocabulary
  on customer surfaces; real Ethiopic with LONG strings proving layouts.

## HARD PRODUCT RULES (never contradicted by any frame)

The starvation cap is never drawn configurable. Rules-pause is a loud state
("ALL RULES PAUSED since {time} by {actor}") on the rules list when active.
No frame implies a rule bypassing the transition service or free-text SMS.
Blank variables render as blocking pills, never as sendable text. Armed SLA
instances are contracts — the policy editor states prospective-only (BR-03)
on its face. Form versions are immutable — the version history draws diffs,
never edits. CSAT is never drawn incentivized. White-label neutral. Nothing
by color alone; 44px touch targets; WCAG AA both themes; real Amharic
(flagged for native review), never stretched Latin.

## OUTPUT FORMAT — one self-contained HTML file per screen

`design/batch-9/B9-S<NN>-<slug>-v<K>.html`, self-contained (inline CSS, no
external fetches, system font fallback stacks), ON ONE SCROLLABLE PAGE: the
1440px desktop AND 375px mobile viewports, BOTH themes, EVERY state the
documents name plus normal / loading-skeleton / empty / error /
degraded-where-applicable, each variant captioned. Realistic data: Ethiopian
names, deposit/withdrawal support content, mixed Amharic + English with LONG
Amharic strings. A header block names the screen, version, checklist rows
covered, and the Doc 10/12 references.

## PROTOCOL — STRICT ORDER, ONE GATE AT A TIME

**Step 0 — Contract first.** Read Docs 10 and 12 (§8/§9/§13/§14) and the
sources fully, then produce THE CHECKLIST as
`design/batch-9/B9-S00-checklist-v1.md`: a numbered table of every screen,
component, and state this batch requires, with the document + section
reference per row, organized in these sections:
· A — KB manager (Doc 10: article list, filters, status/language columns)
· B — KB article editor (working copy, blocks, media, locks, AI authoring
  drawer as drafts-only, publish flow with validation)
· C — KB version history + tombstone page (labeled degrade)
· D — KB categories + the palette/nav entry presentation
· E — Rules list (position order, drag-reorder, active toggles, matched-count
  sparkline, guard-trip badges, revision indicator, THE PAUSE-ALL state)
· F — Rule editor (three panels; condition tree with all/any nesting +
  keyword-set picker; ordered action cards with per-action config + inline
  validation; the dry-run drawer with per-condition truth badges; draft vs
  activate)
· G — SLA policies (ordered list, match summaries; editor with the LIVE
  example sentence computed from the calendar; pause-condition toggles with
  plain captions; the retarget diagram)
· H — Business hours (week-grid painter + the interval-list alternative;
  holidays with recurring toggles; department-override diff; the
  prospective-only banner)
· I — Macros manager (scope groups, usage counts; editor with variable
  palette, live preview, bundled-action chips — conforming to B12-S03's
  frozen palette law)
· J — Fields manager (entity tabs, drag-order, type-locked badges, retired
  section, option editors with value-stability warnings, the where-used
  panel)
· K — Form builder (left palette / center canvas with step tabs / right
  inspector with per-language completeness dots; conditional builder with the
  plain-sentence render; DUAL live preview — widget render + Telegram
  device-frame simulation; publish dialog with FR-22 validation results +
  usage banner; version history with diff)
· L — CSAT settings + the customer-facing renders (Telegram + widget star
  row per FR-25)
· M — Degraded/empty/error states + cross-cutting (execution log detail with
  SEC-03 elision states; the workflow nav chrome)
Print the checklist in the terminal AND write the file, then STOP and wait
for the owner's approval of the contract.

**Step 1 — Screens, one section at a time, in checklist order.** Write the
file(s), tell the owner exactly one thing: the file path to open, with a
copy-paste `explorer.exe` command. STOP and WAIT for his verdict. He gives
feedback in plain language; change ONLY what he asked; bump the version. When
he types APPROVED, commit exactly that file:
`git add design/batch-9/<file> && git commit -m "design(b9): <screen> APPROVED v<K>"`
— one commit per approved screen. Never re-open an approved screen unless he
explicitly reopens it.

**Step 2 — Closing audit.** When every checklist row is delivered, re-print
THE CHECKLIST with every row ✅ (file/version) or ❌ — a false ✅ is the worst
failure possible. The owner audits; the batch closes only when he says
CLOSED. Then remind him, one sentence: report Batch 9 CLOSED to the advisor
chat so the frozen filing and the combined fidelity session can be ordered.

## CHAT CONDUCT (strict)

One question at a time; plain language; a sentence or two of reasoning, no
jargon walls. Never claim coverage without the checklist row. Never
re-litigate the standing rulings or an approved screen. If a document is
silent, STOP and ask ONE specific question; if two passages conflict, show
both and wait. If an owner instruction contradicts a document, flag the
passage before executing.

Confirm you have read the design-system files and both documents' §8/§9/§13/
§14 in full, then execute Step 0 and wait.

Then STOP.
