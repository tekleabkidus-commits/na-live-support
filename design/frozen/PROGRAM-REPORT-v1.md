# Na Live Support — full program report

**PROGRAM-REPORT-v1** · 3 August 2026 · compiled against 165 files on disk

Batches 1 through 7: everything developed, decided, and still open. Compiled by reading every file on disk — file names, versions, chip states, checklist arrays and audit headers were extracted programmatically, not recalled. Anything not verifiable from a file is marked **UNVERIFIED** or **UNKNOWN — held by the owner/manager chat**, with its reason.

> Five verification rounds moved most of this report's absence markers from "chat-held" to sourced. Those corrections are left visible rather than smoothed over — a report about honesty should show its own.

## §1 — Program summary

### What was designed

An AI-first customer support platform for Ethiopian operators, white-labelled, English and Amharic throughout. Seven batches covered: the agent inbox and ticket operations (1+2), the customer-facing chat widget and its designer (3), customer profiles and identity work including merge and RG blocking (4), supervisor live dashboards, the eight report families, the dashboard builder and organization structure (5), Telegram channel administration, RBAC, the settings backoffice, feature flags, translation management and the incident page (6), and authentication with the white-label branding editor (7).

### Batch structure as run

**Batches 1 and 2 shared one chat by owner exception** and were reconciled into a single checklist and audit — `B12-S00-combined-checklist-audit-v3` records Batch 1's 179 rows and Batch 2's 74 collapsed into **221** with duplicates removed and nothing dropped. Batches 3, 4, 5, 6 and 7 each ran in their own chat with their own Step 0 contract, per-section delivery, and a closing audit. Row counts measured from the checklist arrays: Batch 3 **119**, Batch 4 **78**, Batch 5 **166**, Batch 6 **183**, Batch 7 **202**. Program total across all contracts: **969 rows**.

### Design-system lineage

`B1-DS-design-system-v1` established the three directions and the chosen one; **v2** is the declared repair carrying the 44 px floor and the lightbox addendum; the **B5 addendum** added Batch 5's chart and intervention components; **v3** promoted seven Batch 6 components; **v4** promoted seven Batch 7 components, additive only, with canonical forms measured from the drawn instances and a reconciliation note where instances disagreed. All five files are on disk. **No v5 exists and none is needed** — the dark-variant question raised at Batch 7 Section U was withdrawn when v2 §2 was found to carry a complete dark column already.

### Current state, in three sentences

Batches 1 through 6 are closed with their audits on disk; Batch 7's closing audit marks 21 of 22 sections ✅. Two items are open and both are named honestly rather than absorbed: the Amharic destructive-tier clearance, which is the owner's, and the AC-09 baseline export, which is the designer's and held by sequence. The seven-batch design program covers the surfaces its documents named; Docs 09, 10, 12, 14 and 17 surfaces remain undesigned and are not scheduled.

## §2 — Complete file inventory

### Batches 1 + 2 — agent inbox, ticket operations, mobile

*combined by owner exception · 221 reconciled rows (179 + 74)*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B1-DS-design-system-v1` | superseded | The three directions and the chosen one | — |
| `B1-DS-design-system-v2` | FROZEN | System of record for tokens; §2 fixed tokens in BOTH colourways; 44 px floor; lightbox addendum | v1 |
| `B1-S00-checklist-v1` | FROZEN | Batch 1 contract, 179 rows | — |
| `B1-S01-inbox-shell-v2` | FROZEN | Inbox shell; reopened for assignee refusal, tag autocomplete, KB hover preview | v1 |
| `B1-S02-inbox-states-v2` | FROZEN | Inbox state matrix; reopened for the visible, focusable bulk-bar disabled reason | v1 |
| `B1-S03-views-search-notify-v2` | FROZEN | Views, search, notifications, shortcuts; reopened for the four-section views sheet (FR-08) | v1 |
| `B1-S04-ticket-operations-v1` | FROZEN | Ticket operations | — |
| `B2-S00-checklist-v1` | FROZEN | Batch 2 contract, 74 rows, carried rows marked | — |
| `B2-S01-mobile-interactions-v3` | FROZEN | Mobile interactions; scroll restoration on back (FR-12) | v1, v2 |
| `B2-S02-mobile-search-status-v3` | FROZEN | Mobile search, notifications, agent status, reach and density; scope toggle removed per BR-03 | v1, v2 |
| `B2-S03-ticket-properties-v2` | FROZEN | Pending reason, timeline, subject, mobile sheet | v1 |
| `B2-S04-state-affordances-v2` | FROZEN | State affordances and the bulk tail; close 63 | v1 |
| `B2-S05-states-v2` | FROZEN | Panel, races, mobile, sheets | v1 |
| `B12-S01-media-lightbox-v3` | FROZEN | Media lightbox; promoted to a B1-DS addendum on approval | v1, v2 |
| `B12-S02-edit-marker-v2` | FROZEN | Edit marker, revision history, tombstone | v1 |
| `B12-S03-macro-palette-v1` | FROZEN | Macro and saved-reply palette | — |
| `B12-A01-behaviour-acceptance-v1` | FROZEN | Nine build-acceptance behaviours (AC-01…AC-09) | — |
| `B12-R01-drift-report-v1` | FROZEN | Component reconciliation against B1-DS — 79 corrections across 7 files | — |
| `B12-S00-combined-checklist-audit-v3` | FROZEN | The combined 221-row checklist and audit | v1, v2 |
| `B2-S06-closing-audit-v2` | FROZEN | Batch 2 closing audit | v1 |

**Closing audit.** Closing audit: B12-S00-combined-checklist-audit-v3 — every row ✅ or handed to build acceptance; B2-S06-closing-audit-v2 records every Batch 2 row ✅ after seven owner rulings.

### Batch 3 — customer chat widget

*119 rows · 11 screens*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B3-S00-checklist-v2` | FROZEN | Batch 3 contract, 119 rows; reopened to add E07 | v1 |
| `B3-S01-launcher-panel-v1` | FROZEN | Launcher and panel shell | — |
| `B3-S02-home-v1` | FROZEN | Home tab | — |
| `B3-S03-messages-v1` | FROZEN | Messages — one continuous thread | — |
| `B3-S04-help-v1` | FROZEN | Help tab | — |
| `B3-S05-identity-forms-hours-v1` | FROZEN | Identity, forms and hours | — |
| `B3-S06-composer-uploads-v1` | FROZEN | Composer, uploads, voice | — |
| `B3-S07-proactive-notifications-v1` | FROZEN | Proactive, notifications, multi-tab | — |
| `B3-S08-widget-designer-v3` | FROZEN | Widget Designer; closes A04, A06, A07; host-page directives only. Source of the shared contrast refusal reused in Batch 7 Section L | v1, v2 |
| `B3-S09-degradation-v1` | FROZEN | Degradation | — |
| `B3-S10-theme-language-access-v1` | FROZEN | Theme, language, access — rules made visible | — |
| `B3-A01-behaviour-acceptance-v1` | FROZEN | Build-acceptance rows; 1 row on disk (AC-B3-01, no onboarding layer on first open) | — |
| `B3-S11-closing-audit-v3` | FROZEN | Batch 3 closing audit | v1, v2 |

**Closing audit.** Closing audit: B3-S11-closing-audit-v3 — all 119 rows ✅; the three Section A gaps closed in B3-S08-v2 once Doc 02 T-07's header set was supplied.

### Batch 4 — customer profiles and identity

*78 rows · 8 screens*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B4-S00-checklist-v2` | FROZEN | Batch 4 contract, 78 rows; EC-10 to EC-12 folded in | v1 not on disk |
| `B4-S01-customer-panel-v1` | FROZEN | The Customer panel | — |
| `B4-S02-profile-page-v1` | FROZEN | The full profile page | — |
| `B4-S03-identity-work-v2` | FROZEN | Identity work; out-of-scope frame added (C02-v2) | v1 |
| `B4-S04-merge-v2` | FROZEN | Merge; MERGE token shown beside the input in both layouts | v1 |
| `B4-S05-rg-blocking-v1` | FROZEN | The RG flag, and blocking | — |
| `B4-S06-directory-v1` | FROZEN | The directory | — |
| `B4-S07-data-honesty-v2` | FROZEN | Data honesty, export, erasure; prohibition callout | v1 |
| `B4-S08-cross-cutting-v1` | FROZEN | Cross-cutting proof | — |
| `B4-A01-behaviour-acceptance-v1` | FROZEN | Three build-acceptance rows: RG reads never cached, flag history exports whole, inline-edit announce cycle spoken | — |
| `B4-S09-closing-audit-v1` | FROZEN | Batch 4 closing audit | — |

**Closing audit.** Closing audit: B4-S09-closing-audit-v1 — every row ✅, nothing ❌; three rows (A16, D13, H03) admitted by the EC-10/11/12 rulings; three behaviour obligations named at the foot rather than absorbed.

### Batch 5 — analytics, reports, organization

*166 rows · 10 screens*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B5-S00-checklist-v3` | FROZEN | Batch 5 contract, 166 rows; v2 folded in a viewport ruling, v3 reversed it | v1, v2 |
| `B5-S01-live-dashboard-v2` | FROZEN | Chart foundation and supervisor live dashboard (A01–A16, B01–B18); v2 made 375 fully executable | v1 |
| `B5-S02-interventions-v2` | FROZEN | Interventions and explainability (C01–C14); v2 phone-executable with Amharic confirmations — the Batch 5 destructive risk map | v1 |
| `B5-S03-tv-mode-v1` | FROZEN | TV mode (D01–D06) | — |
| `B5-S04-reports-home-v1` | FROZEN | Reports home (E01–E06) | — |
| `B5-S05-report-anatomy-v1` | FROZEN | Report page anatomy, filters, drill-down (F01–F19) | — |
| `B5-S06-report-families-v2` | FROZEN | The eight report families (G01–G13); v2 renders all seven at 375 | v1 |
| `B5-S07-dashboard-builder-v1` | FROZEN | Dashboard builder (H01–H16) | — |
| `B5-S08-exports-schedules-v1` | FROZEN | Exports and schedules (I01–I13) | — |
| `B5-S09-organization-v1` | FROZEN | Backoffice → Organization (J01–J15) | — |
| `B5-S10-restructure-wizards-v1` | FROZEN | Restructure wizards (J16–J21) — destructive-tier confirmations | — |
| `B5-S11-closing-audit-v2` | FROZEN | Closing audit (K01–K10, L01–L13) | v1 |
| `B1-DS-addendum-B5-v1` | FROZEN | B1-DS addendum from Batch 5 — five-part lightbox chrome plus chart components | — |

**Closing audit.** Closing audit: B5-S11-closing-audit-v2 — all 23 cross-cutting rows ✅. Self-audit found six gaps: four drawn in the audit file (K08 DST honesty, L04 Amharic in charts, L09 announcements, L10 reduced motion), two closed in their own files (L01 by drawing all seven family placeholders).

### Batch 6 — Telegram, RBAC, settings backoffice

*183 rows · 16 sections, 15 built*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B6-S00-checklist-v2` | FROZEN | Batch 6 contract, 183 rows; APPROVED, viewport ruled, both silences closed, Section M → Batch 7 | v1 |
| `B6-S01-telegram-bots-connection-v1` | FROZEN | Bot registry and webhook lifecycle (A01–A08, B01–B06) | — |
| `B6-S02-telegram-connections-v1` | FROZEN | Business connection lifecycle (C01–C15) | — |
| `B6-S03-reply-window-settings-v1` | FROZEN | Reply-window administration (D01–D04) | — |
| `B6-S04-menu-designer-v2` | FROZEN | Menu designer (E01–E06, E12, E13) | v1 |
| `B6-S05-bot-templates-commands-v1` | FROZEN | Bot messages and commands (E07–E11) | — |
| `B6-S06-channel-health-v2` | FROZEN | Channel health and observability (F01–F12) | v1 |
| `B6-S07-roles-list-v1` | FROZEN | Roles — list, compare, lifecycle (G01–G03, G12–G14, G19, G20) | — |
| `B6-S08-permission-matrix-v2` | FROZEN | The permission matrix (G04–G06, G08–G11, G15, G17, G18, G21, G22) | v1 |
| `B6-S09-user-overrides-v1` | FROZEN | User overrides and effective permissions (H01–H12) — the profile screen Batch 7 Section H extends | — |
| `B6-S10-rbac-safety-rails-v1` | FROZEN | RBAC safety rails and high-risk governance (I01–I07) | — |
| `B6-S11-settings-shell-v2` | FROZEN | Settings shell and the per-key contract (J01–J19) — renders the auth.* keys once | v1 |
| `B6-S12-notification-preferences-v2` | FROZEN | Notification preferences (K01–K11) | v1 |
| `B6-S13-feature-flags-v2` | FROZEN | Feature flags (L01–L12) | v1 |
| `B6-S14-translation-manager-v2` | FROZEN | Translation manager and language administration (N01–N13) | v1 |
| `B6-S15-config-export-import-v2` | FROZEN | Config export and import (O01–O07) | v1 |
| `B6-S16-incident-page-v2` | FROZEN | The incident page (P01–P04) — the 2 AM stop-button copy | v1 |
| `B6-X01-crosswalks-v2` | FROZEN | Three cross-walks re-issued against the final contract; one finding needed an owner ruling | v1 |
| `B6-A01-closing-audit-v3` | FROZEN | Batch 6 closing audit | v1, v2 |
| `B1-DS-v3-addendum-B6-v1` | FROZEN | B1-DS v3 addendum — seven Batch 6 components, additive only | — |

**Closing audit.** Closing audit: B6-A01-closing-audit-v3 — 17 cross-cutting rows, 16 ✅ and 1 N/E (Q15, not exercised: no Batch 6 surface renders AI content). Section M moved to Batch 7 by owner ruling.

### Batch 7 — authentication and white-label branding

*202 rows · 22 sections*

| File | Status | Covers | Earlier on disk |
|---|---|---|---|
| `B7-S00-checklist-v2` | FROZEN | Batch 7 contract, 202 rows, 22 sections; H07 replaced by the owner-ruled user list; Q2–Q4 recorded verbatim | v1 |
| `B7-S01-login-v1` | delivered | Login card, 18 rows, 8 frames | — |
| `B7-S02-two-factor-challenge-v2` | delivered | Two-factor challenge, 10 rows | v1 |
| `B7-S03-two-factor-enrolment-v1` | delivered | Enrolment wizard, 10 rows, show-once codes | — |
| `B7-S04-two-factor-enforcement-v2` | delivered | Enforcement, grace, admin reset, 7 rows; v2 announced the expired gate | v1 |
| `B7-S05-password-reset-invite-v3` | delivered | Reset, invite, set-password, 12 rows; v2 meter to canonical 34×5, v3 announced the expired link | v1, v2 |
| `B7-S06-sessions-devices-v3` | delivered | Sessions and devices, 15 rows; v2 announcement role, v3 added the dark table | v1, v2 |
| `B7-S07-api-tokens-v4` | delivered | API tokens, 11 rows; v2 Amharic, v3 scope-error roles, v4 dark list | v1, v2, v3 |
| `B7-S08-admin-actions-user-list-v2` | delivered | Administrator actions and the owner-ruled user list, 15 rows (8 owner-ruled); v2 Amharic including H05 | v1 |
| `B7-S09-auth-emails-v2` | delivered | Three auth emails as full bodies, 7 rows; v2 added the 360 px client rendering | v1 |
| `B7-S10-branding-editor-shell-v3` | delivered | Editor shell, 7 rows; v2 preview mark, v3 the Amharic that had been entirely absent | v1, v2 |
| `B7-S11-branding-identity-v2` | delivered | Identity group, 6 rows; v2 added the dark form | v1 |
| `B7-S12-branding-colours-fonts-v1` | delivered | Colours and fonts, 7 rows; the contrast matrix and the no-override wall | — |
| `B7-S13-branding-login-v1` | delivered | Login group, 5 rows; element-footprint image sampling | — |
| `B7-S14-branding-emails-v2` | delivered | Emails group, 6 rows; v2 announced the render failure | v1 |
| `B7-S15-branding-links-legal-v1` | delivered | Links and legal, 4 rows | — |
| `B7-S16-branding-senders-v1` | delivered | Sender identities, 5 rows; no assertable verification | — |
| `B7-S17-branding-inventory-v1` | delivered | Surface inventory, 10 rows, eight tiles | — |
| `B7-S18-draft-preview-v2` | delivered | Draft preview mode, 6 rows; v2 announced the swap | v1 |
| `B7-S19-publish-dialog-v1` | delivered | Publish dialog and validators, 13 rows | — |
| `B7-S20-version-history-rollback-v1` | delivered | History and rollback, 5 rows | — |
| `B7-S21-branding-degraded-v2` | delivered | Degraded states, 4 rows; v2 put the dark tokens on B1-DS §2's dark column | v1 |
| `B7-S22-cross-cutting-v1` | ❌ 2 rows | Cross-cutting measurement over 21 files: 15 of 17 rows ✅, V09 and V17 open | — |
| `B7-A01-closing-audit-v1` | delivered | Batch 7 closing audit | — |
| `B7-X02-owner-rulings-index-v1` | delivered | 18 owner rulings in five groups | — |
| `B7-AC09-manifest.json` | delivered | 44 AC-09 baseline frames with language, theme, viewport and target PNG name | — |
| `B1-DS-v4-addendum-B7-v1` | FROZEN | B1-DS v4 — seven Batch 7 components, canonical forms measured from drawn instances | — |

**Closing audit.** Closing audit: B7-A01-closing-audit-v1 — 21 of 22 sections ✅, 1 ❌ (Section V, carrying the two open items). Fourteen corrections were found by measurement after a delivery note had already passed; the ledger is in §6.

## §3 — Design system lineage

`B1-DS` v1 → v2 → B5 addendum → v3 → v4. Five files on disk.

### `B1-DS-design-system-v1` — Batch 1 Step 1

The three directions and the chosen one. Superseded as system of record but on disk.

### `B1-DS-design-system-v2` — declared repair · additive only · 44px floor · lightbox addendum

The declared repair, and the file that still holds **§2 — fixed by the documents**: eight status pills, priority dots, the SLA chip, the reply-window chip, connection state, channel health rollup, note tokens and the AI accent — **each in both a light and a dark colourway**. Batch 7 twice mixed dark tokens by eye before this column was found; that is the batch's worst error and it is recorded in §6.

### `B1-DS-addendum-B5-v1` — Batch 5 · awaiting approval per its own chip

The lightbox addendum's parts plus Batch 5's chart and intervention components. Its header chip still reads “awaiting your approval” — see §7, unpronounced approvals.

### `B1-DS-v3-addendum-B6-v1` — additive only · 7 components

Seven Batch 6 promotions, additive only — nothing in v2 changed.

### `B1-DS-v4-addendum-B7-v1` — FROZEN 3 Aug · v3 retires as system of record

Seven Batch 7 promotions with canonical forms *measured from the drawn instances* rather than designed in the addendum. The reconciliation note names the one real disagreement: the strength meter had shipped at 30 px and 28 px in two places against a canonical **34 × 5**, corrected by the scope-limited reopen to `B7-S05-…-v2`. Frozen by owner sentence with the standard change note included.

### Fixed-token rules now standing

- Status pill hues, priority dots, SLA and reply-window chips, connection state, channel health, note tokens and the violet AI accent are outside the theme and are never re-mixed — a tenant re-skins identity, never meaning.
- **Status hues are reserved to their semantic components**; generic pass/fail indicators use neutral success and danger tokens. Adopted at Batch 7 Section S after three recurrences; folds into the design system at its next legitimate version bump rather than reopening v4 for prose.
- The 44 px mobile touch floor outranks a component's desktop metric, and every such deviation is flagged in the delivery note.
- **The 56 px stop button is a recorded, deliberate exceedance of the 44 px floor** — `B1-DS-v3-addendum-B6-v1` component 06: min-height 56 px, 10 px radius, 15/600, full width in a stack with a 9 px gap. Its contract requires the consequence named above the button and a same-size cancel beside a destructive action, and forbids it outside incident and stop-control surfaces. In use at `B6-S16`, the incident page; promoted on the owner’s ruling of 3 August 2026. Two earlier drafts of this report marked it UNKNOWN without searching — the correction is recorded here rather than silently applied.

## §4 — Owner rulings index, program-wide

*18 indexed in B7-X02 · 11+ more recorded elsewhere · 1 unfound*

The report asked me to extend B7-X02 backwards across all seven batches. **I can extend it only as far as the files record it, and that is the finding.** Eighteen rulings are captured in B7-X02 with their bindings; for Batches 1–6 the rulings exist as *consequences* visible in file chips and reopen notes, but their wording, date and authority live in those batches' chats, which I cannot read from here.

### Recorded in a delivery note or audit — 18 rulings, Batch 7

Five groups in B7-X02: scope and containers (R-01 the owner-ruled user list, R-02 tokens in scope, R-03 email bodies designed); viewports (R-04 desktop editing as a program rule, R-05 auth screens exempt, R-06 emails need a narrow client rendering); design-system law (R-07 fixed tokens never re-mixed, R-08 status hues reserved, R-09 both colourways already exist, R-10 tenant marks are fixtures); copy and language (R-11 typed name in its own script, R-12 meaning-parity beats word-parity, R-13 the block-versus-warn scope line, R-14 the Amharic review as a named blocker); process (R-15 per-gate sentences, R-16 every touched file bumped, R-17 notes written after checking and method changes apply backwards, R-18 improvements proposed never baked in).

### Recorded outside B7-X02 — the ruling exists in a file, but not in any index

Each of these has a file that carries the ruling or its outcome. Four rounds of checking moved most of this list from “chat-held” to “sourced”, and the corrections are left visible rather than smoothed over — a report about honesty should show its own:

- **Assignee refusal, tag autocomplete, KB hover preview** — outcome in `B1-S01-inbox-shell-v2`'s reopen chip.
- **Bulk-bar disabled reason visible and focusable** — `B1-S02-inbox-states-v2`, citing Doc 06 §8, §13 and Doc 07 §13. This is the law Batch 7 Section S had to distinguish itself from when it removed the typed field.
- **Views sheet, four labelled sections** — `B1-S03-views-search-notify-v2`, FR-08.
- **Search scope: one search, scope enforced invisibly** — `B2-S02-…-v2`'s chip records the toggle's removal under FR-23 and BR-03.
- **Scroll restoration on back** — `B2-S01-mobile-interactions-v2`, FR-12.
- **The seven Batch 2 rulings are enumerated with bindings** — `B2-S06-closing-audit-v2` §“What your rulings changed”: search scope (B2-S02-v2), views sheet (B1-S03-v2), assignee inline refusal (B1-S01-v2), tags with no rename or merge designed (B1-S01-v2), KB hover preview (B1-S01-v2), bulk bar (already correct), scroll restoration (B2-S01-v2). This is the durable-artefact format B7-X02 was later built in — it existed in Batch 2 and was not carried forward as a program-wide index.
- **Doc 02 T-07 header set supplied** — closed three Section A gaps per `B3-S11-closing-audit-v3`.
- **EC-10, EC-11, EC-12** — admitted rows A16, D13 and sharpened H03 per `B4-S09-closing-audit-v1`.
- **The MERGE token treatment** — outcome in `B4-S04-merge-v2`: “token shown beside the input in both layouts”. Batch 7 R-11 distinguishes it from a typed name.
- **The Batch 5 viewport reversal is fully reasoned on disk** — `B5-S00-checklist-v3` §“The viewport conflict — reversed in v3, and now a recorded deviation”: it quotes the Batch 5 prompt’s Step 2 against Doc 15 §9 and Doc 11 §9, states the superseded v2 ruling (“the documents outrank the prompt; no execution at 375 in any form”) and the v3 ruling in force (“every feature is available on a phone”), carries the deviation callout, and names the affected rows B18, C02, H15, J16, J21 plus new component C15.
- **The four Batch 5 OPTIONs are recorded as a set, with their refinements and bindings** — `B5-S11-closing-audit-v2`: all four accepted, three refined. The six-series cap discloses itself in the chart title (“Showing 6 of 9 queues”) so a truncated chart is never read as complete; Undo is an inverse audited operation through normal machinery, never an erasure, and is disabled with its reason where the inverse is not legal; the two-calendar sentence renders only when the window spans a recorded calendar change; paused-schedule reasoning ships as written. Applied at S01-v2 §A, S02-v2 §E, S05 §B and S08 §B.
- **The 113-permission count is recorded with its derivation** — `B6-A01-closing-audit-v1` renders “113 · 111 named + 2 registered additions”, and `v2` names them: `views.share` and `ai.providers.manage`, still to be named by the document owner. The matrix renders 113 throughout `B6-S08`. Settings-render-once is visible in `B6-S11-settings-shell-v2` and is what Batch 7’s exclusion boundary relies on.
- **Section M → Batch 7** — recorded in `B6-S00-checklist-v2`'s chip. Batch 6 in fact leaves at least three rulings legible in files — this one, the 113 derivation above, and Silence 2 in §9.
- **B09 dual placement** (two-codes-left warning at consumption and persistently on Security) and **the fixture-mark treatment** — both ruled 3 August; the fixture mark is in B7-X02 as R-10, B09's dual placement is applied in `B7-S02-…-v2` but is *not* in the rulings index. That is a gap in B7-X02 itself, named here rather than quietly fixed.

> **The finding, restated after checking — it was overstated.** A first draft of this section said the program “made roughly thirty owner rulings and recorded eighteen”. Measured, the durable record is materially better: **18 in B7-X02**, **7 enumerated with bindings in B2-S06**, **4 OPTION acceptances with refinements and bindings in B5-S11**, plus the individually recorded ones — Section M → Batch 7, Silence 2 (no deep-link builder), the 113 derivation, Doc 02 T-07, EC-10/11/12, the 56 px exceedance. **The real gap is not recording, it is indexing:** there is no single program-wide file, so a build engineer must know that Batch 2’s rulings live in its audit, Batch 5’s in its audit, and Batch 7’s in B7-X02. What remains genuinely chat-held is narrower still. The Batch 5 viewport reversal is *not* an example — `B5-S00-checklist-v3` has a section titled “The viewport conflict — reversed in v3, and now a recorded deviation” that quotes both conflicting passages, states the superseded v2 ruling and the v3 ruling in force, and carries the deviation callout. After checking every marker in this report, the one item I can still find no file for is **the wording of the Doc 03 interim ruling** on the 111-vs-118 discrepancy; the discrepancy itself is filed in the Batch 6 contract.

## §5 — Acceptance lists (the build's test obligations)

**Name collision, flagged once:** “AC-09” below is B12-A01’s ninth acceptance row (live-region announcements). The “AC-09 baseline pack” in §1, §7 and §8 is Doc 02’s acceptance criterion for pixel baselines. Different things, same string — the rows below are prefixed with their file.

Behaviours a still frame cannot prove. Each row states what must be true, how a builder demonstrates it, and the passage it answers to; in the audits they close as *handed to build acceptance*, never as ✅ designed.

| Row | Behaviour | Documents |
|---|---|---|
| `B12-A01 AC-01` | Focus system — visible focus in reading order, both themes; dialogs, sheets and ⌘K trap and return focus | Doc 07 §13 |
| `B12-A01 AC-02` | Landmarks and skip links — four regions announced; skip link jumps past the views rail | Doc 07 §13 |
| `B12-A01 AC-03` | Bidi / RTL-ready layout — regions mirror, bubbles swap, directional icons flip. Amharic does not exercise it | Doc 04 §14 · Doc 07 §14 |
| `B12-A01 AC-04` | List row enter / leave — animates within motion tokens, honours reduced motion, never moves under a pointer | Doc 07 FR-11, AC-03 |
| `B12-A01 AC-05` | Composer optimistic send — sending → sent → failed with reason; no duplicate on retry | Doc 07 FR-21 · Doc 04 FR-13 |
| `B12-A01 AC-06` | Composer attachment caps — per-channel size and type enforced before upload, refusal names the cap | Doc 07 FR-18 · Doc 04 §8, EC-02 |
| `B12-A01 AC-07` | Store recovery — corrupt local store rebuilds without losing queued sends, and says so | Doc 07 FS-05 |
| `B12-A01 AC-08` | Draft survives merge — moves to the surviving ticket with an info chip | Doc 07 EC-12 · Doc 04 AC-08 |
| `B12-A01 AC-09` | Live-region announcements — status changes, refusals, bulk progress at 25%, arrivals, offline flush | Doc 07 §13 · Doc 04 §13 |
| `AC-B3-01` | No onboarding layer on first open | Batch 3 · widget |
| `AC-B4-01` | RG flag reads are never cached | Batch 4 · Doc 08 |
| `AC-B4-02` | Flag history exports whole, with authors and reasons | Batch 4 · Doc 08 |
| `AC-B4-03` | The inline-edit announce cycle is spoken, not just marked | Batch 4 · Doc 08 §13 |
| `B12-A01 AC-09 baseline export` | Owner's word to fire it; the manifest is the contract and the format is ruled (PNGs plus per-section contact sheets). Held deliberately until the reopened frames were final — four of the 44 only came into existence in the last pass. | Designer |

### Batches 5, 6 and 7 have no acceptance file

There is no `B5-A01`, `B6-A01-behaviour-acceptance` or `B7-A01-behaviour-acceptance` on disk — `B6-A01` and `B7-A01` are closing audits, not acceptance lists. Batch 5's un-drawable obligations were absorbed into its closing audit's §A/§C/§D; Batch 6's cross-cutting rows are in its audit; Batch 7's are cross-cutting rows in S22. **Consequence for the build:** Batches 1–4 hand over thirteen numbered, demonstrable test rows; Batches 5–7 hand over prose inside audits. If the build wants parity, three acceptance files need writing — designer work, roughly one pass each.

## §6 — Deviations, interpretations, and the error ledger

### Deviations recorded in files

- **Desktop-only editing surfaces** — Batch 6's five editing screens and Batch 7's Sections J–U ship 375 read-only behind Doc 03 §9's notice. Filed in each file's delivery note and promoted to a program rule (R-04).
- **44 px floor over B1-DS desktop metrics** — every mobile variant raises the 36 px button and 38 px field. Filed per file, first in B7-S01.
- **No 375 in B7-S21** — each row is a state of a screen that ships at 375 elsewhere. Filed and ruled acceptable.
- **Three Batch 5 mobile deviations, filed to the interpretation record with the documents unamended** — Doc 15 §9 (tablet+ live dashboard), Doc 15 §9 (1024 px builder floor) and Doc 11 §9 (wizard execution), each overridden by the v3 mobile ruling. Recorded in `B5-S11-closing-audit-v2` and in each affected screen’s delivery note, so the artefact and the record agree.
- **B3-S08 apply-button 34 px vs B1-DS 36 px** — the one place the shared contrast validator's two consumers differ. Flagged in B7-S12's note; the frozen Batch 3 file was not touched. **Still unruled.**
- **The Doc 03 111-vs-118 discrepancy is on the record** — `B6-S00-checklist-v2` carries both figures six rows apart: “118 catalog keys across 14 modules” and “the 111 keys §4.1 literally names, across all 14 modules”. The discrepancy is therefore filed in the batch contract itself; only the interim ruling’s *wording* is chat-held.

### Error ledger

**B12-R01 — 79 corrections across 7 files.** Components matched by eye from approved screens instead of taken from B1-DS verbatim. This is the drift that produced the program's first rule.

**Batch 7 — 14 corrections, all found after a delivery note had passed:** fixed tokens mixed by eye ×4 (three status-hue leaks; S21's dark tokens invented, then "corrected" to light values, when B1-DS §2's dark column existed); counts written from expectation ×5 (5.2:1 that was 3.88:1; 74 characters that were 25; 61 that were 59; frame sums 34/27 that were 40/34; four ❌ rows that were three); behaviour asserted but never exercised ×3 (rail labels "wrapping" that fit one line, a header "wrapping" with 800 px of slack, a banner property called "forced" that was insurance); parity claimed but unchecked ×2 (M and N each shipped a constraint in one language while claiming both; S10 shipped a whole screen with no Amharic).

**Method changes produced:** R-17 — delivery notes written after a claim-by-claim check, *and applied backwards*; R-08 — status hues reserved to semantic components; R-09 — search the design system before proposing a token.

## §7 — Open items, everything not closed

| Item | What closes it | Whose |
|---|---|---|
| **Amharic destructive-tier clearance** | Four verdicts in one line — H05, 4b token revoke/rotation, H15's added clause, the ሀ–ፐ sort label — or a signed exception deferring review to one post-batch pass with the build's i18n freeze downstream. Batch 7's V09 and the audit's only ❌. | Owner |
| **AC-09 baseline export** | Owner's word to fire it; the manifest is the contract and the format is ruled (PNGs plus per-section contact sheets). Held deliberately until the reopened frames were final — four of the 44 only came into existence in the last pass. | Designer |
| **B3-S08 34 px vs B1-DS 36 px** | A ruling: either a two-pixel scope-limited reopen of the frozen Batch 3 file, or a deliberate widget-side metric recorded as such. The only place the shared contrast validator's two consumers differ. | Owner |
| **B1-DS-addendum-B5 approval** | Its header chip still reads “awaiting your approval”. Either an approval sentence or a note recording that it was approved elsewhere. | Owner |
| **Program-wide rulings index** | The Batch 1–6 chat records, so the roughly twelve rulings visible only as file chips can be quoted rather than inferred. Designer work once the source exists. | Owner → Designer |
| **Acceptance lists for Batches 5–7** | Three files in the shape of B12-A01, extracting the un-drawable obligations currently sitting as prose inside three audits. | Designer |
| **Per-batch screenshot exports** | Specified in every batch prompt: approved screens, both viewports and themes, into design/batch-N/ with version names. Owner's routine — only whether each run happened is unrecorded here. | Owner |
| **Batch 6 Q15 — N/E** | Stays not-exercised until a batch designs a surface that renders AI content. That is Doc 09's copilot, unscheduled. | Owner |
| **Docs 09, 10, 12, 14, 17 surfaces** | A batch 8+ scope decision. Nothing in the seven-batch program covers them and no contract exists. | Owner |
| **Bulk-bar disabled reason is a hover tooltip** | Recorded as an open designer question in B2-S06-closing-audit-v2: the reason is reachable by pointer but not by keyboard or screen reader, against Doc 06 §13. The designer offered to draw it as visible text and the word never came. One ruling, then one scope-limited reopen of B1-S02. | Owner |

## §8 — Handoff manifest

### Exists now

- **The design files themselves** — 165 on disk, every screen at both viewports and both themes except the deviations filed in §6.
- **B1-DS v2 + three addenda** as the token source, v4 frozen as system of record. §2 carries both colourways of every fixed token.
- **`B7-AC09-manifest.json`** — 44 frames: 31 en / 13 am, 27 light / 17 dark, 26 at 1440, 16 at 375, 2 at 360, each with its target PNG name.
- **Acceptance lists** — B12-A01 (9 rows), B3-A01 (1), B4-A01 (3).
- **Six closing audits** and **B7-X02**, the 18-ruling index.

### Awaits an action

- **AC-09 PNGs and contact sheets** — manifested, format ruled, not exported. Owner's word; designer's hands.
- **Per-batch screenshot exports** — the destination and the routine *are* specified: every batch prompt on disk carries the owner’s after-approval routine — export every approved screen at both viewports and both themes into the repo under `design/batch-N/` with their version names. Batch 7’s adds that the auth screens attach as Doc 02’s AC-09 baselines. It is the owner’s task; **UNKNOWN** is only whether each run happened.
- **A program-wide rulings index** — B7-X02 covers Batch 7. Extending it needs the Batch 1–6 chat records; see §4.
- **Acceptance lists for Batches 5–7** — see §5.

## §9 — Deliberate absences

Things the program chose not to build. Each is a designed absence with an authority behind it — several are stated on the surface where a person would go looking, because a refusal that is merely missing reads as an oversight.

### Stated-absence rows

- **No impersonation** — Doc 02 SEC-07. Stated on the admin surface (B7-S08 H06) where “sign in as” would live; read-only permissions are the support path.
- **No contrast override** — Doc 16 BR-02. Seven states checked for an escape hatch across B7-S12 and B7-S19, including the validator being unavailable.
- **No admin-set passwords or visible secrets** — Doc 02 BR-02. The admin gets a reset, an unlock and a 2FA clear, and the surface says why there is nothing else.
- **No account-existence disclosure** — Doc 02 FR-02, SEC-01, AC-01. One generic login error, one uniform reset response, and an IP-blocked attempt returns the same generic error rather than a distinct screen.
- **No assertable verification** — Doc 16 SEC-03. Sender status is read from DNS; there is no “mark as verified” anywhere, and the word never rhymes with a customer-identity badge (Doc 08 BR-01, AC-09).
- **No onboarding tour** — the widget opens with no onboarding layer (AC-B3-01).

SSO and SAML, WebAuthn and passkeys, step-up auth, adaptive and anomaly auth (Doc 02 §19); custom font uploads, per-department sub-branding, a theme marketplace, scheduled publishes, translation memory, per-tenant custom domains (Doc 16 §19). Two are visible as stated absences because a person would hunt for them: curated-fonts-only in B7-S12, and no scheduled publish in B7-S19's footer.

The audit view is Doc 17 and outside the seven-batch program — every batch states that a change is recorded and by whom, and none draws the trail. User-lifecycle work beyond the owner-ruled list is unbuilt. **Undesigned document surfaces:** Doc 09 (AI system, chatbot, copilot, supervisor), Doc 10 (knowledge base), Doc 12 (SLA), Doc 14 (SMS), Doc 17 (audit), Doc 18 (DNS and deliverability) beyond the status Batch 7 surfaces. No batch is scheduled for them.

One consequence worth carrying: Batch 6's **Q15 is N/E — not exercised**, because no Batch 6 surface renders AI content. It stays N/E until a batch designs a surface that does, which is Doc 09's copilot. The AI accent's disclosure rule is law in B1-DS today and has never been tested by the screen it exists for.

**The deep-link builder is recorded as deferred** — `B6-S00-checklist-v2`, Silence 2 closed: Doc 05 FR-26’s `src-{source}_ctx-{context}` payload schema stays a bot-side contract with no admin surface, exclusion accepted by the owner. **Tag rename/merge is recorded as not designed** — `B2-S06-closing-audit-v2`: “Tags — per-tenant autocomplete; no rename or merge designed. B1-S01-v2.”

### Excluded by the documents' own §19 future lists

SSO and SAML, WebAuthn and passkeys, step-up auth, adaptive and anomaly auth (Doc 02 §19); custom font uploads, per-department sub-branding, a theme marketplace, scheduled publishes, translation memory, per-tenant custom domains (Doc 16 §19). Two are visible as stated absences because a person would hunt for them: curated-fonts-only in B7-S12, and no scheduled publish in B7-S19's footer.

### Deferred surfaces and the Batches 8+ gap

The audit view is Doc 17 and outside the seven-batch program — every batch states that a change is recorded and by whom, and none draws the trail. User-lifecycle work beyond the owner-ruled list is unbuilt. **Undesigned document surfaces:** Doc 09 (AI system, chatbot, copilot, supervisor), Doc 10 (knowledge base), Doc 12 (SLA), Doc 14 (SMS), Doc 17 (audit), Doc 18 (DNS and deliverability) beyond the status Batch 7 surfaces. No batch is scheduled for them.

One consequence worth carrying: Batch 6's **Q15 is N/E — not exercised**, because no Batch 6 surface renders AI content. It stays N/E until a batch designs a surface that does, which is Doc 09's copilot. The AI accent's disclosure rule is law in B1-DS today and has never been tested by the screen it exists for.

**The deep-link builder is recorded as deferred** — `B6-S00-checklist-v2`, Silence 2 closed: Doc 05 FR-26’s `src-{source}_ctx-{context}` payload schema stays a bot-side contract with no admin surface, exclusion accepted by the owner. **Tag rename/merge is recorded as not designed** — `B2-S06-closing-audit-v2`: “Tags — per-tenant autocomplete; no rename or merge designed. B1-S01-v2.”

---

*Compiled 3 August 2026 from the files on disk. Corrections made during verification are recorded in place rather than removed.*
