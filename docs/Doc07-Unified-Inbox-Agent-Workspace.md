Table of Contents

# Document 07 — Unified Inbox: Agent Workspace

**Product:** AI-First Support Platform for Betting Companies **Document series:** 07 of 19 · Standalone enterprise specification **Audience:** Frontend engineers, backend engineers (read models, realtime), UX/UI designers, QA, product **Depends on:** Doc 02 (sessions, drafts restore), Doc 03 (capability map, scopes), Doc 04 (messages, states, presence, drafts), Doc 05 (window/composer states, health banner), Doc 06 (ticket properties, transitions, bulk). Consumed by: Doc 09 (Copilot panel slot), Doc 08 (customer panel slot), Doc 11 (queue views), Doc 12 (SLA chips, macros). **Stack context:** Inertia + Vue 3 + TypeScript, Reverb/Echo, Redis read models, Meilisearch

## 1. Executive Summary

This document specifies the agent workspace — the screen where support staff spend their entire shift. It defines the four-column desktop layout (Views → List → Thread → Context) and its mobile two-level collapse, the views system (system views, queue views, shared and personal saved views), list-row anatomy and live reordering, thread rendering rules composing Doc 04–06 contracts, the composer with macros, saved replies, AI actions slot, and channel-state awareness, selection and bulk UX, presence and collision handling, global and scoped search, notifications and sounds, agent status (Online/Away/Offline) and its routing contract, offline/reconnect behavior, keyboard-first operation with the complete shortcut map, and the read-model + realtime architecture that keeps 500 concurrent agents’ lists correct at ≤1.5 s latency without stampeding the database. Panels owned by other documents (Customer — Doc 08; Copilot — Doc 09) plug into named slots specified here.

## 2. Purpose

- Specify the highest-traffic screen to pixel-and-state completeness so frontend can build while backend builds the read models against the same contract.

- Define the views/read-model architecture that makes lists fast, live, and scope-correct at target load.

- Establish the interaction grammar (shortcuts, selection, optimistic updates, error recovery) reused by every other staff screen.

## 3. Scope

**In scope:** layout & responsive behavior; views system; list rendering & ordering; thread composition; composer & send flows; macros/saved-replies UX (definitions in Doc 12); selection & bulk UX; presence/collision; typing; search; notifications & sounds; agent status; offline/reconnect; shortcuts; empty/loading/error states for all regions; read models & realtime protocol; performance budgets. **Out of scope:** ticket semantics (Doc 06), message semantics (Doc 04), Customer panel contents (Doc 08), Copilot contents (Doc 09), queue configuration (Doc 11), macro/SLA definitions (Doc 12), supervisor dashboard (Doc 15).

## 4. Functional Requirements

### 4.1 Layout & regions

FR-01 Desktop ≥1280 px: four regions — R1 Views rail (240 px, collapsible to 56 px icons), R2 List (360 px, resizable 300–480), R3 Thread (flex), R4 Context panel (380 px, collapsible; tabs: **Ticket** (Doc 06 property contract), **Customer** (Doc 08 slot), **Copilot** (Doc 09 slot)). Region sizes persist per user. FR-02 1024–1279 px: R4 becomes an overlay drawer; 768–1023 px: R1 collapses to icons permanently; <768 px (mobile): two-level navigation — List screen ↔ Thread screen; R4 = swipe-up bottom sheet with the same three tabs; Views = modal sheet from the header. FR-03 Top bar: global search (⌘K), New-ticket, notification bell with badge, agent-status control, channel-health indicator (Doc 05 FR-29 rollup; degraded/down shows a colored dot + banner on open), profile menu. FR-04 Multi-tab safety: the app is fully multi-tab capable; realtime state converges across tabs; sounds/desktop notifications deduplicate via a tab-leader election (BroadcastChannel).

### 4.2 Views system

FR-05 System views (fixed, per-user counts): **My Open** (assignee=me, open-family), **Unassigned** (scope-visible, assignee=∅), **Mentions** (unresolved @mentions), **Following**, **SLA at risk** (Doc 12 warned/breached, open-family), **Expiring windows** (Doc 05 FR-22 closing/expired with open tickets), **Rescue** (channel_unreachable=true, Doc 05 FR-10). Queue views auto-generated per accessible queue (Doc 11). FR-06 Saved views: any user with tickets.view creates personal views = filter set (status[], priority[], department, queue, assignee, tags, language, channel, has-draft, created/updated ranges) + sort + visible-column config; sharing a view to team/department requires reports.dashboards.share-analogous key views.share (added to Doc 03 catalog change log); shared views are read-only to consumers with “duplicate to edit.” FR-07 Every view shows a live count badge (see T-03 counting contract); counts cap display at “999+”. FR-08 View ordering: user-arrangeable (drag) within sections (System / Queues / Shared / Mine); persisted.

### 4.3 List

FR-09 Row anatomy (dense, 2-line): line 1 — channel glyph (+dual-channel badge per Doc 05 EC-04 grouping), customer display name, ticket number, SLA countdown pill (Doc 12) or window chip (Doc 05) whichever is most urgent, updated-at (relative); line 2 — snippet (last public message, or subject fallback per Doc 06 EC-11), language chip, sentiment dot (Doc 09 latest), priority dot, assignee avatar (or “unassigned” ring), tag overflow (+N), draft pencil (Doc 04 FR-20), unread bold state + count. FR-10 Ordering: default per view (system views define theirs: My Open = last activity desc; SLA at risk = time-to-breach asc; Unassigned = created asc “first-come”); user sort override persists per view. FR-11 Live behavior: incoming changes patch rows in place; re-sorting is **batched every 5 s** with a “list updated — resort” affordance when the user’s pointer is inside the list (prevents rug-pulls mid-click); new rows entering the view slide in at position with a 300 ms highlight; rows leaving the view fade + collapse (150 ms). FR-12 Infinite scroll (cursor, 50/page) with scroll-position restoration on back-navigation; total count in the header. FR-13 Selection: click-checkbox, shift-range, ⌘/ctrl-toggle, “select all in view” (server-side selection token for >page selections, capped by Doc 06 bulk limits); selection survives live re-sorts.

### 4.4 Thread

FR-14 Renders Doc 04 §8/§9 contracts fully (bubbles, states, media, tombstones, typing) plus: date separators; ticket-boundary markers (“Ticket #4811 closed · Ticket #4812 opened”) since a conversation spans tickets (Doc 06); handoff-packet card (Doc 06 FR-11) pinned at its ticket start, collapsible; system events inline (assignment, transfers, escalations) in muted style, collapsible in bulk (“show 12 events”). FR-15 Navigation: opens at first unread (else bottom); “jump to first unread” and “jump to latest” affordances; deep links /inbox/t/{ticket} and /inbox/c/{conversation} resolve with correct anchor; reply-to click scrolls to anchor with context fetch (Doc 04 EC-08). FR-16 Presence & collision: viewer avatars (Doc 04 FR-19) top-right; if another staff member starts typing a reply in the same ticket, the composer shows a live warning strip “Sara is replying…” (their draft is not shown); on send race, the later sender gets a non-blocking confirm (“Sara just replied — send anyway?”).

### 4.5 Composer

FR-17 Modes: **Reply** (public) / **Note** (internal, visual switch flips the composer to note-yellow, Doc 04 FR-17) — mode is sticky per ticket per user; hard visual differentiation is mandatory (border + background + label), and the send button is labeled “Send reply” vs “Add note”. FR-18 Content: rich-lite (bold/italic/links/lists → downgraded per channel capability at send, with a per-channel preview hint), attachments (drag-drop/paste/picker; per-channel caps from Doc 04/05 capabilities shown live), emoji picker, voice recording behind flag. FR-19 Insertions: / opens macros (Doc 12; personal → shared, fuzzy search, preview pane, variables resolved live with missing-variable warnings); saved replies under the same palette; **AI actions slot** (Doc 09 owns contents: draft/improve/translate/summarize) renders as a button cluster + inline results. FR-20 Channel-state awareness (composer states, from Doc 05 §8 contract + Doc 04 reasons): normal / closing-countdown strip / expired options card / connection_paused / blocked_by_user / conversation-blocked — the composer is the single renderer for all of these. FR-21 Send flow: optimistic bubble (queued glyph) → state glyph updates via Doc 04 events; failures render inline retry per Doc 04 §8; **send-and-set-status** split button (Send / Send & Resolve / Send & Pending-customer) executes reply then Doc 06 transition atomically-in-order with rollback messaging if the transition is rejected (EC-08 Doc 06 prompt integrates here). FR-22 Drafts per Doc 04 FR-20; a per-ticket draft chip in the thread header; unsent-draft guard on navigation (only when >20 chars, “discard / keep”).

### 4.6 Search

FR-23 ⌘K palette: sections — Actions (navigate, transitions on current ticket, status change), Views, and **Search** across tickets (number, subject), customers (name, username, phone), messages (full-text, scope-filtered) — all scope-correct via Doc 03; results keyboard-navigable; recent searches per user. FR-24 In-view filter bar: quick filters chips (status, priority, assignee=me, has-draft) compose *within* the current view’s base filters; clear-all. FR-25 Message search results deep-link to the thread anchored at the hit with highlight.

### 4.7 Notifications, sounds, status

FR-26 Notification bell: assignment, mention, follower events (Doc 06 FR-18), escalation-to-me, SLA warning on mine, window-closing on mine (Doc 05 FR-22 opt-in), bulk-completed; each row deep-links; mark-all-read; per-category toggles live in Doc 16 preferences but a quick mute (1 h/today) sits on the bell. FR-27 Sounds: new-message-in-my-open-ticket (default on), new-unassigned-in-my-queues (default off); tab-leader plays once (FR-04); respects OS focus (no sound when thread visible & focused). FR-28 Agent status: **Online / Away / Offline** control; contract: Online = routable (Doc 11 consumes agent.status_changed), Away = keeps assignments, not routable, auto-set after inbox.away.idle_minutes (default 10) of no activity with a 60 s countdown toast to cancel; Offline = manual or logout; heartbeat loss > 90 s → system-away (Doc 04/11 rescue timers reference this). Supervisor force-status (Doc 03 supervision.agent_status.force) reflects here with attribution (“set by Dawit”).

### 4.8 Offline & reconnect

FR-29 Socket loss: amber “reconnecting” pill (top bar) within 2 s; list/thread continue read-only from cache; composer queues sends locally (Doc 04 §9) with visible chips; on reconnect: sync endpoint (/v1/sync?cursor) reconciles (Doc 04 T-06/Doc 01 conventions), queued sends flush in order with idempotency keys; a full-offline browser state shows the offline screen with cached last-view read-only. FR-30 Version skew after deploy: server includes build id in payloads; mismatch → passive “refresh for updates” toast (never forced mid-composition).

### 4.9 Keyboard map (complete, v1)

FR-31 J/K next/prev row · Enter/O open · X select · Shift+X range · R focus reply · N note mode · A assign palette · I assign to me · E resolve · Shift+E send & resolve · P priority palette · T tag palette · S snooze-to-pending palette · M mentions view · G then U/M/A go to Unassigned/My Open/SLA-at-risk · ⌘K palette · ⌘Enter send · Esc close panel/deselect · ? shortcut overlay. All remappable v2 (§19); overlay lists live bindings.

## 5. Non-functional Requirements

NFR-01 Cold load (authed) to interactive list ≤ 1.5 s p75 on mid-range hardware / 3G-fast; thread open ≤ 400 ms p75 warm. NFR-02 Live update visible ≤ 1.5 s p95 end-to-end (Doc 04 AC-07 shared measurement). NFR-03 60 fps scroll on 10k-row virtualized lists and 10k-message threads (Doc 04 virtualization inherited). NFR-04 JS bundle (inbox route) ≤ 350 KB gz initial; panel tabs lazy-loaded. NFR-05 Memory ≤ 300 MB tab at 8 h soak with 5k processed events (leak budget test). NFR-06 All optimistic operations have defined rollback UX (no silent divergence); reconciliation proof via sync-endpoint golden tests. NFR-07 Read-model correctness: view membership/counts exactly match a direct scoped query (consistency checker in staging, alert on drift).

## 6. User Stories

US-01 As an agent, I run my whole shift from one screen — claim, answer, resolve — without touching the mouse. US-02 As an agent, when 30 tickets pour in during a match, my list stays readable: no rows jumping under my cursor, new work clearly marked. US-03 As an agent, I always know which channel state I’m in before I type — the composer tells me if the window is closing or the player blocked the bot. US-04 As an agent on the bus (phone), I can triage Unassigned and send quick replies with the same features, just stacked. US-05 As a team lead, I share a “VIP pending > 2 h” view with my team and everyone sees the same live list. US-06 As an agent, losing Wi-Fi for two minutes costs me nothing: my typing is preserved, my sends go out on reconnect, in order. US-07 As an agent, a colleague opening my ticket is visible to me before we collide, and a send race never posts a duplicate answer silently.

## 7. Business Rules

BR-01 The inbox never lies about state: every glyph, count, and chip is backed by a server event; optimistic UI must reconcile or roll back visibly (NFR-06). BR-02 Note vs Reply confusion is treated as a safety hazard: the visual differentiation (FR-17) is not themeable below a minimum contrast delta, and QA includes a “wrong-mode” heuristic test (see AC-05). BR-03 Scope is invisible plumbing: agents never see out-of-scope rows, search hits, or counts — absence, not lock icons (consistent with Doc 03 SEC-02 philosophy). BR-04 Unassigned is first-come by default (FR-10) — fairness to customers over agent cherry-picking; operators may re-sort per view but the default encodes the value. BR-05 Sounds and desktop notifications are per-user choices; the platform never force-enables noise. BR-06 Away-auto is generous (10 min) and cancelable — status is for routing truth, not surveillance theater; active-time metrics (Doc 15) derive from activity events, not status.

## 8. UX Requirements

- **Empty states per region:** Views (none shareable yet → explainer); List (view empty → contextual: My Open shows “Inbox zero 🎉 — check Unassigned (12)” with action; Unassigned empty → “All caught up”); Thread (no selection → keyboard-hint panel with the shortcut overlay teaser); Search (no hits → per-section empties with query echo).

- **Loading:** list skeleton rows (8) → progressive fill; thread skeleton (Doc 04); panel tab skeletons independent; view counts show em-dash until first value (never 0-then-jump).

- **Error surfaces:** list load failure → inline retry card (keeps rail usable); thread failure → retry + “open customer instead”; send-and-status partial failure → reply stays sent, transition error toast with one-tap retry of just the transition (Doc 06 EC-08 prompt style).

- **Live-resort affordance (FR-11):** pill “List changed · Resort” appears max once/5 s; auto-resort when list unhovered ≥ 3 s.

- **Collision strip (FR-16):** non-blocking, dismissible per occurrence, never covers the composer text.

- **Mobile specifics:** swipe-right on row = quick claim (haptic), swipe-left = quick actions sheet (resolve/pending/priority); pull-to-refresh on list; composer sticky above keyboard with attachments in an overflow sheet; bulk capped 25 (Doc 06 §9).

- **First-run:** 4-step coach marks (views, list, composer modes, ⌘K), dismiss-forever, re-openable from ? overlay.

## 9. UI Requirements

- Density toggle (comfortable/compact) affects list row height (64/52 px) and thread spacing; persisted.

- Tokens per design system: status/priority hues from Doc 06 §9; window/SLA chips from Doc 05/12 shared components; AI-origin content always carries the AI accent (platform rule).

- The three context tabs keep fixed order (Ticket, Customer, Copilot) with badge dots (Copilot: new suggestion; Customer: RG banner presence per Doc 08 rules).

- Virtualized list maintains stable scrollbar geometry (estimated row heights locked to density).

- Dark/light complete; channel-health dot colors mirror Doc 05 rollup; reduced-motion: slides/fades become instant, highlight becomes outline.

- Print stylesheet for a thread (ticket header + messages, notes excluded unless conversations.history.full and explicitly toggled) — used before Doc 04 transcript export exists in a workflow.

## 10. Technical Requirements

T-01 **Read models:** per-view membership is *not* computed per request. A list-projector consumer (Doc 01 events) maintains Redis sorted sets per (tenant, view-hash) for system/queue views and materialized filter results for saved views (recomputed on definition change + incrementally patched by events); rows hydrate from a ticket_list_items denormalized table (Doc 06 T-08) by id batch. View counts = ZCARD (FR-07/T-03). T-02 **Realtime protocol:** channels tenant.{id}.view.{viewHash} carry deltas {op: add|remove|patch, id, sort_key, patch{}}; clients apply to local stores; full-row fetch on cache miss; thread channel per Doc 04 T-06; protocol versioned (v:1). T-03 **Counting contract:** view badge = server ZCARD pushed on delta (debounced 2 s); client never derives counts from its partial window. T-04 **Search:** Meilisearch indexes tickets_index (number, subject, tags, customer names) and messages_index (body, scope keys); scope filtering via filterable attributes (department_id, assignee_id, team ids) matched to the Doc 03 scope of the requester server-side (search proxy injects filters; the client never sends scope). T-05 **Selection tokens:** “select all in view” creates a server-side selection (view-hash + filter snapshot + count) consumed by Doc 06 bulk; guards against membership drift by resolving ids at execution with a drift report (“3 items left the view and were skipped”). T-06 **State management:** a normalized client store (tickets, conversations, messages, views) with event-sourced updates; all mutations via command objects that pair optimistic-apply with rollback handlers (NFR-06 enforcement point). T-07 **Sync endpoint:** /v1/sync?cursor returns ordered events since cursor for the user’s subscribed scopes (bounded 5k; overflow → instructs full refetch of affected views) — the single reconciliation path for reconnect/mobile-resume. T-08 **Perf tooling:** CI Lighthouse budget on the inbox route; soak test harness replaying L2 event streams into a headless client asserting NFR-03/05.

**Data flow (text diagram):**

Doc 06 transition / Doc 04 message events ──► list-projector
   ├─ update ticket_list_items row
   ├─ patch view ZSETs (add/remove/rescore)
   └─ publish view deltas + count ──► Reverb ──► clients patch stores
client action → optimistic apply → API → event confirms (or rollback handler fires)
reconnect → /v1/sync?cursor → replay deltas → stores converge

## 11. Security Requirements

SEC-01 View hashes embed scope context server-side; subscribing to a view channel re-authorizes (Doc 04 T-06); scope changes (Doc 03 FR-07) force resubscribe with pruned membership pushed. SEC-02 The search proxy is the only search path; direct Meilisearch exposure prohibited; injected filters tested per Doc 03 AC-03 fixtures. SEC-03 Client stores are per-tab memory; no ticket/message content in localStorage (drafts excepted per Doc 04 T-07 — body only, IndexedDB, cleared on logout). SEC-04 Desktop notifications show customer display name + ticket number only — never message content (shoulder-surfing posture; setting can reduce further to “New activity”). SEC-05 Print/preview paths respect note gating (§9) and watermark with user + timestamp. SEC-06 Selection tokens are user-bound and single-use per bulk execution.

## 12. Performance Requirements

- Budgets NFR-01–05 verified under L2 (Doc 19): 500 concurrent agents, 200 events/s platform, 30 events/s on a single hot view.

- Projector lag (event → ZSET patched) ≤ 500 ms p95; delta publish ≤ +200 ms.

- Saved-view recompute (definition change) for 50k candidate tickets ≤ 5 s with progress indicator.

- Search p95 ≤ 150 ms (tickets) / ≤ 300 ms (messages) at 10 M message index.

- Sync endpoint p95 ≤ 250 ms for ≤ 500-event replays.

## 13. Accessibility Requirements

Full WCAG 2.2 AA: the four regions are landmarks with skip-links; list is a proper listbox with roving tabindex (J/K mirror arrows); live-region announcements: batched list changes (“List updated: 3 new”), incoming message in open thread (Doc 04 log region), collision strip (role="status"); composer mode change announced explicitly (“Internal note mode”); split-button send exposes all three actions in the menu; shortcut overlay reachable via ? and screen-reader friendly; badge counts have accessible labels (“My Open, 12 tickets”); all drag interactions (view reorder, panel resize) have keyboard equivalents; focus is never stolen by live updates (documented focus policy, tested).

## 14. Localization Requirements

All inbox strings under inbox.* (en + am); relative times localized with ICU (“2 min ago” ↔ Amharic equivalents) and switch to absolute at >24 h; snippet direction handles mixed LTR/RTL content (bidi isolation); shortcut overlay shows localized labels but physical-key bindings remain layout-aware (documented for non-QWERTY v2); number formatting in counts localized; empty-state copy culturally reviewed (the “🎉” is a token, replaceable per tenant via Doc 16).

## 15. Edge Cases

EC-01 Agent opens a deep link to an out-of-scope ticket → 404-pattern page (Doc 03 SEC-02) with “search instead” — never confirms existence. EC-02 Row updates while selected → selection persists by id (FR-13); if it leaves the view, it stays selected in a “no longer in view” tray on the bulk bar until deselected or executed (drift report per T-05). EC-03 Two tabs, composer open in both for the same ticket → drafts sync last-writer (Doc 04 EC-06) with the toast; typing indicators do not self-trigger. EC-04 View shared to a team member without scope for some rows → they see only their scoped subset with no indication of hidden rows (BR-03); the count badge is per-viewer (T-01 per-scope ZSET variants or filtered count — implementation note documented). EC-05 1k-row bulk intent → “select all in view” token carries true count; execution chunks per Doc 06 caps with a queued-batches progress drawer. EC-06 Ticket transitions while its thread is open (by rule/colleague) → property panel patches live; composer state re-evaluates (may switch to resolved-state prompt per Doc 06 EC-08). EC-07 Clock skew client-side → relative times computed from server-now offset (handshake), not client clock. EC-08 Reverb reconnect storm after deploy → clients jitter reconnects (0–10 s) and rely on sync cursors; server sheds with 429-on-connect honored by backoff. EC-09 An agent’s only queue is deleted (Doc 11) mid-shift → queue view vanishes with a toast; My Open unaffected; deep links to it fall back to My Open. EC-10 Extremely long single message (16 KB) → bubble clamps at 40 lines with “show more” expansion; search hit anchors still resolve. EC-11 Snippet contains media-only message → kind placeholder snippet (“📷 Photo”, localized), never raw storage keys. EC-12 Draft exists on a ticket that gets merged (Doc 06) → draft moves with the user to the survivor ticket (drafts keyed by conversation, which persists), with an info chip.

## 16. Failure Scenarios

FS-01 Projector down → lists freeze but remain correct-as-of; staleness pill after 30 s (“Live updates delayed”); on recovery, projector resumes from event backlog; clients converge via deltas + sync. No agent action is blocked (writes go through normal APIs). FS-02 Redis view ZSET loss → rebuild job from scoped queries per view (bounded, prioritized: system views first); during rebuild, affected views serve direct-query fallback with a perf-degraded flag (alert per Doc 18). FS-03 Meilisearch down → search sections show “search unavailable” inline; palette Actions/Views sections keep working; indexer catches up from events on recovery. FS-04 Sound/notification API permission denied → silent downgrade with a one-time hint in the bell menu; never re-prompt loops. FS-05 Client store corruption (schema mismatch after skew, FR-30) → auto-recover by store reset + refetch of the active view/thread, preserving the composer text above all (drafts are the last thing ever dropped).

## 17. Success Scenarios

SS-01 An agent completes a 60-ticket shift entirely on keyboard; time-motion review shows zero forced mouse interactions in the core loop. SS-02 Match-night burst: 30 events/s on the Unassigned view for 10 min; agents report a stable list (resort affordance metrics: <1 auto-resort per 5 s honored), zero duplicate claims beyond the designed race message. SS-03 A metro Wi-Fi drop (90 s) across 40 agents: all queued sends flush in order, zero duplicates (idempotency), zero lost drafts. SS-04 The staging consistency checker (NFR-07) runs a week with zero drift alerts across all view types.

## 18. Acceptance Criteria

AC-01 Layout matrix: all four breakpoints render the specified region behavior; persisted sizes/collapse restore across sessions. AC-02 Views: system-view membership fixtures (per FR-05 definitions) match direct queries exactly; saved-view CRUD + share + duplicate-to-edit round-trips; per-viewer counts correct in the EC-04 fixture. AC-03 Live list: scripted delta streams produce the exact FR-11 behaviors (batched resort, pointer-hold, enter/leave animations) verified by automated UI tests with visual assertions. AC-04 Composer: all Doc 05/04 channel states render per contract (fixture-driven storybook + integration); send-and-set-status success and partial-failure paths behave per §8. AC-05 Mode-safety: heuristic test — 50 scripted “reply-intent” sequences into note mode trigger the differentiation checks (contrast delta, label assertions); zero note payloads reach adapters (Doc 04 AC-04 integration). AC-06 Collision: dual-session scripts produce the strip, the send-race confirm, and exactly one visible outcome ordering. AC-07 Offline: 2-min network cut during composition → reconnect flushes queued sends in order, drafts intact, stores converge (sync golden test). AC-08 Shortcuts: every FR-31 binding automated end-to-end; ? overlay matches live bindings; no binding conflicts with browser defaults on the support matrix. AC-09 Search: scope-injection fixtures (Doc 03 AC-03 reuse) prove no out-of-scope hits; message deep-link anchors land highlighted. AC-10 Perf: CI budgets (NFR-01/03/04) enforced; 8 h soak within memory budget; projector lag SLO met under L2.

## 19. Future Expansion

- Remappable shortcuts + Vim/Zendesk preset maps (FR-31 v2).

- Split-view (two threads side-by-side ≥ 1600 px) — region model already accommodates an R3b.

- Inline mini-Copilot ghost-text (Doc 09 dependency) behind the AI accent rules.

- Snooze with time picker (beyond pending) using Doc 06 timer infra.

- Workspace “focus mode” (single-ticket, distraction-free) for training/QA review sessions.

- Native mobile app shell (the mobile web contract here is its spec baseline).

## 20. Risks

R-01 Read-model complexity (High/High — the hardest engineering in the series) → the consistency checker (NFR-07), rebuild jobs (FS-02), and direct-query fallback are designed in from day one; ship system views first, saved views second. R-02 Live-list UX churn eroding trust (Medium/High) → FR-11’s batching is a product commitment, not a tuning knob; telemetry on resort frequency reviewed at beta. R-03 Bundle growth as panels accrete (High/Medium) → route-level budgets in CI (T-08), lazy tabs, quarterly bundle review. R-04 Multi-tab edge cases (Medium/Medium) → tab-leader election + idempotent sends + the EC-03 fixtures; soak includes dual-tab runs. R-05 Mobile parity pressure (“just make it the app”) (Medium/Low) → the two-level contract is the deliberate scope; native shell is §19, not scope creep.

## 21. Recommendations

- Build in this order: thread (on Doc 04 fixtures) → composer states → system views + projector → saved views → search → polish; each stage is shippable to internal dogfood.

- Stand up the staging consistency checker (NFR-07) the same week the projector lands — read-model bugs found by agents cost trust you won’t get back.

- Dogfood with your own future support team on real Telegram staging traffic for two weeks before agent onboarding; the FR-11 batching values and Away timing (FR-28) should be tuned from that data.

- Treat the composer’s channel-state card set as the single most important UX in the product — it is where Doc 05’s complexity either becomes operable or becomes tickets about your ticket system.

- Keep the Copilot and Customer tabs strictly slot-based (Docs 08/09 own contents); the inbox team owning panel internals is how workspace codebases rot.

*End of Document 07. Next in series: Document 08 — Customer Profiles **&** Identity.*