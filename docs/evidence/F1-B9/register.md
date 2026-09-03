# SESSION-F1 · [B9] fidelity register — Doc 10 KB staff surfaces vs `design/frozen/B9-S01…S04` (67dc4e4)

Legend as in `docs/evidence/F1-B8/register.md`: **DONE** = built and proven (the proof named) · **PARTIAL** = only with a named follow-on · **NOT-DONE** = out of reach, reason + follow-on. Deviations: `docs/evidence/F1-B9/deviations.md` (B9-Dnn). Proofs: `tests/Browser/f1-b9.spec.ts`; frame-vs-build pairs under `docs/evidence/F1-B9/shots/`.

## 0. Proofs of record (the bucket)

| Proof | Evidence | Verdict |
|---|---|---|
| Browser record: `kb-manager` + `kb-a11y` + `inbox-palette` (byte-untouched) + `f1-b9` | `docs/evidence/F1-B9/b9-specs-04.txt` | 34 passed, EXIT=0 (record 04; records 01–03 are the fix-first runs) |
| Pest doors (Feature/Kb + Security/KbAiNeverPublishesTest), unmodified | `docs/evidence/F1-B9/b9-pest-01.txt` | 81 passed (2776 assertions) · EXIT=0 |
| `make lint` | `docs/evidence/F1-B9/b9-lint-02.txt` | EXIT=0 (pint · phpstan max · eslint · vue-tsc) |
| `make gates` (capture-first) | `docs/evidence/F1-B9/b9-gates-02.txt` | 14/14 PASS, EXIT 0 |
| Hex extraction (no token changed in [B9]) | `docs/evidence/F1-B8/tokens-hex-02.txt` stands (148/148 PASS) | — |
| CI look (one per bucket) | `docs/evidence/F1-B9/ci-look-01.txt` | see file |

## X. The batch's hard absences (X7 · X8 · X9 — audited on the four screens)

| # | Absence | Status | Proof |
|---|---|---|---|
| X7 | No AI path publishes a KB article | DONE | f1-b9 S02 B05/B06 + cross-cutting: the drawer has no publish verb; `KbAiNeverPublishesTest` |
| X8 | Retire never implies delete; the tombstone degrades with a label, never a 404 | DONE | f1-b9 S03 C03/C04: no delete on the archived record (`delete_absent` stated), the citation landing answers a STATE; no failure vocabulary probed |
| X9 | Legal next states absent, never greyed; refusals inline; nothing by colour alone; 44 px; no failure vocabulary | DONE | Restore absent on the current version; Publish absent for the non-publisher; the depth-3 wall; every dot/pill carries its word; 375 targets ≥ 44 px (f1-b9 S01/S02/S03/S04 375 tests) |

## A. B9-S01 KB manager — `Pages/Admin/Kb/Manager.vue` + `Components/Backoffice/BackofficeShell.vue`

| Row | Status | Proof / note |
|---|---|---|
| A01 | DONE | three panes in the Backoffice chrome (52 px top bar · 200 px nav · "Knowledge base" its own row); tree with counts, grips and ↑↓ (position door); list skeleton while the search door answers (B9-D02) — f1-b9 S01 §A |
| A02 | DONE | status pill per article from its variants (Draft · In review · Published · Archived) |
| A03 | DONE | ●◐○ dots per active language with tooltip text ("en: Current") and the sr-only words; the existing `kb-badge-<id>` text kept |
| A04 | DONE | amber INTERNAL tag with its tooltip; AI-usable inheritance on the tree row's `title` and in the detail ("inherited from …"; B9-D30) |
| A05 | DONE | Language · Visibility · Status selects (bold when on), search over title/summary + the staff search door (B9-D03), "N of M match", Clear — f1-b9 S01 filters |
| A06 | DONE | no results (query echoed in a code chip + "Create an article from this query" → the store door); empty category (two verbs, the move picker → the move door); fresh tenant (note + checklist; the state has no fixture — B9-D06) |
| A07 | DONE (absence) | f1-b9 S01 A07 with the read-only viewer: no internal row, no Internal word, no visibility option — absence, not redaction |
| A08 | DONE | 375: the read notice, the pane jump tabs (B9-D01), 44 px rows; no overflow; axe both themes |
| A09 | DONE | the fixture's Amharic titles wrap in the 64 px rows; the cross-cutting am sweep at 1440 + 375 |

## B. B9-S02 article editor — `Pages/Admin/Kb/Editor.vue`

| Row | Status | Proof / note |
|---|---|---|
| B01 | DONE | the FR-02 block set behind a toolbar + insert menu (B9-D12), block headers with grip · ↑↓ · ⋯; language tablist with dots + SOURCE tag; content-first: the toolbar/rail skeleton while read-only resolves |
| B02 | DONE | autosave every 5 s through the working-copy door; the stamp says "working copy, not a version"; "Saving… · N unsaved edits"; "differs from vN" (B9-D31) |
| B03 | DONE | lock acquire/heartbeat/release/takeover doors; held banner names the holder (server-resolved name, B9-D19); the loser's danger banner; read-only posture |
| B04 | DONE | image block: upload door, alt required with the AC-10 line, upload-failed (Retry/Remove), virus-blocked (Remove), retention placeholder (B9-D20) |
| B05 | DONE | amber `role=note` banner under the header, repeated above the fold on long articles (B9-D32); rail states Copilot-only + the audited audience change (the visibility door behind a confirm) — f1-b9 S02 B05 |
| B06 | DONE | right-side AI drawer: five tools (three act, two start drafts — B9-D17), running → ai:true blocks via the recover door, AI-off from the banner door (B9-D18), the drafts-only foot, NO publish control — f1-b9 S02 B06 |
| B07 | DONE | flags inline on the block with the dotted underline, popover (pattern · rationale · Edit / Keep-with-reason · BR-06 note), the kept chip from the version's override, the rail summary, the pre-publish banner + "Next flag" — f1-b9 S02 B07 |
| B08 | DONE | Submit / Request changes / Approve & publish by permission + status; the sentence for each; comments anchored to the focused block with Jump; the fixture checklist (B9-D16) |
| B09 | DONE | publish dialog: variant · audience · AI-usability (category link) · publishing lang only · sanitizer · alt gate · flips-to-outdated (source) · version note · what publish does; BLOCKED state from the door's refusal (missing alt / missing media with jump links) — f1-b9 S02 B09 |
| B10 | DONE | pipeline strip from `index_status` (saved · sanitized · indexing / indexed / index pending + Retry = re-publish); B9-D21 for the chunk progress |
| B11 | DONE | stats header (views by surface · citations · helpful · deflection; empty variant; sparkline absent — B9-D14) + the feedback list (score / actioned filters, Mark actioned, cursor paging; B9-D15) |
| B12 | DONE | Alt+↑/↓ moves the focused block with a live announcement; the insert menu and block menus are keyboard-reachable; popovers open from a button — f1-b9 S02 B12 |
| B13 | DONE | 375 read-only: readonly fields, no toolbar/move buttons, the notice, 44 px tabs; axe both themes |
| B14 | DONE | the Amharic variant: title/summary labels per language, the transliteration helper (B9-D23) — f1-b9 S02 B14 |

## C. B9-S03 version history + tombstone — `Pages/Admin/Kb/History.vue`, `Pages/Kb/Tombstone.vue`

| Row | Status | Proof / note |
|---|---|---|
| C01 | DONE (pair anatomy unproven on the one-version fixture) | two panes; version rows with author · note · badges (B9-D24); View / Compare / Restore (absent on the current); the diff pane: +/−/±/= blocks, word marks (B9-D25), collapsed stubs, prev/next landmarks, summary counts; empty-compare message |
| C02 | DONE | restore CONFIRMATION with from / creates / keeps / history + the EC-05 media warning + the unsaved-edits warning (B9-D26) → the restore door |
| C03 | DONE (staff landing) · NOT-DONE (widget Help panel — Doc 13, plan §4) | the staff citation landing: state chip (Current · Superseded · Retired · Unknown), the version-pinned snapshot, what changed since (diff summary + compare link), one primary, the resolver-states rail with the no-404 law (B9-D28) — f1-b9 S03 C03 |
| C04 | DONE | archive dialog with the consequences in nouns (leaves · keeps · undo · audited) → the archive door; the archived banner with Un-archive → Draft and Open tombstone; the un-archive card names Delete as absent (B9-D27) |

## D. B9-S04 categories + the shell's one KB entry — `Manager.vue` (categories mode)

| Row | Status | Proof / note |
|---|---|---|
| D01 | DONE | the category editor: name per language with dots, slug + hint, parent (read-only, B9-D08), description per language, icon picker (12), position, AI-usable switch, visibility default + BR-03 hint; the depth-3 wall (no "+ Add subcategory", the wall card) proven on fixture subcategories built and removed through the real doors; delete refused with articles → the reassign picker driven by the impact door, the zero-impact alternative, soft delete at 0 (B9-D11) — f1-b9 S04 |
| D02 | PARTIAL — follow-on **F1-FO-4** | ↑ ↓ reorder through the position door with the live line and Alt+↑↓; ← → (indent/outdent) ABSENT — no reparent door (B9-D08/D09) |
| D03 | DONE | AI-usable inheritance on hover + the rail; the EC-02 off dialog with the numbers (published public leaving, internal unaffected, audited) → the update door; the internal-split card on a not-AI-usable internal-default category |
| D04 | DONE | one "Knowledge base" nav row, no sub-navigation (f1-b9 S04 D04); the palette row is the built P8 one (`kb-manager.spec` P8 test, untouched) |

## L. Cross-cutting

| Row | Status | Proof / note |
|---|---|---|
| Both themes | DONE | axe light + dark on every screen; tokens only (the frozen KB colours map onto existing `--ds-*` tokens — no token added) |
| 1440 + 375 | DONE | every screen's 375 read posture with 44 px targets |
| Amharic | DONE | f1-b9 L04: every KB page in the am locale, 1440 + 375, light + dark, zero shell overflow; machine am → R-14 queue (the kb.php keys are listed in `docs/evidence/F1-B9/r14-am-keys.txt`) |
| Reduced motion | DONE | f1-b9 L05: zero animated/transitioning elements on the manager and the editor |
| Absences | DONE | f1-b9 cross-cutting: no "verified", no per-article AI switch, no edit control on a version, no publish in the drawer |

## Totals

Rows: 3 (X) + 9 (A) + 14 (B) + 4 (C) + 4 (D) = 34. DONE 32 · PARTIAL 1 (D02, follow-on F1-FO-4) · NOT-DONE half 1 (C03's widget Help panel — Doc 13, recorded before the first commit).
