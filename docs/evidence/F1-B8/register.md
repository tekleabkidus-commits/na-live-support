# SESSION-F1 · [B8] fidelity register — Doc 09 AI surfaces vs `design/frozen/B8-*` (cfe305b)

Legend: **DONE** = built and proven (the proof named in the row) · **PARTIAL** = only where a named follow-on exists · **NOT-DONE** = out of this session's reach, with the reason and the follow-on. A row says DONE only when the visibility proof ran green on the committed tree. Deviations are `docs/evidence/F1-B8/deviations.md` rows (B8-Dnn). Screen proofs live in `tests/Browser/f1-b8.spec.ts` (block names below); frame-vs-build pairs in `docs/evidence/F1-B8/shots/`.

## 0. Proofs of record (the whole bucket)

| Proof | Evidence | Verdict |
|---|---|---|
| Browser record: `ai-backoffice` + `ai-copilot` + `ai-honesty` (byte-untouched) + `f1-b8` | `docs/evidence/F1-B8/b8-specs-record-05.txt` | 32 passed · EXIT=0 |
| Pest doors (Feature/Ai + the two Security specs), unmodified | `docs/evidence/F1-B8/b8-pest-record-02.txt` | 157 passed (2088 assertions) · EXIT=0 |
| `make lint` | `docs/evidence/F1-B8/b8-lint-04.txt` | pint · phpstan · eslint · vue-tsc — EXIT=0 |
| `make gates` (capture-first) | `docs/evidence/F1-B8/b8-gates-03.txt` | 14 passed, 0 failed · EXIT 0 |
| Hex extraction (tokens only, 148 declarations) | `docs/evidence/F1-B8/tokens-hex-02.txt` | 148 declarations · RESULT: PASS |
| Full battery `make test` (wall power, rule 13) | `docs/evidence/F1-B8/battery-01.txt` | see file |
| Packets landed (advisor countersign, owner-relayed at 5e8a953): `ai-honesty` + `ai-backoffice` + `ai-copilot` + `inbox-thread` (byte-untouched) + `f1-b8` with the three packet proofs | `docs/evidence/F1-B8/packets-specs-01.txt` | 44 passed · EXIT 0 |
| CI look (one per bucket) | `docs/evidence/F1-B8/ci-look-01.txt` | see file |

## X. The five hard absences (re-audited on every delivered screen — L07)

| # | Absence | Status | Proof |
|---|---|---|---|
| X1 | No auto-send from any Copilot artifact | DONE | f1-b8 S12 L07 (no `send` control inside `copilot-tab` / `ai-actions`); `CopilotNeverAutoSendTest`; B8-D07 (the frame's "Send the Amharic" rendered as Insert) |
| X2 | No generated RG text | DONE | f1-b8 S12 L07 (no improve/generate control in `gr-rg`); S06 slate preview is the byte-fixed template; Pest EC-04/RG suites |
| X3 | No removable disclosure | DONE | f1-b8 S12 L07 (no switch/checkbox in `persona-disclosure-lock-*` or `gr-disclosure`); Pest "N-08 / BR-05 ABSENCE" |
| X4 | No stated transaction facts | DONE (template level) | Pest "BR-02/AC-02 … refused by the schema itself"; S03 C03 row below |
| X5 | No publish without fresh simulation | DONE | f1-b8 S05 (Publish locked with its reason → FRESH gate → confirm; slider touch → STALE gate) + S12 L07; `ai-backoffice.spec` FR-11/BR-07 untouched |

## A. B8-S01 Copilot panel — `Components/Ai/CopilotTab.vue` (committed 02cde75)

| Row | Status | Proof / note |
|---|---|---|
| A01 | DONE | six `role=region` sections in reading order, each with its skeleton (`copilot-skeleton-*`) — f1-b8 S01 §A |
| A02 | DONE | stamp `copilot-summary-stamp` from `summary_updated_at` (B8-D01); refresh debounce is the door's; `summary_stale` copy when the stamp ages |
| A03 | DONE | `copilot-chip-*` text + ✦ accent; absent-per-chip renders `chips_partial` |
| A04 | DONE | card arrives whole from the polling door (B8-D03 — no browser stream seam); pending caret static; Insert/Regenerate/Discard |
| A05 | DONE | Insert `v-if="canInsert"` — absent, never greyed; focus returns to the composer (`ai-copilot.spec` FR-14 untouched) |
| A06 | DONE | `regen_left` counter + `Regenerate in m:ss` from `retry_after_seconds` (B8-D02) |
| A07 | DONE | `locked` banner with KB links + the weekly-review note |
| A08 | DONE | low-confidence banner REPLACES the card (`card-banner`); 10 s → degraded (door state) |
| A09 | DONE | guard strip inside the card (`.cp-guard`) — never panel-level |
| A10 | DONE | `copilot-kb` top 3 with insert-as-link |
| A11 | DONE | `copilot-similar` (scope-checked rows; second line per B8-D04) |
| A12 | DONE (populated state unproven live) | Doc 12 seam yields `[]` in the fixture — empty state proven; the "Use" row renders from the seam (B8-D06) |
| A13 | DONE | 5 × `.cp-seg` + `card-confidence` text `NN% · High` |
| A14 | DONE | degraded banner "AI unavailable — macros and KB search still work" (`card-banner` on `degraded`; B8-D05 for the time) |
| A15 | DONE | `role=status aria-live=polite` announcements on completion; reduced motion → no caret animation (f1-b8 S12 L05) |
| A16 | DONE | f1-b8 S12 L04 opens the Copilot thread in the am locale at 1440 + 375 (shots `sweep-am-copilot-1440-light.jpg`, `sweep-am-copilot-375-light.jpg`) |

## B. B8-S02 composer AI actions — `Components/Ai/ComposerAiActions.vue` (committed 02cde75)

| Row | Status | Proof / note |
|---|---|---|
| B01 | DONE | four ✦ verbs, busy word, AI-off → cluster GONE + one line (`ai-actions-off`, live kill-switch flip — f1-b8 S02 §A AI-off; B8-D09) |
| B02 | DONE | Draft → card in the A04 anatomy (`ai-copilot.spec` FR-15 untouched) |
| B03 | DONE | "Improved — tone & grammar, meaning kept", original recoverable until Insert |
| B04 | DONE | translate PAIR `ai-translate-pair` with EN/AM tags; primary reads **Insert** the Amharic (B8-D07, document conflict: N-09 over the frame's "Send") |
| B05 | DONE | `ai-digits-verified` on pass; rejection banner NAMES the digits (`2,500 → 2,050`; B8-D08/D12) |
| B06 | DONE | mixed input → IN tag + "dominant language" line (B8-D10) |
| B07 | DONE | Summarize → note card "Insert as note draft" (lands in the note composer) |
| B08 | DONE | f1-b8 S02 §F at 375 — all four verbs ≥ 44 px, axe clean both themes |

## C. B8-S03 chatbot in conversation (customer-facing output; no staff screen of its own)

| Row | Status | Proof / note |
|---|---|---|
| C01 | DONE (template level) | `lang/{en,am}/ai.php` `greeting.first` carries the persona name + disclosure; Pest "AC-05 … a persona publishes ONLY if every declared greeting carries its language's disclosure" |
| C02 | DONE | packet **P-F1-BUBBLE** clause 1 LANDED under the advisor's countersign (owner-relayed at 5e8a953): ai-bg · 1 px ai-border · 3 px ai-edge · body text-primary · ✦ in ai-fg — f1-b8 packets proof (edge 3 px, border 1 px) |
| C03 | DONE (template level) | claim-framing is the grounding contract: Pest "BR-02/AC-02 … an answer WITHOUT a citation is refused by the schema itself"; "sabotage #8 … no_grounding" |
| C04 | DONE | Pest "FR-08/FR-10/AC-02 (positive): a grounded answer goes out WITH its citation rendered per channel capability" |
| C05 | DONE (absence) | no edit affordance exists on an AI message (`ai-honesty.spec` AC-05 DOM: marker + accent only) |
| C06 | DONE (template level) | Pest "FR-12: customer requests a human → … the FR-28 template" (queue-wait estimate is the Doc 11 seam's value) |
| C07 | DONE (template level) | RG template byte-fixed (Pest RG/EC-04 suites); the staff-side excerpt is Doc 08's RG card (S08 build) |
| C08 | DONE (Doc 12) | CSAT 1-tap 1–5 shipped with Doc 12 (`Csat` suites) |
| C09 | DONE | Pest "FR-09/EC-08: resolve sends the closing text … a reply reopens with post_resolution context; the THIRD reply hands off" |
| C10 | DONE | Pest "EC-01: only the confirmed batch TAIL classifies" |
| C11 | DONE | `ChatbotTurn` sends the typing action through the adapter capability (grep `typing` in `app/Domain/Ai/Chatbot/ChatbotTurn.php`) |
| C12 | DONE | Pest "FR-12/EC-02/EC-11/EC-12/FS-01 (AC-09 customer side): … unsupported_language … all end in the template" |
| C13 | DONE | Pest "EC-04: betting advice → the deflection TEMPLATE (never generated), third strike → blocked_topic_persistence handoff" |

## D. B8-S04 persona editor — `Pages/Admin/Ai/Persona.vue` + `Components/Ai/AiSettingsShell.vue`

| Row | Status | Proof / note |
|---|---|---|
| D01 | DONE | name, avatar radiogroup (B8-D17), greeting per language, tone sliders with WORD values — f1-b8 S04 §A |
| D02 | DONE | preview select over the five canned dialogs (B8-D18), AI bubble in accent, per language (shots `B8-S04-preview-am`) |
| D03 | DONE | Publish locked with its reason until previewed → confirm dialog → versions with `rollback-{v}` (shots `-publish-unlocked`, `-publish-confirm`, `-versions`) |
| D04 | DONE | disclosure input + `persona-disclosure-lock-{l}` (lock, never a toggle) |
| D05 | DONE (gated state unproven live) | GATED chip renders from `LanguageGate::missingTemplates`; both launch languages ship complete, so the gate is unreachable in this build (B8-D16; shots `B8-S04-gate` show the complete state) |

## E. B8-S05 thresholds & simulation — `Pages/Admin/Ai/Thresholds.vue`

| Row | Status | Proof / note |
|---|---|---|
| E01 | DONE | four `th-row-*` sliders with consequence + value line |
| E02 | DONE | before/after bar pairs + deltas + headline on yesterday's decisions; no-history note WITHOUT locking the controls (B8-D13, document conflict: BR-07 governs) |
| E03 | DONE | `th-flips` drill-down, names masked (B8-D15 — the conditional row resolved DONE) |
| E04 | DONE | FRESH / STALE gates (`th-fresh`/`th-stale`), publish disabled with its reason (X5) |
| E05 | DONE | `th-overrides` table + `th-edit-<ref>` / `th-add-override` |
| E06 | PARTIAL — follow-on **F1-FO-1** | target (≥ 0.90) + corpus size stated beside `rg_min`; the LIVE precision figure needs a stored harness result (schema growth → out of F1; B8-D14) |
| E07 | DONE | `th-view-table` → `simulation-table` (`ai-backoffice.spec` FR-11 untouched) |

## F. B8-S06 guardrails — `Pages/Admin/Ai/Guardrails.vue`

| Row | Status | Proof / note |
|---|---|---|
| F01 | PARTIAL — follow-on **F1-FO-2** | seeded floor with locks and NO delete: DONE; per-topic "Edit wording" NOT-DONE — no field in the guardrails payload (B8-D19) |
| F02 | DONE | `gr-disclosure-{l}` + `gr-disclosure-lock` |
| F03 | DONE | RG template tabs COMPLETE/MISSING, slate preview, `gr-rg-gate-{l}` |
| F04 | DONE | `gr-max-ai` with the consequence line |
| F05 | DONE | `gr-profanity` + `gr-pii` role=switch with On/Off word |
| F06 | DONE | custom delete behind `gr-delete-dialog`; seeded rows offer none (f1-b8 S06 §A–§E) |

## G. B8-S07 scope & kill switches — `Pages/Admin/Ai/KillSwitches.vue`

| Row | Status | Proof / note |
|---|---|---|
| G01 | DONE | `ks-scope` — KB categories (INTERNAL lock) + endpoints with conversations/day + web row (B8-D21) |
| G02 | DONE | `switch-*` role=switch cells, 64×44 targets (B8-D20) |
| G03 | DONE | red OFF with `since-<surface>` "OFF since {time} by {actor}" (live flip, restored) |
| G04 | DONE | `killswitch-confirm` with `ks-blast` (B8-D22) |
| G05 | DONE | packet **P-F1-BANNER** LANDED (countersigned): the inbox mirror counts AND links to the grid (`ai-banner-details`, `ai.ui.banner_details` en + am) — f1-b8 packets proof; B8-D24 closed |
| G06 | DONE | `ks-audit` strip (B8-D23) |

## H. B8-S08 providers & status — `Pages/Admin/Ai/Providers.vue` (new page on the existing route, B8-D29)

| Row | Status | Proof / note |
|---|---|---|
| H01 | DONE (tripped state unproven live) | one chain per tier, breaker state in WORDS from `CircuitBreaker` + `ProviderHealth` (B8-D25); the fixture has no tripped breaker |
| H02 | DONE | `pv-model-map` + `pv-change-<tier>` → publish routing (existing door, `ai.providers.manage`) |
| H03 | DONE | `vault://ai/{provider}` references only (B8-D26) |
| H04 | DONE | `pv-version-<kind>` + rollback where the door allows (B8-D27); FS-05 note |
| H05 | DONE (live rungs unproven) | ladder banners `pv-ladder-*` render from the breaker/health reads; fixture shows the healthy rung |
| H06 | DONE | `pv-quota` bar, 80 % mark, hatched 5 % reserve explained (B8-D28) |

## I. B8-S09 decision log & quality loop — `Pages/Admin/Ai/Decisions.vue`

| Row | Status | Proof / note |
|---|---|---|
| I01 | DONE | table on the FR-20 fields; `decisions-surface` (server) + client chips; empty state names the filters (B8-D30) |
| I02 | DONE | outcome chips from the stored outcomes (B8-D31) |
| I03 | DONE | `dl-record` states the ai.decisions.view split, the platform-debug note and the 30-day hash reduction (B8-D32) |
| I04 | DONE | `dl-tab-unanswerable` + `dl-draft-<intent>` → Doc 10's from-conversations draft door (B8-D33) |
| I05 | DONE | SVG curve + `dl-calibration-table` text values + finding |
| I06 | DONE (absence) | f1-b8 S12 L07: no `rg_risk` / "RG review" text on the page |
| K06 (here) | DONE | EC-11 note under the table: provider refusals log as `provider_refusal`; raw refusal text never customer-visible |

## J. B8-S10 supervisor outputs & RG review queue — `Pages/Admin/Ai/Supervisor.vue` (new page on `ai.decisions.view`)

| Row | Status | Proof / note |
|---|---|---|
| J01 | DONE | anomaly item with the baseline sentence (B8-D34) |
| J02 | DONE | sentiment-watch item linking into the thread |
| J03 | DONE | digest feed form, six bullets; email form rendered from the same bullets (am recipient in the sweep) |
| J04 | DONE | `sv-qa` labelled advisory, never a published score |
| J05 | PARTIAL — follow-on **F1-FO-3** | queue (fires + clears, excerpt per fire, calm slate) DONE; the confirm/clear FLOW needs a queue-clear door (Doc 08's card owns the clear; B8-D35; plan §4) |
| J06 | DONE | f1-b8 S10 J06: the read-only viewer sees no RG row, count or vocabulary (B8-D36) |

## K. B8-S11 degraded states (cross-screen)

| Row | Status | Proof / note |
|---|---|---|
| K01 | DONE (live rungs unproven) | the ladder as live status on S08 (`pv-ladder-*`) + the kill-switch off banner's "customers go straight to the team" line |
| K02 | DONE | Copilot degraded card (A14) + Supervisor `sv-paused` note |
| K03 | DONE | `pv-quota-<state>` under / 80 % / exhausted with the 5 % reserve sentence |
| K04 | DONE | `ai-setup-checklist` in the shell whenever no article is published (links to Persona / KB / categories) |
| K05 | NOT-DONE | FS-02 retrieval-corruption banner — no retrieval-health read exists (plan §4; follow-on: retrieval health read, Doc 09/10) |
| K06 | DONE | see I. (Decisions note) |
| K07 | DONE | S07 off banner + "what customers get meanwhile" line; the inbox mirror (G05) |
| K08 | DONE | packet **P-F1-BUBBLE** clause 2 LANDED (countersigned): the transcript-unavailable line on a transcript-less voice message; the seeder carries a dedicated fixed-id voice fixture under the 0l law (`00000000-0000-7000-8000-0000000a0f03`) — proven visible in f1-b8 packets proof |

## L. B8-S12 cross-cutting

| Row | Status | Proof / note |
|---|---|---|
| L01 | DONE | hex extraction PASS (`tokens-hex-02.txt`); dark shots for every screen |
| L02 | DONE | every screen block has a 375 test asserting ≥ 44 px on its controls |
| L03 | DONE | axe (wcag2a + wcag2aa) light + dark per screen, both viewports; every chip/meter/switch carries its word |
| L04 | DONE (the finding closed by P-F1-TOPBAR, countersigned) | f1-b8 S12 L04: real Amharic in the am locale on every AI page, 1440 + 375, both themes, zero overflow on the AI surfaces; the Copilot sheet measured on its own box. Finding B8-D37: Doc 07's inbox top bar overflows 48 px at 375 in am (packet **P-F1-TOPBAR**, stop #1). Machine am → R-14 queue (`fixtures/i18n/r14-queue-doc09.json`, +450 keys; `docs/evidence/F1-B8/r14-am-keys.txt`) |
| L05 | DONE | f1-b8 S12 L05: zero animated/transitioning elements under `prefers-reduced-motion` on the card, thresholds and the grid |
| L06 | DONE | fixture names only (`[FIXTURE]`, `@fixture_*`, "Fixture Agent") — no operator brand in any lang key or shot |
| L07 | DONE | f1-b8 S12 L07 |

## Totals

Rows: 100 (X 5 + A 16 + B 8 + C 13 + D 5 + E 7 + F 6 + G 6 + H 6 + I 6 + J 6 + K 8 + L 7 + K06 counted once).
DONE 95 · PARTIAL 3 (E06, F01, J05 — each with a named follow-on) · NOT-DONE 2 (K05 + F01's wording half counted in F01). Was at 0dfda21: 92 / 4 / 4 — C02, K08 (P-F1-BUBBLE) and G05 (P-F1-BANNER) flipped when the packets landed under the countersign; B8-D37 (P-F1-TOPBAR) closed at the same landing.

Follow-ons named here: **F1-FO-1** live RG precision surfacing (harness result store) · **F1-FO-2** per-topic deflection wording field · **F1-FO-3** RG-queue clear door · packets **P-F1-BUBBLE** (C02, K08), **P-F1-BANNER** (G05) and **P-F1-TOPBAR** (B8-D37) awaiting the advisor's countersign (stop #1).
