Table of Contents

# Document 05 — Telegram Channel: Business Account + Direct Bot

**Product:** AI-First Support Platform for Betting Companies **Document series:** 05 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, DevOps, operator admins, product **Depends on:** Doc 01 (tenancy, settings, events), Doc 02 (webhook auth FR-23), Doc 03 (permissions telegram.*), Doc 04 (adapter contract, pipelines, state machine). Consumed by: Doc 06 (routing), Doc 07 (composer states), Doc 09 (AI flows), Doc 14 (SMS fallback), Doc 15 (Telegram reports). **Stack context:** Telegram Bot API (webhooks; standard + Business updates), Nutgram (PHP), Redis token buckets

## 1. Executive Summary

This document specifies the Telegram channel in both of its operating modes and their hybrid: (a) **Business Account mode** — the primary model, where players message a human-looking Telegram support account and a Business-Mode bot connected to that account bridges its chats into the platform via business_connection / business_message updates, replying on the account’s behalf with business_connection_id; and (b) **Direct Bot mode** — players message the bot itself (deep links, /commands, rich inline-button flows). It defines the Doc 04 adapter implementation in full: setup wizards, connection lifecycle and health, the ~24-hour on-behalf reply window (tracking, UI states, SMS fallback), update-type mapping, media mapping, delivery-state mapping, typing signals, rate limiting, the menu designer, per-language bot messages, multi-bot and multi-account topologies, and every Telegram-specific edge case. The connection method is an operator **Setting** per Telegram channel: direct | business | hybrid.

## 2. Purpose

- Implement Doc 04’s adapter contract for Telegram completely, so core behavior (states, retries, media, ordering) is inherited, and everything Telegram-peculiar is isolated here.

- Make the Business-account model operable by non-technical operator admins: guided connect, visible health, understandable failure states.

- Specify the reply-window mechanics end-to-end (data, jobs, composer UX, fallbacks) since they are the single biggest behavioral difference from ordinary chat channels.

## 3. Scope

**In scope:** bot registration & webhook lifecycle; Business Mode enablement and connection flow; connection registry & health; mode setting (direct/business/hybrid) semantics; inbound update mapping (standard + business, edits, deletions); outbound send mapping incl. business_connection_id; reply-window tracking, states, expiry sweep, and fallbacks; typing via sendChatAction; delivery-state truth mapping; media mapping (photos, voice, video, documents, stickers); commands & deep links (direct mode); menu designer & bot message templates; rate limits; per-connection reports feed; admin screens; Telegram-specific edge/failure cases. **Out of scope:** core pipelines and state machine (Doc 04); AI conversation logic (Doc 09 — this doc only defines where flows attach); routing/ticket rules (Doc 06); SMS template content (Doc 14); groups/channels support (deferred, §19).

## 4. Functional Requirements

### 4.1 Bot registration & webhook

FR-01 Add-bot wizard step 1: admin pastes BotFather token → server-side getMe validation → store token as vault reference (Doc 01 SEC-04) → create bot record with unguessable route key → setWebhook to /hooks/telegram/{bot_key} with a generated secret_token, allowed_updates explicitly including: message, edited_message, callback_query, my_chat_member, business_connection, business_message, edited_business_message, deleted_business_messages → verify via getWebhookInfo and display result. FR-02 Webhook controller behavior per Doc 04 FR-10 step 1; secret header mismatch per Doc 02 FR-23. FR-03 Re-register webhook action (button) is idempotent and safe anytime; drops nothing (Telegram redelivers pending updates). FR-04 Deleting a bot requires: zero active business connections OR explicit cascade acknowledgment; performs deleteWebhook; conversations remain readable (channel endpoint archived).

### 4.2 Connection method setting

FR-05 Per bot: mode ∈ {direct, business, hybrid} (Backoffice → Channels → Telegram → Connection tab; permission telegram.bots.manage). Semantics: - direct: business updates, if any arrive, are stored but flagged unconfigured_source and alert admins (misconfiguration signal). - business: direct messages to the bot get an auto-reply template (“please contact @SupportAccount”) — template editable per language; conversation still stored. - hybrid: both fully active; the same customer via both paths resolves to one customer (Doc 08) with two conversations (one per endpoint) — inbox groups them under the customer (Doc 07). FR-06 Mode changes take effect immediately; changing away from business with active connections requires typed confirmation and does not disconnect accounts (it only changes handling) — disconnect is a separate explicit action.

### 4.3 Business connection lifecycle

FR-07 Connect flow (wizard step 2 when mode includes business): checklist UI — (1) enable Business Mode for the bot in BotFather (instructions + deep link), (2) on the support phone: Settings → Telegram Business → Chatbots → select the bot → grant “reply” permission, (3) platform waits (live) for the business_connection update → displays account name/id, can_reply, granted scope → admin confirms “this is our account” → connection saved active. FR-08 Connection registry (per Doc 01 schema telegram_business_connections): one bot ↔ many connections (one per support account); each connection is an inbound line with independent health, reports dimension, and routing tag (Doc 06 can route by connection). FR-09 Lifecycle states: active → paused (owner-side pause or can_reply=false) → active and active|paused → revoked (owner disconnects). All transitions come from business_connection updates; each emits telegram.connection.{state} events, updates health, and triggers admin notification on paused|revoked. FR-10 On revoked: all open tickets on that connection are flagged channel_unreachable, listed in a rescue view (Doc 07), and outbound lane for the connection freezes (Doc 04 FR-14 non-retryable classification).

### 4.4 Inbound mapping (adapter → Doc 04 NormalizedInbound)

FR-11 message (direct) and business_message map to NormalizedInbound with: external_chat_id = chat.id (+connection scope for business), sender from from (id, first/last, username), dedupe_key = {bot}:{update_id}, channel_meta = {connection_id?, telegram_date, message_id, entities}. Business messages sent by the account owner manually from the phone arrive as business messages with from = owner → mapped as author_type=agent, attributed to a reserved “Account owner (phone)” staff identity, so manual replies are captured in-thread (core stores; Doc 07 renders distinctly). FR-12 edited_message / edited_business_message → Doc 04 FR-21 revision path. deleted_business_messages → tombstones per message id list. FR-13 Media mapping: photo (largest size + generate thumb from Telegram’s), voice (ogg/opus, duration), video/video_note, document (respect Doc 04 cap pre-download via file_size when present), sticker (webp/tgs → stored; animated rendered as static thumb v1). File retrieval via getFile through the SSRF-safe proxy (Doc 04 SEC-03); Telegram’s 20 MB bot-download limit maps to a media_rejected note when exceeded (EC-07). FR-14 callback_query (direct-mode inline buttons): ACK within 1 s (answerCallbackQuery), payload routed to the owning flow (Doc 09/12) with replay protection (nonce in callback data, 15-min validity). FR-15 my_chat_member (user blocked/unblocked the bot in direct mode) → conversation annotation + telegram.bot_blocked|unblocked events; blocked maps future sends to non-retryable blocked_by_user.

### 4.5 Outbound mapping & delivery truth

FR-16 Send methods chosen by kind (sendMessage/Photo/Voice/Video/Document); business sends include business_connection_id; text > 4096 chars auto-split at word boundaries into sequential lane messages (annotated part i/n). FR-17 Delivery truth mapping (Doc 04 FR-13): Telegram provides no read/delivery receipts to bots → adapter capability delivered unsupported; UI terminal success = single check “Sent” (accepted by Telegram). The platform never fabricates double-check states. Documented in agent training copy (tooltip explains). FR-18 Error classification (adapter): 403 blocked → non-retryable blocked_by_user; business send outside window / connection paused → non-retryable window_expired / connection_paused; 429 with retry_after → retryable honoring the header (overrides backoff step); 400 entity/format errors → non-retryable invalid_content with detail surfaced to sender; 5xx/network → retryable. FR-19 Typing: staff composing (Doc 04 FR-18) → sendChatAction("typing") every 4 s while active, with business_connection_id where applicable; failures ignored silently (best-effort). FR-20 Rate limiting: token buckets per bot (default 25 msg/s global, tunable) and per chat (1 msg/s sustained, burst 3) per Telegram guidance; buckets configurable in settings within platform maximums; 429s additionally feed adaptive slowdown (bucket refill −20% for 60 s).

### 4.6 Reply-window mechanics (business mode)

FR-21 Per business conversation, maintain reply_window_expires_at = last inbound customer message time + window (window default 24 h, setting telegram.business.window_hours, min 1, max 24 — conservative default matches Telegram behavior; the sweep also *learns*: a window_expired-classified send error before the computed expiry tightens the stored expiry to now and logs calibration telemetry). FR-22 States exposed to Doc 06/07: open (>2 h left), closing (≤2 h — countdown chip, ticket list badge, optional agent notification), expired. A sweep job runs every 5 min updating states and emitting telegram.window.closing|expired (Doc 12 automations can act on these, e.g., queue-priority boost). FR-23 Expired-state composer contract (rendered by Doc 07): sending disabled with explanation; offered actions: (a) send SMS re-engagement template (Doc 14; visible only when customer has a phone on file), (b) “reply from the phone” guidance (owner manual reply resets the window when the *customer* responds — copy explains that only customer activity reopens the window), (c) wait — any new customer message reopens automatically. FR-24 A customer message arriving on an expired conversation transitions it to open, recomputes expiry, clears badges, and (if a ticket is open) notifies the assignee.

### 4.7 Direct-mode surface: commands, deep links, menus

FR-25 Commands (direct mode; registered via setMyCommands per language): /start [payload], /menu, /status (customer’s open + recent tickets summary — data via Doc 06), /human (handoff request event to Doc 09/06), /language (picker). Command set is fixed v1; labels/descriptions localized. FR-26 Deep links: t.me/{bot}?start={payload}; payload schema src-{source}[_ctx-{context}] (e.g., src-app_ctx-deposit) → stored on the conversation as acquisition metadata and passed to the AI first turn (Doc 09) for context-aware greeting; unknown payloads ignored gracefully. FR-27 Menu designer (Backoffice): per bot per language, a drag-and-drop builder of the reply-keyboard main menu (≤ 12 buttons, rows of ≤ 3) where each button maps to: KB flow / Form (Doc 12) / AI free-chat / Human handoff / URL; publish is versioned with instant rollback; direct mode renders reply keyboards + inline buttons in flows; business mode renders the same flows as numbered text menus (capability fallback, Doc 04 FR-09) — the designer previews both renderings side by side. FR-28 Bot message templates (per bot per language, i18n-managed): greeting, off-hours, handoff (“creating ticket…”), resolved + CSAT prompt, blocked-direct-mode redirect (FR-05), window guidance strings. All operator-editable (Doc 16 translation manager), variables supported ({{first_name}}, {{queue_wait}}).

### 4.8 Health & observability

FR-29 Per bot: webhook status (getWebhookInfo polled 5 min + event-driven), pending_update_count, last error, in/out per hour, failure rate by class, retry-queue depth. Per connection: state, can_reply, last inbound, sends blocked count. Health rolls up to healthy | degraded | down with defined thresholds (settings) → channel.health_changed events (Doc 15 dashboards, Doc 18 alerts). FR-30 Test tools (admin): send-test-message (to an admin-verified sandbox chat), simulate-window-expiry (staging only), re-register webhook, view last 50 raw updates (payload viewer, PII-masked per Doc 17 rules, permission telegram.health.view).

## 5. Non-functional Requirements

NFR-01 Inherits all Doc 04 budgets; adapter adds ≤ 30 ms p95 to normalization and ≤ 80 ms p95 to sends (excluding Telegram RTT). NFR-02 Webhook endpoint sustains 100 updates/s per bot burst (queue-buffered) with ACK budget intact. NFR-03 Window-state sweep drift ≤ 5 min worst case (job cadence) with event-driven immediate updates on sends/receives. NFR-04 Connection state reflects owner-side changes ≤ 5 s after the Telegram update arrives. NFR-05 Menu/template publishes propagate to next customer interaction ≤ 10 s (config cache invalidation). NFR-06 Zero Telegram tokens or file URLs in logs (Doc 02 SEC-08 scrubber patterns extended with Telegram formats).

## 6. User Stories

US-01 As an operator admin, I connect our @BrandSupport account by following a 3-step on-screen checklist and see it turn green with the account’s name shown. US-02 As an agent, when a ticket’s reply window is about to lapse, I see a countdown and get it answered in time — and when it does lapse, the composer tells me exactly what my options are. US-03 As an agent, when the boss replies to a player from the support phone at midnight, I see that reply in the thread the next morning, clearly marked. US-04 As an operator admin, when a new hire accidentally disconnects the chatbot on the phone, I’m alerted within seconds and the affected tickets are listed for rescue. US-05 As a player, I tap a “Get help” link in the app and the bot greets me already knowing I came from the deposit screen. US-06 As a product owner, I redesign the bot’s main menu for a promo in the menu designer, preview both the button version and the business text version, and publish without engineering.

## 7. Business Rules

BR-01 One Telegram support account connects to exactly one bot (Telegram constraint); one bot may serve many accounts; topology is documented in the connection registry and reports dimension on it. BR-02 The platform never initiates first contact with a Telegram user in either mode (bots cannot; business on-behalf is window-bound) — outreach is SMS’s job (Doc 14). BR-03 Owner manual replies are first-class thread content, attributed and reported (as “phone” agent), never hidden — accountability over tidiness. BR-04 The single-check “Sent” truth standard is non-negotiable (FR-17); no roadmap item may fake richer receipts. BR-05 Window durations may be tightened by operators but never extended beyond the platform-observed Telegram behavior (FR-21 calibration wins over configuration). BR-06 Direct-mode auto-redirect (business-only mode) must always include a tappable account link — dead ends are prohibited.

## 8. UX Requirements

- **Connection tab:** mode selector (segmented, with a one-paragraph explanation each + “recommended: Business” tag), connect checklist with live status per step, connections table (account name, state pill, can_reply, last inbound, sends-blocked count, actions: pause handling/disconnect), disconnect uses typed-name confirmation and lists affected open tickets first.

- **Health panel:** status banner, sparkline in/out, failure-class breakdown, retry-queue depth with drill-in, “last 50 updates” viewer, action buttons (test message, re-register webhook).

- **Composer states (contract for Doc 07):** normal; closing (amber countdown chip inline over composer: “Reply window closes in 1 h 12 m”); expired (composer disabled, options card per FR-23 with SMS button pre-filtered by phone presence); connection_paused/revoked (red card naming the account and linking admin health); blocked_by_user (guidance card per Doc 04 reason mapping).

- **Menu designer:** two-pane (builder + live dual preview: Telegram-button render / business text render), drag rows, per-language tabs with completeness badges, version history with restore, publish confirmation showing affected bots.

- Owner-phone messages render with a distinct “phone” badge and the account name.

- Empty states: no connections yet (explainer + start checklist); no updates yet in viewer (“waiting for first message” with test-message shortcut).

- Loading: checklist steps show live spinners while waiting for Telegram updates; health widgets skeleton then stream.

## 9. UI Requirements

- Components: mode segmented control, step checklist with per-step status icons, connection state pills (active=green, paused=amber, revoked=red), countdown chip (mm:ss under 10 min, h:mm above), dual-preview frame (device-styled), raw-update viewer (virtualized JSON rows, copy button, masked fields greyed).

- The countdown chip and window badges appear identically in inbox list rows (Doc 07 consumes the same component).

- Responsive: connection tab fully usable on tablet; checklist usable on phone (admins often stand next to the support phone during connect); designer requires ≥ 1024 px (fat-finger policy consistent with Doc 03).

- All states themed light/dark; reduced-motion honored on countdowns (no pulsing, static color shift).

## 10. Technical Requirements

T-01 Adapter class implements Doc 04 FR-08 exactly; capabilities: {max_text:4096, media:[photo,voice,video,document,sticker], supports_typing:true, supports_edit:limited(outbound own messages, direct mode), supports_delete:limited, supports_buttons:direct_only, reply_window: none(direct) | duration(business, per FR-21)}. T-02 Nutgram bound per-bot at runtime from the bot registry (no per-bot deploys); webhook route resolves bot by route key then verifies secret header before instantiation. T-03 Business attribution: reserved system staff identity per connection (“{account_name} (phone)”) created lazily; maps in reports as agent type owner_phone. T-04 Window store: reply_window_expires_at on conversations (Doc 01 schema) + Redis ZSET windows:{tenant} (score=expiry) for O(log n) sweep; sweep job pops due entries, emits events, updates states; event-driven updates on every inbound/outbound bypass the 5-min cadence. T-05 Callback nonces: HMAC(bot_key, flow_id, ts) truncated to fit Telegram’s 64-byte callback data; validated + single-use via Redis. T-06 Config caches: per-bot menu/templates JSON in Redis, invalidated on publish (Doc 01 T-09 conventions). T-07 File downloads: getFile → file_path → fetch via egress proxy; Telegram file URLs treated as secrets (contain token) — never persisted or logged (only our storage keys are). T-08 429 handling: retry_after parsed and honored per FR-18; adaptive bucket per FR-20 implemented as Redis Lua for atomicity. T-09 Contract tests: recorded-fixture suites for every update type (incl. business set), error classes, and the split-message path; Doc 04 conformance suite must pass unmodified.

**Business-mode message roundtrip (text diagram):**

Player → @BrandSupport ──Telegram──► bot webhook (business_message, conn=C1)
  → Doc04 pipeline → ticket/agent (Doc 06/07)
Agent reply → Doc04 outbound lane → adapter.send(+business_connection_id C1)
  → Telegram delivers AS @BrandSupport → player replies → window resets
[owner replies from phone] → business_message(from=owner) → thread as "phone" agent

## 11. Security Requirements

SEC-01 Bot tokens vault-only (Doc 01 SEC-04); the UI never displays a stored token (re-entry required to change). SEC-02 Webhook secret per bot, 256-bit, rotated on re-register; header check constant-time. SEC-03 Raw-update viewer masks: phone numbers, contact payloads, and any forwarded-content author fields; permission-gated telegram.health.view (Doc 03). SEC-04 Connection confirm step (FR-07) requires an explicit admin confirmation of the account identity — prevents an attacker connecting *their* account to the operator’s bot going unnoticed; unconfirmed connections stay pending and never route (alert after 10 min). SEC-05 Deep-link payloads are untrusted input: schema-validated, length-capped, never executed as flow ids directly (indirection table). SEC-06 Callback replay protection per T-05; expired/invalid callbacks answered with a neutral toast, event logged. SEC-07 All Telegram API egress via the allowlisted proxy (Doc 04 SEC-03) — api.telegram.org only.

## 12. Performance Requirements

- Per-bot burst 100 updates/s absorbed (queue) with webhook ACK p99 ≤ 250 ms (Doc 04 NFR-01 inherited).

- Send RTT budget: adapter overhead ≤ 80 ms p95 + Telegram RTT (monitored separately; SLO on our half only).

- Window sweep processes 50k tracked windows ≤ 10 s per run.

- Menu designer publish → cache invalidation ≤ 10 s cluster-wide.

- Health rollup computation ≤ 1 s per bot per interval.

## 13. Accessibility Requirements

Admin surfaces meet WCAG 2.2 AA: checklist steps announce status changes (role="status"); countdowns exposed as text with periodic (not per-second) SR announcements; state pills icon+text; dual-preview frames labeled (“Preview: button menu” / “Preview: text menu”); raw-update viewer keyboard navigable; designer drag operations have keyboard equivalents (move up/down buttons); typed-name confirmations properly labeled and described.

## 14. Localization Requirements

- All operator-facing strings under telegram.* i18n keys (en + am).

- Customer-facing bot content (templates FR-28, menus FR-27, commands FR-25 descriptions) is per-language operator content with completeness indicators; fallback chain per Doc 01; /language picker lists only languages with complete template sets.

- Window guidance copy avoids technical jargon in all languages (“You can reply here again after the customer writes back”).

- Numbered text menus render digits per locale conventions where applicable; RTL-ready message composition.

## 15. Edge Cases

EC-01 Owner connects the *wrong* account → confirm step (SEC-04) catches; admin rejects → connection deleted, checklist restarts. EC-02 Two admins run connect simultaneously → second sees the pending connection and joins the confirm step (single registry row per connection_id). EC-03 can_reply=false granted (read-only connection) → connection saved paused with explainer (“reply permission not granted — redo step 2”); inbound still flows. EC-04 Customer messages via both business account and direct bot (hybrid) → one customer, two conversations (FR-05); if both have open tickets, Doc 06’s one-open-per-conversation holds per conversation; inbox groups by customer with a dual-channel badge; agents are warned when replying in the *older-activity* conversation. EC-05 Username of the support account changes → connection row updates from the next update; historical attribution keeps old display with history (Doc 08 pattern). EC-06 Bot removed from Business *and* token revoked in the same minute → both paths converge on revoked + webhook failure; health shows the compound cause; rescue view lists tickets once. EC-07 20 MB Telegram bot download limit hit (customer sends 40 MB video) → media_rejected note to staff with “ask customer to compress / send via …” template shortcut; message text (caption) still stored. EC-08 retry_after: 300 on a business send with 20 min window left → honored wait still inside window → proceeds; if wait ≥ remaining window → immediate window_expired classification (no doomed retries). EC-09 Split message (FR-16) partial failure (part 2 of 3 fails final) → lane holds per Doc 04 FR-15; UI shows the composite with the failed part marked; retry resumes from the failed part. EC-10 Sticker/animated content the platform can’t thumbnail → generic sticker placeholder; never blocks the message. EC-11 Duplicate business_connection updates (Telegram redelivery) → upsert by connection_id, idempotent. EC-12 Customer forwards a message from a third party → forwarded-origin metadata stored but masked in viewers (SEC-03); staff see “forwarded message” annotation. EC-13 Clock: Telegram date seconds vs. our receipt (Doc 04 BR-04) → receipt order wins; telegram_date preserved in channel_meta. EC-14 Operator sets window_hours=24 but Telegram behaves as 20 h for an account → calibration (FR-21) tightens stored expiry on first observed early failure; telemetry aggregates per connection for support diagnostics.

## 16. Failure Scenarios

FS-01 Telegram API outage → sends queue (lanes intact), health down, banner in inbox header (Doc 07), automatic drain on recovery; windows are *not* expired by our sweep during a verified outage window (sweep pauses expiry transitions while health=down to avoid false “expired” states, resumes with recompute). FS-02 Webhook silently broken (Telegram can’t reach us) → detected via pending_update_count growth + no-inbound heartbeat (alert if a normally-active bot is silent > threshold); one-click re-register; missed updates are redelivered by Telegram. FS-03 Vault unavailable at send time → sends fail retryable credentials_unavailable, alert, lanes hold; no token fallback storage exists by design. FS-04 Connection flaps (active↔paused repeatedly, unstable owner) → flap damping: > 3 transitions/hour collapses notifications into one digest alert; states still tracked truthfully. FS-05 Redis ZSET loss (window index) → rebuild job from conversations table (source of truth is the column); during rebuild, event-driven updates still function.

## 17. Success Scenarios

SS-01 An operator connects two support accounts to one bot, routes VIPs to account B (Doc 06 rule on connection), and sees per-account report lines — all without engineering. SS-02 Match-night burst: 100 updates/s for 5 min; ACK budget held; agents experience live flow; zero drops (queue metrics as evidence). SS-03 A window expires mid-dispute; the agent sends the SMS re-engagement template; the player replies on Telegram 3 h later; the window reopens, the assignee is notified, resolution proceeds — full sequence visible on the ticket timeline. SS-04 The Doc 04 conformance suite passes unmodified against this adapter (architecture proof).

## 18. Acceptance Criteria

AC-01 Fixture suite: every update type in FR-01’s allowed list maps to the exact NormalizedInbound documented, including owner-phone attribution and deletion tombstones. AC-02 Mode matrix: direct/business/hybrid × (direct inbound, business inbound) produce the specified handling incl. the misconfiguration alert and redirect template. AC-03 Connection lifecycle: scripted BotFather + phone procedure on a staging account walks active→paused→active→revoked with states, events, alerts, and rescue-view contents verified at each step. AC-04 Window mechanics: simulated timelines verify open→closing→expired transitions, composer contract states, SMS-fallback gating on phone presence, reopen-on-inbound, and calibration tightening (EC-14). AC-05 Error classification table: each Telegram error fixture (403 blocked, window, paused, 429+retry_after, 400 content, 5xx) lands in the Doc 04 state and reason specified, including EC-08’s doomed-retry avoidance. AC-06 Rate limits: bucket conformance under synthetic load; 429 adaptive slowdown observable; per-chat FIFO order preserved. AC-07 Truthful receipts: UI never renders a delivered state for Telegram (DOM assertion across all message states). AC-08 Menu designer: publish/rollback round-trip; dual previews match actual renders on a staging bot (screenshot comparison); ≤ 10 s propagation. AC-09 Security: token absent from all logs/UI; unconfirmed-connection routing block; callback replay rejected; update viewer masking verified.

## 19. Future Expansion

- Group-chat support (moderated communities) — adapter capability extension; core unaffected.

- Telegram Stars payments for premium support tiers — separate module, hooks reserved on conversation metadata.

- Mini App (web app inside Telegram) as a richer direct-mode surface for forms — Doc 12 forms already renderable.

- Outbound-initiated contact if/when Telegram broadens business messaging — window model already parameterized.

- Animated sticker rendering (lottie) in thread view.

## 20. Risks

R-01 *Telegram Business behavior changes* (High likelihood — the feature is young) → adapter isolation (Doc 04), calibration telemetry (FR-21), and a quarterly “Bot API changelog review” task in Doc 18’s runbook calendar. R-02 *Operator phones as infrastructure* (High/Medium) — the support account lives on a phone someone can drop in a river → recommendation: accounts on dedicated devices with cloud password set, documented in operator onboarding; connection loss is survivable (FR-10 rescue) but disruptive. R-03 *Window expiry causing missed SLAs* (Medium/Medium) → closing-state notifications, automation hooks (FR-22), SMS fallback, and SLA pause-on-unreachable option (Doc 12 policy flag). R-04 *Hybrid-mode confusion for agents* (Medium/Low) → customer-level grouping + reply-target warning (EC-04); training copy. R-05 *Bot API rate ceilings during viral bursts* (Low/Medium) → buckets + adaptive slowdown degrade gracefully; bursts queue rather than fail.

## 21. Recommendations

- Stand up a permanent staging bot + staging Business account on a dedicated device; AC-03/AC-08 must run against real Telegram, not only fixtures.

- Ship business mode and direct mode together even though business is primary — direct mode is your integration surface for the Android app deep links from day one.

- Write the operator runbook page “Your support phone is production infrastructure” during this module’s build, not after the first revoked-connection incident.

- Keep the calibration telemetry (FR-21) visible to yourselves in Doc 15 — it is your early-warning radar for Telegram-side behavior changes.

- Resist adding channel-specific fields to core models when implementing; everything Telegram-shaped fits in channel_meta — future adapters will thank you.

*End of Document 05. Next in series: Document 06 — Ticketing System.*