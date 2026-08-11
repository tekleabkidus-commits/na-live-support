# SESSION-07 / DOC 07 — APPROVED PLAN (committed at approval per governance #9 / standing rule 12)

**Approved by the owner on the advisor's audit, 2026-08-10: 101/101 family-exact against the
advisor's independent enumeration.** Corrections C1–C4 and rulings A-30/A-31/A-32 (filed in
`docs/ANSWERS-FROM-DOC-AUTHOR.md`), `inbox.away.idle_minutes` editability, and the R-15 stack
growth were bound at approval and are incorporated below. STEP 0 evidence: rulings block at
`docs/ANSWERS-FROM-DOC-AUTHOR.md:1107`, filing commit `008876d`, advisor confirmation in
writing before this plan existed. Solo-process proof accepted (PID 39080, raw ps pasted).

## Corrections bound at approval (advisor, binding)

- **C1** — `ticket_list_items.snoozed_until` is DROPPED from the migration: no Doc 07 text
  demands the column (FR-31's "S" is snooze-TO-PENDING, a Doc 06 status action; the snooze
  time picker is §19 future). No invented schema. The pinned-provenance copies are exactly
  `last_activity_at` and `window_expires_at`.
- **C2** — the `events.occurred_at` R-19 treatment is the FILED DEFERRAL of BUILD-STATUS
  row 9 discharging, not a new core-edit decision — but it edits Doc 01's relay path, so it
  follows the S23 ruled-edit pattern: the relay-side change is enumerated in a committed
  diff assertion citing row 9; sabotage #16 runs at the MIGRATION level; and the step-1
  commit's full battery explicitly includes the Doc 01–06 suites as the regression proof
  for every event write.
- **C3** — Doc 03 EC-02: at close-out, reconcile against the row's CURRENT BUILD-STATUS
  text (the transition half was recorded CLOSED at S22 step 2; the S19 audit row carries
  the UI-state half). Update whichever half the record still shows open — on executed
  evidence only, no double-closing.
- **C4** — the PROJECT-HANDOFF-v6 discrepancy is RULED: the committed session prompt
  (`90ec851`, advisor-authored and advisor-confirmed) is the binding restatement of the
  inherited rows; the owner committed the four v6 kit files at `8e3c0b4` (governance #2's
  third recurrence, caught by this session's reading check).

## Repo-vs-prompt discrepancies recorded at plan time

1. PROJECT-HANDOFF-v6 — resolved by C4 (`8e3c0b4`).
2. The `events` ordering column is `occurred_at`, not `created_at` (migration
   `2026_07_30_001100`); the deferral treatment below uses the real name.
3. `views.share` was not yet registered (111 keys verified) — it is the addition-of-record
   the registry header names; it registers at step 1 with this document landing.
4. No `ComposerState` DTO exists — Doc 05 shipped the §8 contract as data doors
   (connection state machine, `reply_window_expires_at`, `DeliveryFailureReason` incl.
   `blocked_by_user`/`connection_paused`/`window_expired`, all verified in the repo).
   Doc 07 builds the read-side resolver; no channel semantics re-implemented.
5. Frame conventions: B1/B12 files carry captioned frame groups; B2 files carry lettered
   ids (A10–A16, B9–B14, C7–C16, D1–D20). The frame map enumerates what the frozen files
   actually contain (extracted mechanically). B1-S02's own header covers combined-audit
   rows 101–168.

**Scoping verification (mandate §4.2):** the twelve screen files + four audit/acceptance
files are the Batch 1+2+12 Doc 07 stack at highest frozen versions. Customer-tab internals
are Batch 4 (Doc 08's build); Copilot internals are drawn nowhere. Both tabs are SLOTS:
fixed order (Ticket, Customer, Copilot), badge-dot seam fixtures, zero panel logic. The
Ticket tab is this session's (Doc 06 property contract through Doc 06 doors). The seven
B2-S06 rulings bind: search scope invisible; four-section views sheet; assignee inline
refusal; tags-no-rename/merge (R-22's class-2 fill, this session's); KB hover preview;
visible+focusable bulk-bar reason (frozen B1-S02-v2 governs; the program report's §7 note
is stale); scroll restoration per SEC-03.

## Id count, by family (advisor-matched 101/101)

FR 31 · NFR 7 · US 7 · BR 6 · T 8 · SEC 6 · EC 12 · FS 5 · SS 4 · AC 10 · R 5 = **101**.

## Canonical suites command (standing rule 14 — mirrored in BUILD-STATUS)

```
./vendor/bin/pest tests/Feature/Inbox \
                  tests/Contract/InboxEventConsumerContractsTest.php \
                  tests/Isolation/InboxGrantPhysicsTest.php \
                  tests/Security/InboxSearchScopeTest.php \
                  tests/Security/InboxSecurityTest.php
```

Performance suites run under `make perf` / the CI stage; browser proofs under
`make browser` (the Doc 01–06 precedent). Any new Doc 07 file at another tier joins this
command in the same commit that creates it. Figures cited before a named file exists come
from the extant paths, stated as such (the S23 record-note precedent).

## Event-family enumeration (standing rule 16)

**One (1) new catalog event:** `agent.status_changed` v1 (FR-28) —
`{tenant_id, user_id, status(online|away|offline), reason(manual|auto_idle|heartbeat_lost|forced|logout), set_by_user_id}`;
exact schema fixed at the step-1 commit; Doc 11 seam fixture ships with it. Catalog
83 → 84.

**Distinction stated (the Doc 04 RealtimePusher precedent):** T-02's Reverb view-delta
payloads (`{op,id,sort_key,patch}`, `v:1`) and T-03's count pushes are realtime protocol,
NOT catalog events — ephemeral, never through the outbox. Everything durable consumes
existing catalog events. Any in-flight addition is a FILED amendment citing its document
requirement before it ships. FR-26's bell rows are per-user notification ROWS built from
existing events — no new event.

## Registries

- **Settings:** `inbox.away.idle_minutes` — int, default 10, validation 1..240,
  `tenant_editable: true` (APPROVED at plan approval; operator policy per BR-06/§21; the
  editing surface arrives with Doc 16). Nothing else: FR-11 timings are product
  commitments (R-02); density/region-sizes/sounds/mute are per-user preferences (BR-05);
  the mobile bulk cap 25 is platform-fixed.
- **Notification categories (step 1, before any producer):** `notify.inbox.assignment`,
  `notify.inbox.mention`, `notify.inbox.follower`, `notify.inbox.escalation`,
  `notify.inbox.sla_warning` (rows render from Doc 12 fixtures; the category closes the
  bell's type set), `notify.inbox.window_closing` (consumes `telegram.window.closing`,
  Doc 05 FR-22 opt-in), `notify.inbox.bulk_completed`. All `layer_model: user`,
  `default_channels: [in_app]` (BR-05).
- **Permissions:** `views.share` registers (the addition of record; FR-06). Route→key
  same-commit from the closed catalog — all verified present: `tickets.view`,
  `tickets.reply`, `tickets.note`, `tickets.status.change`, `tickets.close`,
  `tickets.assign`, `tickets.assign_self`, `tickets.follow`, `tickets.merge`,
  `tickets.tags.manage`, `tickets.export`, `conversations.view`,
  `conversations.history.full`, `supervision.agent_status.force`. No key invented.
- **Features:** `inbox.voice_recording` (FR-18 "behind flag") — default false,
  owning_doc Doc07; the flag hides rendering only (N-19).

## New tables — R-19b per-column analysis (inherited row 8) + ZSET physics

| Table | Full R-19b treatment (clock_timestamp() default + INSERT&UPDATE column-revoked + schema-derived guard, FIRST migration) | Analyzed NOT ordering/metric-bearing, why |
|---|---|---|
| `ticket_list_items` | `created_at` (row birth). **Pinned-provenance copies (app-writable, documented, never fresh clock reads): `last_activity_at`, `window_expires_at`** — the projector copies canonical DB-time stamps from source rows/events (the Doc 05 `last_inbound_at` class). C1: `snoozed_until` DROPPED | `updated_at` (projection maintenance metadata; the sort key is the ZSET score derived from the pinned copies); denormalized display fields are data |
| `inbox_saved_views` | none beyond hygiene default on `created_at` | view order is the user-arranged `position` integer; `updated_at` is per-user config LWW |
| `inbox_selection_tokens` | `created_at` full treatment (expiry comparisons in SQL, C-11) | `consumed_at` — single-use latch written by the consuming statement's own clock_timestamp() |
| `inbox_notifications` | `created_at` FULL treatment — the bell list orders by it | `read_at` — a latch |
| `inbox_user_prefs` | none | pure per-user preference state; no ordering, no metrics (BR-06) |
| `agent_statuses` | `since` — statement-level clock_timestamp() in the UPDATE (displayed with attribution; NOT metric-bearing, BR-06: active-time derives from activity events) | `status`, `set_by_user_id` |

**ZSET physics (R-23):** every view ZSET score is INTEGER MICROSECONDS derived from the
pinned DB-time sort field; residual same-microsecond tiebreak = lexicographic member
(ticket UUID), documented and total. Keys `t:{tenant}:view:{viewHash}` on redis-volatile,
invalidation documented per C-07, FS-02 rebuild is the loss answer — cache is never the
source of truth.

**Inherited row 7 — THE DEFERRAL FIRES.** T-07's `/v1/sync?cursor` orders over `events`
(`(occurred_at, id)` cursor, C-05). Treatment lands at step 1, BEFORE the endpoint
(step 5): `ALTER TABLE events ALTER occurred_at SET DEFAULT clock_timestamp()` + the
R-19a column-list grant (app INSERT-denied on `occurred_at`; the relay stops supplying
it — append order becomes the stream order) + the schema-derived guard extended to
`events`, sabotaged at the migration level. Per C2: the relay-side edit is enumerated in
a committed diff assertion citing BUILD-STATUS row 9, and the step-1 battery includes the
Doc 01–06 suites as the regression proof for every event write.

## Architecture decisions (ruled at approval)

- **A-30 (COUNTERSIGNED)** — EC-04 per-viewer counts via per-scope ZSET variants: the
  view-hash embeds the scope signature (SEC-01); variants materialize LAZILY on first
  subscribe; eviction/volatility documented per C-07; FS-02 rebuild covers variant loss;
  the EC-04 fixture proves two scopes → two counts with zero out-of-scope rows.
- **Meilisearch tenancy (T-04/SEC-02):** per-tenant physical indexes
  (`t{tenant}_tickets`/`t{tenant}_messages`) + server-injected Doc 03 scope filters — two
  independent layers, the N-01 shape; the proxy is the only path; the client never sends
  scope. First real Meilisearch consumer.
- **A-31 (COUNTERSIGNED)** — sync scope filtering joins through `ticket_list_items` so
  reconnect delivery equals list/thread visibility; the reconnect golden asserts a
  scope-excluded event never reaches the narrower client.
- **A-32 (RULED)** — a mention is unresolved from `note.mentioned` until the mentioned
  agent next opens that ticket's thread or dismisses the bell row; membership = tickets
  with unresolved mentions. Trade-off named at ruling; revisit on dogfood feedback.
- **ComposerStateResolver:** read-side mapping of Doc 05/04 door data →
  `normal | closing_countdown | expired_options | connection_paused | blocked_by_user |
  conversation_blocked`; zero window/connection semantics re-implemented; EC-06
  re-evaluates on live transitions.

## Stack growth (R-15 — APPROVED)

`@tanstack/vue-virtual` (NFR-03 virtualization), `pinia` (T-06 normalized store +
command objects), `@lhci/cli` (dev/CI only, T-08 Lighthouse budgets). Declined and
accepted as stated: in-house fuzzy scorer, native BroadcastChannel/IndexedDB, existing
Playwright + scout/meilisearch-php.

## Steps (one commit per numbered step; safety tests BEFORE the features they constrain)

| Step | Contents |
|---|---|
| 1 | Schema + registries: the six tables (above) with R-19b physics + `InboxGrantPhysicsTest`; the `events.occurred_at` R-19 treatment per C2 (deferral row 7, diff assertion citing row 9, Doc 01–06 suites in the battery); `views.share` + settings + notification categories + `inbox.voice_recording`; `agent.status_changed` catalogued; `lang/{en,am}/inbox.php` skeleton; traceability flip; sabotages #11, #16 |
| 2 | Read models: `ListProjector` durable consumer (ConsumerRegistry); C-09 idempotency + kill/re-drive drill FIRST; T-03 replay-counting proof BEFORE any count is trusted; `ticket_list_items` hydration; view ZSETs (R-23); scoped-list proof + sabotage #1 BEFORE any list serves data; NFR-07 checker; `fixtures/tickets/consumers/doc07.json` tightened in this first consumer commit; sabotage #9 |
| 3 | Realtime: T-02 view-delta channels v:1 + SEC-01 subscribe re-auth + scope-change resubscribe; T-03 count pusher (debounce 2 s, em-dash contract); `channel.health.changed` v2 FIRST consumer (registry entry, C-09, catch-up drill, first consumer-contract fixture); sabotages #12, #13 |
| 4 | Views: seven system-view definitions + membership fixtures == direct queries (AC-02, A-32); queue-view seam (Doc 11 fixture); saved views CRUD + `views.share` + duplicate-to-edit; EC-04 variants proven (A-30); view ordering; FS-01 projector-down drill + FS-02 ZSET-loss rebuild (killing the real thing); sabotage #18 |
| 5 | Sync + skew: T-07 `/v1/sync?cursor` (bounded 5k + overflow instruction, A-31); NFR-06 reconnect goldens; EC-07 server-now offset; EC-08 jitter + 429-on-connect; FR-30 build-id; sabotage #10 |
| 6 | Search: index definitions + event-driven indexer + search proxy (SEC-02 only path); scope-injection fixtures (AC-09) + sabotage #2 BEFORE search serves data; FS-03; recent searches; T-04 budget structure |
| 7 | Selection/bulk seam: T-05 tokens — cap + SEC-06 single-use/user-bound proofs BEFORE bulk wiring (sabotages #3, #4); execution-time resolution + drift report; EC-05 chunking through Doc 06; EC-02 tray contract |
| 8 | Tokens-first + shell: hex-extraction re-run FIRST (committed script); B1-S01 shell (FR-01/02/03, AC-01), health dot/banner (Doc 05 FS-01 closing row, R-25), slots + badge-dot fixtures, §13 landmarks (B12-A01 AC-02), density |
| 9 | List UI: FR-09 anatomy (WindowChip consumed; EC-04 badge — Doc 05 closing row; EC-11 snippet — Doc 06 closing row; Doc 09/12 fixture slots); FR-10; FR-11 (AC-03, B12-A01 AC-04, sabotage #6); FR-12 + scroll restoration; FR-13 + EC-02 tray; EC-09 |
| 10 | Thread UI: AC-07 no-delivered-DOM FIRST (sabotage #7 = the Doc 05 AC-07 closing proof); FR-14 (AC-08 card — Doc 06 closing row; US-03 owner-phone — Doc 05 closing row); FR-15 + EC-01 (sabotage #15) + Doc 04 EC-08 anchor; EC-10; B12-S01 + B12-S02 frames; §9 print (SEC-05, sabotage #20) |
| 11 | Composer: FR-17 mode safety (BR-02, AC-05 + Doc 04 AC-04 integration, sabotage #14); FR-20 resolver — ALL Doc 05 §8 states (closing rows) + EC-06 + EC-08-prompt (Doc 06 closing row); FR-21 split button (B12-A01 AC-05); FR-18 caps (B12-A01 AC-06) + voice flag; FR-19 palette (B12-S03) + AI slot; FR-22 drafts + EC-12 (B12-A01 AC-08); FR-16 collision (AC-06, sabotage #8) |
| 12 | Views/search/notify UI + status: B1-S03 rail/sheet/editor; ⌘K (FR-23–25, B2-S02, BR-03); tab-leader proof BEFORE sounds (sabotage #5); FR-26 bell + mute + FS-04; SEC-04 (sabotage #19); FR-28 status + force + event + Doc 11 seam; Rescue view (Doc 05 US-04 closing row) |
| 13 | Offline client + shortcuts + mobile: FR-29 + T-06 command objects (BR-01/NFR-06); FS-05 store recovery (B12-A01 AC-07, sabotage #17); AC-07 golden; FR-31 complete map (AC-08, US-01, SS-01); B2-S01 mobile; coach marks |
| 14 | Ticket tab + operations + R-22 fill: B2-S03 + B2-S05 D1–D12 through Doc 06 doors; B1-S04/B2-S04 operation surfaces incl. merge banners/dialogs/preview + merge-preview read (Doc 06 closing row); the R-22 tag rename/merge UI (class-2, filed); SEC-05 export surface mounts (Doc 06 closing row); audit-coverage entries |
| 15 | §12 structure + sweeps + close-out: `InboxBudgetsTest` + T-08 CI budgets + soak structure (L2 → Doc 19, never faked); §13 sweep (B12-A01 AC-01/02/09); §14 sweep (B12-A01 AC-03 forced-RTL, ICU, locale digits, 🎉 token, R-24); R-14 flagging; 101-id self-audit; ledger reconciliation; closing-set BUILD-STATUS updates; DESIGN-DEVIATIONS; §9 report; CI once |

Per step: targeted tests while iterating; canonical command + `make gates` + `make lint`
once per step-commit, output pasted; browser under `make browser`; captures unfiltered to
`docs/evidence/S07-step{N}/` FIRST; CI checked ONCE (rule 15); evidence to the advisor as
files or plain text (rule 17).

## THE PLAN TABLE — every Doc 07 id → file(s) → proving test

Backend `app/Domain/Inbox/`, controllers `app/Http/Controllers/Inbox/`, consumers
`app/Jobs/Inbox/`, UI `resources/js/Pages/Inbox/` + `resources/js/Components/Inbox/`,
stores `resources/js/stores/inbox/`; browser specs `tests/Browser/inbox-*.spec.ts`.

| Id | File(s) | Proving test | Step |
|---|---|---|---|
| FR-01 | `Pages/Inbox/Workspace.vue`, `Components/Inbox/Shell/{ViewsRail,ListPane,ThreadPane,ContextPanel}.vue` | `inbox-layout.spec.ts` — four regions, sizes persist/restore (AC-01) | 8 |
| FR-02 | shell breakpoints + `MobileNav.vue`, `BottomSheet.vue` | `inbox-layout.spec.ts` all four breakpoints | 8 |
| FR-03 | `TopBar.vue`, `HealthIndicator.vue` | `inbox-topbar.spec.ts` + `HealthConsumerTest.php` | 3,8 |
| FR-04 | `lib/tabLeader.ts`, store convergence | dual-tab browser: leader plays once; sabotage #5 | 12,13 |
| FR-05 | `Views/SystemViews.php`, `QueueViewSource` seam | `SystemViewMembershipTest.php` == direct queries (AC-02; A-32) | 4 |
| FR-06 | `SavedViews.php`, `SavedViewController` + `views.share` | `SavedViewsTest.php` CRUD/share/read-only/duplicate | 4 |
| FR-07 | `CountPusher.php` | `CountingContractTest.php` (999+ cap, em-dash); sabotage #13 | 3 |
| FR-08 | view-order in `inbox_user_prefs`; rail drag + keyboard | `SavedViewsTest` + `inbox-views.spec.ts` | 4,12 |
| FR-09 | `List/Row.vue` (WindowChip consumed; fixture slots) | `inbox-list.spec.ts` anatomy incl. EC-04 badge, EC-11 snippet | 9 |
| FR-10 | `SystemViews.php` sorts + per-view override | `SystemViewMembershipTest` sort pins (BR-04) | 4 |
| FR-11 | `stores/inbox/liveList.ts` (5 s batcher) | `inbox-live-list.spec.ts` (AC-03, B12-A01 AC-04); sabotage #6 | 9 |
| FR-12 | cursor endpoint (C-05) + scroll restoration | `ListEndpointTest.php` + `inbox-scroll-restore.spec.ts` | 5,9 |
| FR-13 | selection store + `BulkBar.vue` + T-05 | `SelectionTokensTest.php` + `inbox-selection.spec.ts` | 7,9 |
| FR-14 | `Thread/{Bubble,DateSeparator,BoundaryMarker,HandoffCard,SystemEventGroup}.vue` | `inbox-thread.spec.ts` on Doc 04/05/06 fixtures | 10 |
| FR-15 | deep-link routes + anchors | `DeepLinkTest.php` (EC-01 404-pattern) + thread spec | 10 |
| FR-16 | `CollisionStrip.vue`, send-race confirm | `inbox-collision.spec.ts` dual-session (AC-06); sabotage #8 | 11 |
| FR-17 | `Composer/ModeSwitch.vue` | `inbox-composer-modes.spec.ts` + AC-05 heuristic | 11 |
| FR-18 | `Composer/{RichLite,Attachments,Emoji,VoiceFlag}.vue` | `inbox-composer-content.spec.ts` (B12-A01 AC-06) | 11 |
| FR-19 | `Composer/SlashPalette.vue`, `AiActionsSlot.vue` | `inbox-macro-palette.spec.ts` (Doc 12/09 fixtures) | 11 |
| FR-20 | `Composer/ComposerStateResolver.php` + `StateCards.vue` | `ComposerStateResolverTest.php` + `inbox-composer-states.spec.ts` (AC-04) | 11 |
| FR-21 | `SendFlow.ts`, `SplitButton.vue` → Doc 06 doors | `SendAndSetStatusTest.php` + B12-A01 AC-05 browser | 11 |
| FR-22 | drafts (Doc 04 FR-20) + `DraftChip.vue` + >20-char guard | `inbox-drafts.spec.ts` (EC-12 = B12-A01 AC-08) | 11 |
| FR-23 | `CommandPalette.vue` + `SearchProxyController` | `inbox-palette.spec.ts` + `InboxSearchScopeTest.php` (AC-09) | 6,12 |
| FR-24 | `FilterBar.vue` | `inbox-filter-bar.spec.ts` (compose-within, clear-all) | 12 |
| FR-25 | search deep-link anchor + highlight | `inbox-search-anchor.spec.ts` | 10,12 |
| FR-26 | `inbox_notifications` + fanout consumer + `Bell.vue` + quick mute | `NotificationBellTest.php` + `inbox-bell.spec.ts` | 12 |
| FR-27 | `lib/sounds.ts` | `inbox-sounds.spec.ts` dual-tab, OS-focus respect | 12 |
| FR-28 | `Status/AgentStatus.php`, heartbeat, `StatusControl.vue`, force route | `AgentStatusTest.php` + Doc 11 seam fixture | 12 |
| FR-29 | `lib/offline.ts` | `inbox-offline.spec.ts` 2-min golden (AC-07) | 13 |
| FR-30 | build-id in payloads + toast | `VersionSkewTest.php` + never-forced assert | 5,13 |
| FR-31 | `lib/shortcuts.ts` + `ShortcutOverlay.vue` | `inbox-shortcuts.spec.ts` — EVERY binding e2e (AC-08) | 13 |
| NFR-01 | route code-split, skeletons | Lighthouse CI budget (T-08); L2 → bucket | 15 |
| NFR-02 | (Doc 04 AC-07 shared measurement) | `InboxBudgetsTest.php` indicative; L2 → bucket | 15 |
| NFR-03 | `@tanstack/vue-virtual` list + thread | virtualization budget test, dev-indicative | 9,15 |
| NFR-04 | vite manualChunks + lazy tabs | CI bundle budget ≤350 KB gz, blocking | 15 |
| NFR-05 | leak-budget harness | `inbox-soak.spec.ts` structure; 8 h → Doc 19 | 15 |
| NFR-06 | T-06 command objects | `SyncGoldenTest.php` + rollback browser asserts | 5,13 |
| NFR-07 | `inbox:consistency-check` + drift alert | `ConsistencyCheckerTest.php` + seeded-drift control; replay proof | 2,4 |
| US-01 | keyboard-only core loop | `inbox-keyboard-shift.spec.ts` zero-pointer | 13 |
| US-02 | FR-11 + new-work marking | AC-03 suite + SS-02 burst script | 9,15 |
| US-03 | composer state cards | composer-states spec | 11 |
| US-04 | mobile two-level triage | `inbox-mobile.spec.ts` | 13 |
| US-05 | shared view live round-trip | `SavedViewsTest` + dual-session browser | 4,12 |
| US-06 | offline journey | AC-07 golden | 13 |
| US-07 | collision + race visibility | AC-06 suite | 11 |
| BR-01 | commands, no silent divergence | rollback goldens + FS-01 freeze-correct | 2,5,13 |
| BR-02 | non-themeable contrast delta | `ModeContrastFloorTest` (+hostile-theme fixture) | 11 |
| BR-03 | scope invisibility | scoped-list + scoped-search + EC-04 fixtures | 2,4,6 |
| BR-04 | Unassigned created-asc | sort pin | 4 |
| BR-05 | per-user noise choices | `NotificationBellTest` defaults | 12 |
| BR-06 | away generous + cancelable; metrics from activity events only | `AgentStatusTest` + metric-absence assert | 12 |
| T-01 | `ListProjector.php`, ZSETs, hydration | replay-counting + C-09 + kill/re-drive | 2 |
| T-02 | view-delta protocol v:1 | `ViewDeltaProtocolTest.php` | 3 |
| T-03 | `CountPusher.php` | `CountingContractTest.php`; sabotage #13 | 3 |
| T-04 | `Search/{IndexDefinitions,Indexer,SearchProxy}.php` | `InboxSearchScopeTest.php` + FS-03 | 6 |
| T-05 | `SelectionTokens.php` | `SelectionTokensTest.php`; sabotages #3/#4 | 7 |
| T-06 | `stores/inbox/*` + command objects | store units + rollback goldens | 13 |
| T-07 | `SyncController`, `(occurred_at,id)` cursor | `SyncEndpointTest.php` + golden; sabotage #10 | 5 |
| T-08 | `@lhci/cli` stage + soak harness | CI budget run + soak structure | 15 |
| SEC-01 | scope-embedded hashes + re-auth + resubscribe | `ViewChannelAuthTest.php`; sabotage #12 | 3 |
| SEC-02 | proxy sole path | `InboxSearchScopeTest` + no-client-key assert; sabotage #2 | 6 |
| SEC-03 | per-tab memory; drafts IndexedDB body-only, cleared | `inbox-storage-hygiene.spec.ts` | 13 |
| SEC-04 | notification = name + number only | `NotificationContentTest`; sabotage #19 | 12 |
| SEC-05 | print note gate + watermark | `PrintPathTest.php`; sabotage #20 | 10 |
| SEC-06 | tokens user-bound single-use | `SelectionTokensTest`; sabotage #4 | 7 |
| EC-01 | out-of-scope deep link → 404-pattern | `DeepLinkTest.php` (C-10 metric); sabotage #15 | 10 |
| EC-02 | selection-by-id + tray | `inbox-selection.spec.ts` | 7,9 |
| EC-03 | dual-tab drafts LWW + toast; typing never self-triggers | dual-tab suite | 13 |
| EC-04 | per-viewer counts (A-30) | `PerViewerCountsTest.php` | 4 |
| EC-05 | 1k intent → chunked + drawer | `BulkChunkingTest.php` | 7 |
| EC-06 | live patch + composer re-evaluation | resolver test + browser | 11 |
| EC-07 | server-now offset | handshake test + client unit | 5,13 |
| EC-08 | jitter + 429-on-connect | `ReconnectStormTest.php` + backoff unit | 5,13 |
| EC-09 | queue deleted → toast + fallback | scripted removal (Doc 11 fixture) | 9,12 |
| EC-10 | 16 KB clamp + anchors resolve | long-message fixture | 10 |
| EC-11 | media-only snippet placeholder | `ListProjectorTest` snippet cases en+am | 2,9 |
| EC-12 | draft survives merge + chip | `inbox-drafts.spec.ts` (B12-A01 AC-08) | 11 |
| FS-01 | projector down → freeze + pill + recovery | `ProjectorOutageDrillTest.php` (real kill); sabotage #18 | 4 |
| FS-02 | ZSET loss → prioritized rebuild + fallback | `ViewRebuildDrillTest.php` (real FLUSH; covers A-30 variants) | 4 |
| FS-03 | Meilisearch down → inline unavailable | `SearchOutageTest.php` (dead port) | 6 |
| FS-04 | permission denied → downgrade + one-time hint | bell spec | 12 |
| FS-05 | store corruption → reset, composer text above all | `inbox-store-recovery.spec.ts` (B12-A01 AC-07); sabotage #17 | 13 |
| SS-01 | 60-ticket keyboard shift | scripted shift, zero pointer | 13 |
| SS-02 | burst stability | scripted burst dev-scale; L2 → Doc 19 | 15 |
| SS-03 | 90 s drop: in-order, zero dupes, zero lost drafts | AC-07 golden extended; 40-agent scale → Doc 19 | 13 |
| SS-04 | week-long zero-drift | NFR-07 checker shipped + scheduled; structure by seeded control | 4 |
| AC-01 | layout matrix | `inbox-layout.spec.ts` | 8 |
| AC-02 | views fixtures + round-trips + EC-04 | membership + saved-views + counts tests | 4 |
| AC-03 | scripted deltas → FR-11 exact | `inbox-live-list.spec.ts` visual asserts | 9 |
| AC-04 | all composer states + split-button paths | state suite + `SendAndSetStatusTest` | 11 |
| AC-05 | wrong-mode ×50 + zero note payloads | heuristic + Doc 04 AC-04 integration | 11 |
| AC-06 | collision dual-session | `inbox-collision.spec.ts` | 11 |
| AC-07 | offline golden | `inbox-offline.spec.ts` + `SyncGoldenTest` | 13 |
| AC-08 | bindings e2e + overlay parity + no conflicts | `inbox-shortcuts.spec.ts` | 13 |
| AC-09 | scope-injection + anchored highlights | `InboxSearchScopeTest` + anchor spec | 6,10 |
| AC-10 | CI budgets + soak structure + SLO | `InboxBudgetsTest` + stages; L2 halves → Doc 19, stated | 15 |
| R-01 | checker/rebuild/fallback day one; system views first | steps 2–4 ordering + proofs | 2–4 |
| R-02 | batching a commitment; telemetry | constants pinned by test; counter emitted | 9 |
| R-03 | route budgets + lazy tabs | NFR-04 gate + lazy assert | 15 |
| R-04 | leader + idempotent sends + EC-03; dual-tab soak | dual-tab suite + soak run | 12,13,15 |
| R-05 | two-level contract is scope | B2-S01/S02 coverage; native shell stays §19 | 13 |

## Inherited rows 0–12 — dispositions (as approved)

0. Discharged pre-plan; filing commit `008876d`; advisor confirmation in writing. R-22 fill = step 14, filed class-2, frame-mapped.
1. Doc 05 closing set, one row each → §8 composer rendering (s11: FR-23/AC-04/US-02 halves); Rescue view (s12: US-04/FR-10); FS-01 banner (s3/s8); EC-04 badge+grouping+warning (s9, +R-04 half); US-03 owner-phone render (s10); AC-07 DOM half (s10 = sabotage #7).
2. Doc 06 closing set → T-08 (s2); SEC-05 surface (s14); EC-08 dialog (s11); EC-11 snippet (s9); AC-08 card (s10); merge surfaces + preview read (s14). Also stated: Doc 04's Doc-07-bucketed PARTIALs — SEC-01 inert-render (s10), EC-08 anchor UI (s10), US-03/AC-08 cross-device journey (s13) — close on executed evidence; FR-17's mention consumer surfaces in the bell (s12).
3. B12-A01 nine rows: ALL NINE executed-here — AC-01 §13 (s15), AC-02 §13 (s8/15), AC-03 §14 forced-RTL (s15), AC-04 FR-11 (s9), AC-05 FR-21 (s11), AC-06 FR-18 (s11), AC-07 FS-05 (s13), AC-08 EC-12 (s11), AC-09 §13 (s9/11/15).
4. Shared components CONSUMED: WindowChip (row chip + composer countdown), ConnectionStatePill (paused/revoked cards + rescue), HealthRollupPill (top bar + rescue), StepChecklist (not consumed by any Doc 07 surface — stated), Doc 04 glyph/tombstone/edit-marker patterns (thread). Second implementation = violation + escalation.
5. health-v2 first consumer at s3: registry entry + C-09 + catch-up drill + the event's first consumer-contract fixture; transition-only consumed, never re-derived.
6. Projectors durable via registry (s2); replay-counting proof before counts trusted; doc07.json tightened in the first consumer commit.
7. Deferral FIRES (real column `events.occurred_at`); treatment at s1 before the s5 endpoint; C2's regression battery binds.
8. R-19b per-column table above (C1 applied); R-23 integer-µs scores.
9. Route→permission same-commit; `views.share` registers s1; all named keys verified present.
10. Standing PARTIALs untouched except the closing sets; [OA] rows stay blocked (separate addendum session if unblocked, rotation first); Doc 19/08/12/14/15 + Doc 03 SS-01/SS-02 stay; Doc 03 EC-02 per C3 — reconcile against the CURRENT record at close-out, executed evidence only.
11. Intermittents: 0a stays isolated; 0d re-run once + record; 0f ladder first rung only if it fires; two single-sightings become lettered risks on recurrence.
12. Design-evidence honesty: NO Batch 1/2 pixel baselines; traceability claims functional-state + a11y only; restated at close-out.

## FRAME MAP (mechanically extracted from the frozen files)

**B1-S01-inbox-shell-v2** (s8/9): Desktop 1440 Light | Desktop 1440 Dark | Mobile 375 two-level → `Workspace.vue`/`MobileNav` → `inbox-layout.spec.ts`/`inbox-mobile.spec.ts`; "Meron Assefa can't take this ticket" (assignee inline refusal, list not pre-filtered) → `AssignPalette.vue` → refusal case; "Withdrawal times" (KB hover preview) → `KbHoverPreview.vue` (Doc 10 fixture seam) → hover/focus spec; List/Thread Light+Dark density insets → density spec.

**B1-S02-inbox-states-v2** (rows 101–168; bulk-bar reason VISIBLE AND FOCUSABLE) (s9–12): Loading 8-skeletons+em-dash | Empty (My Open "Inbox zero 🎉 … Check Unassigned (N)" with action; Unassigned "All caught up") | Error inline-retry | Live-resort·selection·bulk-bar·drift-tray | Thread no-selection·loading·error | Collapsed-events·first-unread·clamp·tombstone·failed-send | New-conversation identity card ("staff-collected", NEVER "verified" — N-06 absence assert) | Note mode | Window-expired·connection-paused·conversation-blocked | Collision·send-race·offline-queue·partial-failure·draft-guard | Resolved-by-rule-while-typing | Connection/deploy/health banners | Agent status away-countdown·forced | Search no-hits·unavailable·out-of-scope·notifications | Mobile offline+empty 375 → their owning components/specs per the plan table.

**B1-S03-views-search-notify-v2** (s4/12/13): rail + 56px collapsed | reordering + "No shared views yet" | saved-view editor | ⌘K desktop three sections | bell + empty | shortcut overlay (complete v1 map) | coach marks ×4 | Views sheet Light/Dark FOUR labelled sections | Search Dark.

**B1-S04-ticket-operations-v1** (s14): Merge incl. "What happens" preview (the merge-preview READ) | Split/escalate/spam/transfer | "New ticket — blocked by an open one" | 375 full-screen dialogs (Escalate/Merge/Split, both themes) | Thread print layout (SEC-05).

**B2-S01-mobile-interactions-v3** (s13, 16 frames): swipe-claim revealing/committed/undo ×2 themes | actions sheet ×2 | pull-to-refresh pulling/refreshing ×2 | selection partial/at-cap-25 ×2 | context sheet | composer keyboard-up | attachment sheet | 1024–1279 R4 drawer | 768–1023 icon rail | scroll-restoration triptych.

**B2-S02-mobile-search-status-v3** (scope toggle REMOVED — never reappears) (s12/13, 17 frames): A10 search opened/skeleton/no-results/am-results/failed-offline | A11 sheet populated/muted/empty | A12 status entry/sheet/Away-chosen | A15 thumb-reach ×2 | A16 density 76/60 ×2 themes.

**B2-S03-ticket-properties-v2** (s14, 10 frames): B9 pending-reason panel/sheet | B12 timeline (+am actors, escalation) | B13 subject AI-at-rest/being-edited-am/edited-by-staff+mobile | B14 mobile sheet rest/scrolled.

**B2-S04-state-affordances-v2** (s14, 13 frames): C8 resolved+auto-close | C9 closed+lapsed | C13 reopened+count | C10 spam 7-day | C11 escalated+clock | C12 unreachable | spam-terminal | C7 unmerge available/refused-pruned | C15 bulk drawer running-with-failures + sheet | C16 confirm-phrase >50.

**B2-S05-states-v2** (s9–14, 21 frames): D1–D6 property-panel states | D7–D12 two-people-one-ticket | D13–D15 universal 375 + offline | D18 sheet motion | D19 Rescue populated | D20 reply anchor.

**B12-S01-media-lightbox-v3** (s10, 6 frames): 1440 photo+description | 375 pinch/video/states | photo-at-rest | video-no-description | loading-then-failed | infected — no download, no description (SEC-04 absence).

**B12-S02-edit-marker-v2** (s10, 7 frames): permitted marker | actions-by-capability | history open | no-permission view | three-markers thread | history sheet 375 | editing-own + audit line.

**B12-S03-macro-palette-v1** (s11, 7 frames): nothing-typed | fuzzy+missing-variable | nothing-matches | no-macros-yet | sheet list-first | preview+missing-variable | after-insertion-unsent.

**R-22 fill (class-2, filed):** settings-row (B6-X01 J03–J08 pattern) + confirmation component + Doc 06 T-07 engine progress → `TagManager.vue` → rename/merge spec incl. mid-rewrite resume.

**Token law:** fixed tokens verbatim both colourways; status hues reserved (R-07/R-08); R-25 ink-ramp on outage/health bars; R-24 fallback-language rendering wherever operator content renders, with the §14 completeness indicator.

## SABOTAGE LIST — the enumeration (ledger reconciles item-by-item against these 20)

1. Scope filter dropped from list read model → scoped-list proof red
2. Search index fed without tenancy scope → scoped-search red
3. Selection-token cap removed → Doc 06 bulk-limit test red
4. Token single-use/user-binding removed → SEC-06 reuse proof red
5. Tab-leader election disabled → duplicate-notification red
6. Resort batcher bypassed → FR-11 affordance red
7. Delivered glyph for a Telegram message → AC-07 DOM red (the Doc 05 closing proof)
8. Collision warning suppressed → FR-16 dual-session red
9. Projector made non-idempotent → C-09 replay red
10. Sync ordering broken → reconnect golden red
11. R-19b grant dropped on `inbox_notifications` → schema guard red at MIGRATION level
12. View-channel subscribe re-auth removed → SEC-01 red
13. Client derives count from partial window → T-03 contract red
14. Note payload routed toward an adapter path → Doc 04 AC-04 integration red
15. EC-01 deep link confirms existence → 404-pattern red
16. `events.occurred_at` guard dropped (the app column grant removed) → R-19 schema guard red at MIGRATION level (C2)
17. FS-05 recovery drops composer text → drafts-last red
18. Staleness pill suppressed in projector outage → FS-01 red
19. Desktop notification includes message content → SEC-04 red
20. Print path ignores note gate → SEC-05 red
21. [AMENDED 20→21 by ruling A-33 at the step-1 checkpoint, filed before the
    sabotage ran] the relay's stamp-copy broken (a fresh clock supplied
    instead of the outbox byte-copy) → the A-33 byte-identical proof in
    EventsOccurredAtTreatmentTest goes red.
22. [AMENDED 21→22 by ruling A-35 at the 6+7 acceptance, filed before the
    sabotage runs — A-35 condition (3)] `BindTenantContextToJob` reverted
    to clear-not-restore → the A-35 sync-driver restore regression test
    goes red. THE LEDGER RECONCILES AGAINST 22 ITEMS.

Backup before sabotage; fail AND restored-pass pasted; tree proven clean before commit;
captures unfiltered first; verdicts from unfiltered capture summaries + exit codes only.

## Pre-agreed buckets (the only deferrals)

Doc 08 panel contents · Doc 09 panel contents · Doc 11 queue views · Doc 12 definitions ·
Doc 15 supervisor · §12/NFR L2 p-values → Doc 19. Slots/seams/fixtures for each are THIS
session's. Nothing else defers.

## Amharic (R-14 standing rider)

All `inbox.*` en+am; close-out states the count and queues everything machine-authored;
destructive tier flagged: bulk-execution confirms, the C16 confirm-phrase, tag
rename/merge confirmations, discard-draft, send-race confirm.
