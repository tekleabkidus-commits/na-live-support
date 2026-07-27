Table of Contents

# Document 13 — Web Chat Widget

**Product:** AI-First Support Platform for Betting Companies **Document series:** 13 of 19 · Standalone enterprise specification **Audience:** Frontend engineers (widget + admin), backend engineers, UX/UI designers, QA, DevOps, operator admins **Depends on:** Doc 01 (tenancy, error format), Doc 02 (visitor tokens FR-21/22, CORS/frame posture), Doc 03 (webchat.* permissions), Doc 04 (adapter contract, message pipeline, media), Doc 08 (visitor identity, resolver), Doc 09 (chatbot pipeline, streaming), Doc 10 (Help tab contract FR-18), Doc 11 (wait estimates), Doc 12 (form renders, offline form, CSAT star row). Consumed by: Doc 07 (webchat conversations in the inbox), Doc 15 (live-chat reports). **Stack context:** Standalone Preact bundle (<50 KB core gz) in an isolated iframe, loader snippet, REST + Reverb (WebSocket with polling fallback), CDN-served

## 1. Executive Summary

This document specifies the website live-chat widget end to end: the embeddable loader snippet and its iframe isolation model, the three-tab Intercom-style surface (**Home** — greeting, shortcuts, expected reply time; **Messages** — conversation history and the live thread; **Help** — Doc 10’s public KB browse/search), the visitor identity and session model (anonymous persistent visitors, optional pre-chat form, optional signed-identity snippet for logged-in sites), the AI-first conversation flow with human handoff and queue position, proactive triggers, business-hours and offline-form behavior, file upload and voice (flagged), typing/read indicators, sound and browser notifications, dark/light/auto theming from the operator’s Widget Designer (full admin spec: appearance, content, behavior, install, health tabs), the domain allowlist and CSP posture, deployment topology (versioned CDN bundles with staged rollout), accessibility to WCAG 2.2 AA inside the iframe, localization, page-context capture, and every degradation path from socket loss to complete API outage. The widget implements the Doc 04 Channel Adapter contract server-side (webchat adapter), so delivery states, media safety, ordering, and events are inherited — this document specifies everything visitor-facing and operator-configurable on top.

## 2. Purpose

- Specify a widget an operator can install with one snippet on any site they control (including the Doc-series context: a standalone support subdomain and the Android app’s WebView) and fully brand/configure from the Backoffice.

- Contract the visitor experience precisely — states, timing, fallbacks — so the widget team, the adapter team, and QA build against one truth.

- Keep the host page safe (no style/script bleed, no data access beyond the declared context capture) and the widget safe from hostile hosts (allowlist, frame ancestry).

## 3. Scope

**In scope:** loader + iframe architecture; bundle/versioning/rollout; visitor session & identity (incl. signed identity); the three tabs and all conversation UX; AI/human flow states; proactive triggers; pre-chat & offline forms (Doc 12 renders); wait/queue display; uploads & voice; notifications & sounds; theming & the Widget Designer admin; install & health tabs; domain allowlist & security posture; page-context capture; realtime & fallback transport; offline/reconnect; multi-tab; accessibility; localization; events & report feeds. **Out of scope:** the server-side message pipeline (Doc 04 — the webchat adapter conforms to it); chatbot logic (Doc 09); KB content (Doc 10); form definitions (Doc 12); CSAT policy (Doc 12 — the star row renders here); inbox rendering of webchat conversations (Doc 07).

## 4. Functional Requirements

### 4.1 Loader & isolation

FR-01 Install = one async script tag with the widget key: <script async src="https://cdn.{platform}/w/{widget_key}.js"></script>; the loader (< 5 KB) validates origin against the allowlist, then mounts a positioned iframe (launcher) and a second expandable iframe (panel) — all UI inside iframes (zero host-DOM styling; host page CSS cannot affect the widget and vice versa). FR-02 Loader API on the host page (window.PlatformChat): open(), close(), openHelp(articleSlug?), identify(signedPayload) (FR-07), setLanguage(code), on(event, cb) for open|close|message|handoff (host-page analytics hooks; payloads contain no message content — event names only), capture(context{}) (merges into page-context per FR-24 allowlist). All methods queue until ready. FR-03 Frame ancestry: the panel iframe sets frame-ancestors per widget config (the allowlisted domains); the loader additionally verifies location.ancestorOrigins/referrer chain (Doc 02 EC-08 — both origin and ancestor validated). FR-04 Bundle versioning: immutable versioned bundles on CDN; the widget-key loader resolves the tenant’s pinned channel (stable | early) with staged platform rollout (5% → 50% → 100%); a broken-rollout kill reverts the pointer platform-wide ≤ 5 min.

### 4.2 Visitor session & identity

FR-05 First load: POST /widget/v1/session per Doc 02 FR-21 → visitor token + visitor_id; the Doc 08 resolver creates the shell customer on first *message* (not first load — no ghost profiles from bounces). FR-06 Pre-chat form (config: off | optional | required): a Doc 12 form (seeded minimal: name + phone) rendered before the first message when required; values land as staff-collected customer fields + identities (phone via Doc 08 FR-09 semantics — duplicate-phone conflicts silently attach nothing and flag for staff instead of blocking the visitor). FR-07 Signed identity (optional, for operators with a logged-in site): the host page calls identify({payload, sig}) where sig = HMAC-SHA256 of the payload (user_ref, name?, phone?, email?) with a per-widget secret; the server verifies and links/creates the customer with source=api provenance; invalid signatures are ignored with a health-tab counter (never trusted, never erroring the visitor). Signed identity coexists with the no-verification stance: provenance says “operator site asserted”, still not platform-verified language (Doc 08 BR-01 wording rules apply). FR-08 Continuity: token per Doc 02 (sliding 30 d); conversation history restores across pages/visits; visitor cleared cookies = new visitor per Doc 02 EC-09 (staff can merge later, Doc 08).

### 4.3 Tabs & conversation UX

FR-09 **Home:** greeting block (persona avatar/name per Doc 09 config, welcome text per language), expected reply time (Doc 11 FR-13 bands; off-hours shows the opens-at band), shortcut buttons (config: KB article, start-form, start-chat-with-topic, URL), recent-conversation resume card when history exists. Tabs themselves are config-toggleable (Home/Help can be off → Messages-only mode). FR-10 **Messages:** conversation list (usually one; linked-work splits per Doc 06 render as one thread — the visitor sees a single continuous conversation, ticket boundaries are staff-side only), the live thread per Doc 04 §8/9 contracts (bubbles, delivery glyphs where truthful, media lightbox, tombstones), typing indicators both ways (visitor→staff via events; staff/AI→visitor), day separators, unread badge on the launcher. FR-11 **Help:** Doc 10 FR-18’s contract verbatim (browse, search-as-you-type, article view with 👍/👎, “still need help → start chat” persistent). FR-12 Conversation flow: message → Doc 04 pipeline → Doc 09 chatbot (streaming rendered token-batched) → resolve (CSAT star row per Doc 12 FR-25) or handoff → **queue state**: “You’re #3 in line · ~5 min” (position from Doc 11 queue data + band; position updates live; band only when position unknown) → agent joins (system line “Sara joined”, staff avatar/name per privacy setting widget.agent_identity: first_name|alias|team_name) → live human chat → resolution → CSAT. FR-13 Handoff continuity: the visitor never re-explains — the Doc 09 packet carries everything; the widget shows “Connecting you to a specialist — they can see your conversation.” FR-14 Proactive triggers (config list): {condition: time-on-page ≥ N s | page-URL matches | exit-intent (desktop) | custom via capture() key} → action: open with a specific message or shortcut set; frequency guard (max 1 proactive per visitor per widget.proactive.cooldown_hours default 24; never re-fires after dismissal on the same page). FR-15 Business hours (Doc 12 calendars): in-hours = normal; out-of-hours with the chatbot on = AI serves with the off-hours handoff behavior (“the team returns at 08:00 — I’ve saved everything”); chatbot off = **offline form** (Doc 12 seeded: name+phone+issue) creating an offline-tagged ticket (Doc 06 FR-14) with confirmation copy; if the visitor left a phone, Doc 14’s ticket-created SMS applies per template settings. FR-16 Uploads: drag/paste/picker per Doc 04 caps with progress + cancel; voice recording behind the platform flag (default off per the series stance); paste-screenshot is first-class (deposit receipts). FR-17 Notifications: in-page sound (default on, config) + title-bar unread counter + browser Notification (permission requested only after the visitor sends their first message — never on load) for replies while the tab is unfocused; all suppressed while the thread is visible and focused. FR-18 Multi-tab: BroadcastChannel leader election (Doc 07 FR-04 pattern) — one socket, one sound, synchronized unread; the panel state (open/closed) is per-tab by design.

### 4.4 Widget Designer (Backoffice admin, permission webchat.widgets.configure)

FR-19 Multiple widgets per tenant (site, subdomain, app-WebView each get their own key/config). Tabs: - **Appearance:** brand colors (Doc 16 contrast validation), launcher icon (preset set + upload), position (corner + offsets), dark/light/auto (auto = prefers-color-scheme), font (curated incl. Ethiopic), avatar, live preview pane (desktop + mobile frames). - **Content:** welcome per language, shortcut buttons builder, tab toggles, agent-identity mode (FR-12), pre-chat form picker (Doc 12), offline form picker. - **Behavior:** chatbot on/off (Doc 09 kill-switch integration shown read-only here with a link), proactive triggers builder (FR-14), business-hours source (tenant | department override), file upload toggle, voice flag (visible only when platform flag on), sound defaults, CSAT on/off (policy note that Doc 12 governs suppression). - **Install:** the snippet with copy button, the signed-identity secret (rotate flow: new secret + overlap window per Doc 02 FR-19 pattern), domain allowlist editor (exact hosts + wildcard subdomain), framework notes (SPA route-change handling, CSP copy-paste header per Doc 02 T-07). - **Health:** loads, opens, messages, error rate, invalid-signature counter (FR-07), blocked-origin attempts (with origins listed), active-version indicator (FR-04), test-mode toggle (sandbox conversations excluded from reports — Doc 05 FR-01 test-mode family). FR-20 Config publishes are versioned with instant rollback (platform publish pattern); propagation ≤ 60 s (config polled by open widgets every 5 min + pushed on the socket).

### 4.5 Transport, offline, context

FR-21 Transport: WebSocket (Reverb) primary; automatic fallback to 5 s polling on socket failure (feature-identical minus typing granularity); transport state never surfaced to visitors beyond honest delivery glyphs. FR-22 Visitor offline/reconnect: queued sends with visible “sending…” chips, flush in order with idempotency keys (Doc 04 semantics); a full offline state shows a soft banner (“You’re offline — messages will send when you reconnect”); history cached in-frame memory + IndexedDB (content-lite: last 50 messages, purged on logout/clear per Doc 07 SEC-03 family rules — visitor-side storage is the visitor’s own data). FR-23 SPA support: the loader survives client-side route changes (history API hooks) re-evaluating proactive URL triggers; no re-init required. FR-24 Page-context capture (attached to the conversation for staff, Doc 07 Customer/Ticket panels): current URL, referrer, page title, device/browser/OS (UA-CH), viewport, language, timezone offset, and operator-supplied capture() keys from a config allowlist (undeclared keys dropped) — **no cookies, no localStorage reads, no form scraping, ever** (structural: the widget code has no host-DOM access from the iframe; capture() is the only inbound channel and it is allowlist-filtered server-side).

## 5. Non-functional Requirements

NFR-01 Loader ≤ 5 KB gz; core panel bundle ≤ 50 KB gz initial (Help tab and forms lazy-chunked); launcher paints ≤ 300 ms after loader executes on 3G-fast; zero layout shift on the host page (fixed-position iframes only). NFR-02 Message send→visible-to-agent inherits Doc 04 NFR-01 budgets; chatbot first token per Doc 09 NFR-01 rendered ≤ +200 ms relay overhead. NFR-03 Widget runtime CPU idle ≤ 1%; memory ≤ 30 MB; no polling when socket healthy. NFR-04 Config propagation ≤ 60 s (FR-20); allowlist changes ≤ 60 s (a removed domain’s widgets stop on next config poll or reload). NFR-05 The widget functions on the support matrix: last 2 versions of Chrome/Firefox/Safari/Edge + Android WebView ≥ 90 + iOS WKWebView ≥ 14; graceful no-mount (silent, logged) below matrix. NFR-06 Availability posture: widget failures must never break the host page (loader wrapped, global error boundary, CSP-safe) — a dead widget is invisible, not a broken site.

## 6. User Stories

US-01 As a player on the betting site, help is one tap away, answers stream instantly in my language, and if I need a person I can see I’m #2 in line. US-02 As a player who navigated three pages mid-chat, my conversation followed me without a flicker. US-03 As a player at 3 AM with the bot off, I leave my number and get an SMS when the team picks it up in the morning. US-04 As an operator admin, I restyle the widget to match a promo, preview both themes on both device frames, and publish — live in a minute, rollback in one click. US-05 As an operator admin, I see blocked-origin attempts in Health and catch a partner site embedding our widget without permission. US-06 As the operator’s developer, the SPA notes and CSP snippet in the Install tab made integration a 20-minute task. US-07 As an agent, webchat conversations arrive in my same inbox with the page the visitor was on, their device, and everything the bot already covered.

## 7. Business Rules

BR-01 Host-page safety is absolute (NFR-06 + FR-24’s structural no-DOM-access): the widget can never be the reason an operator’s site broke or leaked — this outranks any feature request for deeper page integration. BR-02 Honest presence: the widget never fakes agent availability (“we typically reply in…” bands are Doc 11 data; no decorative “online” dots when nobody is routable). BR-03 Notification permission is earned (FR-17): requested only after engagement, never on load — browser-permission spam is brand damage. BR-04 The visitor’s thread is one continuous conversation (FR-10): staff-side ticket mechanics never leak as visible resets or “this chat has ended” walls (resolution shows softly with CSAT; the thread remains). BR-05 Signed identity asserts, never verifies (FR-07): all Doc 08 BR-01 language rules apply; the widget shows the visitor their own asserted name for continuity, staff surfaces show provenance. BR-06 Proactive is polite (FR-14 guards): one shot, dismissal respected, cooldown enforced — engagement tricks that annoy players are structurally limited. BR-07 Test mode traffic never pollutes reports (FR-19 Health) — mirrors the Doc 05 sandbox rule.

## 8. UX Requirements

- **Launcher:** brand-colored bubble with icon; unread badge; open/close morph (150 ms); configurable offset respects safe areas (iOS notches); an optional attention nudge (single subtle bounce, config, max once/session, reduced-motion → none).

- **Panel:** 380×640 desktop floating with shadow; full-screen sheet < 480 px viewport; header (avatar/persona or agent identity per state, title, minimize/close, overflow menu: sound toggle, language switcher, download transcript [emails or downloads per config], privacy link); tab bar bottom (Home/Messages/Help per config).

- **Thread states:** AI streaming (token-batched with caret per Doc 09 §9); “AI → human” transition system lines; queue card (position live-updating with a gentle count-down animation, band fallback); agent-joined line with identity per config; resolution + CSAT star row inline (Doc 12 FR-25 render) then the thread simply continues if the visitor types again (BR-04).

- **Composer:** single-line growing to 5 lines; attach; emoji; send on Enter (Shift+Enter newline); disabled states with reasons (blocked visitor per Doc 04 — neutral copy “messaging unavailable”; offline banner per FR-22).

- **Offline form / pre-chat:** Doc 12 widget renders inside the panel with the widget theme; success confirmations with expectation copy.

- **Help tab:** per Doc 10 FR-18; article view uses reader typography with the theme; feedback row; the persistent start-chat footer.

- **Empty/loading/error:** Home skeleton (greeting instant from cached config, wait band streams in); Messages empty = friendly prompt + shortcuts; Help offline = “articles unavailable — chat still works”; hard API outage = launcher stays, panel shows a minimal retry card (the last-resort state, NFR-06).

- **First-open micro-onboarding:** none beyond the greeting (widgets need zero tutorials — a deliberate anti-pattern rejection).

## 9. UI Requirements

- All tokens from the widget theme (operator colors validated for AA at config time; the platform refuses to publish failing combinations with suggested corrections — Doc 16 validator reuse).

- Dark/light/auto with genuine dark surfaces (not inverted brand colors); media lightbox and forms themed consistently.

- Typography: curated set incl. Noto Sans Ethiopic; base 15 px in-panel; visitor and staff/AI bubbles per Doc 04 variant set with the AI accent kept subtle on the visitor side (persona-colored, marked “AI assistant” textually per Doc 09 disclosure).

- Delivery glyphs only where truthful (Doc 04): webchat supports delivered (socket ack) — double-check appears here even though Telegram never shows it; the per-channel truth rule renders per capability.

- Iframe rendering: crisp on zoom/high-DPI; the panel respects browser text-zoom (rem-based).

- Reduced-motion: streaming renders in chunks, no bounces, no count-down animation.

## 10. Technical Requirements

T-01 Architecture: loader (vanilla, no deps) + panel app (Preact + a minimal store); build produces immutable hashed bundles + the per-widget-key loader shim resolving config + version channel (FR-04); CDN with long-cache immutables and 60 s-cache shims. T-02 The webchat server adapter implements Doc 04 FR-08: capabilities {max_text: 4000, media: [photo, video, document, voice(flagged)], supports_typing: true, supports_edit: false, supports_delete: false, supports_buttons: true, reply_window: none}; delivery delivered on socket ack from the visitor client (truthful double-check, §9). T-03 API surface (visitor-token scoped, Doc 02 FR-21): POST /widget/v1/session, GET /widget/v1/config, POST /widget/v1/messages (idempotency-keyed), GET /widget/v1/messages?cursor, POST /widget/v1/typing, POST /widget/v1/forms/{id}/steps, GET /widget/v1/kb/* (Doc 10 public), POST /widget/v1/csat/{survey}, POST /widget/v1/context (FR-24 allowlist-filtered), POST /widget/v1/identify (FR-07). All CORS-scoped per widget allowlist; rate-limited per visitor token (Doc 02 FR-25 family). T-04 Realtime: visitor subscribes to their conversation channel via a widget-scoped Reverb auth (Doc 02 guard); polling fallback shares the same message-cursor endpoint (T-03) — one sync model (Doc 07 T-07 philosophy). T-05 Queue position: Doc 11 exposes position-in-queue for a waiting ticket (extension to its estimator endpoint, added to Doc 11’s change log); pushed on routing events, polled as fallback. T-06 Proactive engine: config rules evaluated in-loader (URL/time) and in-panel (capture() keys); firing calls open() with the configured payload; guards persisted in IndexedDB per visitor. T-07 SPA hooks: history pushState/replaceState wrapping + popstate listener in the loader (namespaced, removable, no monkey-patch leaks — implementation-reviewed pattern). T-08 Health metrics: loader beacons (load, blocked-origin, mount-fail) via a 1×1-cost POST /widget/v1/beacon (no third-party analytics); panel errors to the same path with sampled stacks. T-09 Contract tests: adapter conformance (Doc 04 AC-01) for webchat; visitor-API scope fixtures (a visitor token can touch exactly its own conversation — Doc 03-style matrix); transport-fallback drill (kill socket mid-chat → polling seamless); host-page-safety suite (widget on a hostile fixture page with aggressive CSS/CSP/JS — zero interference both directions); the browser/WebView matrix in CI (Playwright + device lab for WebViews).

**Load ****&**** message flow (text diagram):**

host page → loader (origin+ancestor check) → mount launcher iframe
open → panel iframe → session (visitor token) → config → Home (cached greeting + live wait band)
visitor message → POST /messages (idempotent) → Doc 04 pipeline → Doc 09 turn
   → stream → panel render | handoff → queue card (position via Doc 11) → agent (Doc 07)
socket lost → polling (5 s) on the same cursor endpoint → socket resume → converge

## 11. Security Requirements

SEC-01 Iframe isolation + no host-DOM access (FR-24) is the security model’s foundation; the loader’s only host-page footprint is the two iframes and the namespaced API/history hooks (T-07), all reviewed as a standing checklist item. SEC-02 Origin enforcement in depth: loader check (client), CORS (browser), server-side origin/ancestor validation on session issue (Doc 02 FR-21/EC-08) — three independent layers; blocked attempts logged with origins (FR-19 Health). SEC-03 The signed-identity secret is vault-stored, shown once, rotatable with overlap (FR §4.4 Install); signature validation is constant-time; replay bounded by a payload timestamp window (±5 min). SEC-04 Visitor content is hostile input on staff surfaces (Doc 04 SEC-01) and staff/AI content renders inert in the widget equally; KB article HTML is the Doc 10 sanitized render. SEC-05 IndexedDB cache holds message bodies for continuity — documented as visitor-device data (their own conversation); a config flag widget.cache.content: off exists for operators with kiosk/shared-device concerns (then history is server-fetch only). SEC-06 Transcript download/email (overflow menu) is the visitor’s own conversation only, rate-limited, notes structurally absent (Doc 04 BR-01 — notes never reach the adapter, so they cannot appear here). SEC-07 CSP guidance ships in Install (Doc 02 T-07 alignment); the widget itself operates under a strict own-CSP (no eval, no inline, self+platform origins only).

## 12. Performance Requirements

- Budgets NFR-01–03 verified in CI (Lighthouse + custom loader-timing harness on throttled profiles) and on the WebView lab.

- Thread virtualization beyond 200 messages (Doc 04 patterns); Help tab search debounced 150 ms with ≤ 200 ms p95 responses (Doc 10 NFR-03).

- Polling fallback load: 5 s interval × concurrent fallback visitors bounded by the standard rate limits; the platform sustains 10k concurrent widget sessions (Doc 01 capacity) with ≤ 20% on polling in degraded scenarios.

- Config poll (5 min) + beacon traffic ≤ 1 req/min/visitor amortized.

## 13. Accessibility Requirements

WCAG 2.2 AA inside the iframes: the launcher is a labeled button with unread state announced; the panel is a dialog with focus trap and Esc-close (returning focus to the launcher); tabs are a tablist; the thread is a polite log region (Doc 04 §13 rules — author + kind announcements); queue-position updates announced at position changes only (not every recompute); the star row is a radiogroup; forms per Doc 12 §13; all operator-configurable colors pass the config-time validator (§9); keyboard-only completeness for every flow incl. uploads (input fallback) and the lightbox; screen-reader tested on NVDA + VoiceOver (iOS) as part of the matrix.

## 14. Localization Requirements

Widget strings under widget.* (en + am shipped; operator-overridable per Doc 16); language resolution: explicit visitor choice (switcher, persisted) → setLanguage() from the host → browser language → widget default; RTL-ready layout; the greeting/shortcuts/offline copy are per-language operator content with completeness gates (a language can’t be offered incomplete); Help follows Doc 10’s fallback chain with the article-language behavior (BR-05 Doc 10); dates/times localized in the visitor’s locale; the transcript export localizes its frame text but preserves message content verbatim.

## 15. Edge Cases

EC-01 Two widgets accidentally installed on one page (double snippet) → the loader detects an existing mount (namespaced global) and no-ops the second with a console warning + beacon. EC-02 The host site’s CSP blocks the iframe/CDN → loader fails silently (NFR-06), beacons mount-fail:csp, Health surfaces it with the copy-paste fix (Install tab). EC-03 Visitor opens the widget in 6 tabs → leader election (FR-18); the leader’s socket serves all; leader-tab close hands off ≤ 2 s. EC-04 Signed identity for user_ref X arrives on a visitor already linked to customer Y (site login switch) → the conversation continues; the identity event flags the switch for staff (system note “site identity changed”), no automatic re-linking mid-conversation (Doc 08 BR-02 conservatism); new conversations start under X’s customer. EC-05 A proactive trigger fires while the visitor is typing into a page form → open() defers until the page input loses focus (host-page politeness heuristic via document.activeElement check from the loader’s minimal rights — no key reading, just focus presence). EC-06 Queue position regressed (higher-priority ticket boosted past, Doc 11) → the card never shows the number going up; it holds the band (“~5 min”) until the position improves (honesty via bands beats demoralizing arithmetic; documented choice). EC-07 Business hours flip while the visitor types (17:59 → 18:00) → in-flight conversation unaffected (Doc 11 EC-09 no-cliff rule); only *new* sessions get off-hours behavior. EC-08 Upload of a 200 MB video → client-side cap check before any bytes move (config from Doc 04 caps), friendly limit message. EC-09 The WebView host (Android app) has no Notification API → notification code no-ops cleanly; sounds respect the app’s audio focus; the matrix (NFR-05) pins these behaviors. EC-10 Visitor pastes a huge text (> max_text) → composer soft-splits with a “sent as 2 messages” note (adapter split per Doc 05 FR-16 family, capability-driven). EC-11 Config rollback while panels are open → next config poll/push applies; mid-conversation theme changes apply gracefully (CSS-var swap, no remount). EC-12 Allowlist wildcard *.brand.com and a hostile evil-brand.com → exact-suffix matching with the dot boundary (.brand.com) — fixture-pinned; bare-domain and www both require explicit entries or the wildcard.

## 16. Failure Scenarios

FS-01 Reverb down → polling fallback (FR-21) engages per session ≤ 10 s; typing granularity degrades; recovery auto-resumes sockets; visitors notice nothing but slightly slower updates. FS-02 Full API outage → panel shows the minimal retry card; the loader keeps the launcher (a later open may succeed); queued sends persist in-frame and flush on recovery (bounded to the session). FS-03 CDN bundle regression (bad rollout) → FR-04 staged rollout limits blast radius; the kill-pointer reverts ≤ 5 min; beacons make the regression visible within the first stage. FS-04 Doc 09 chatbot down (its FS-01) → the widget seamlessly shows the straight-to-queue experience (handoff template + queue card) — the visitor never sees an AI error. FS-05 Doc 10 Help index down → Help tab’s degraded state (§8); Messages unaffected (independent failure domains, Doc 10 FS-02 alignment).

## 17. Success Scenarios

SS-01 An operator installs the widget on their standalone support subdomain and inside the Android app’s WebView in one afternoon using only the Install tab — both surfaces themed, allowlisted, and reporting separately. SS-02 A deposit-page proactive trigger (“stuck? I can check deposit timing”) lifts self-serve resolutions measurably (Doc 15 deflection report) with zero complaint-signal about nagging (BR-06 guards holding). SS-03 The hostile-host-page suite (T-09) passes against a fixture with * { all: unset !important }, aggressive CSP, and prototype-tampering JS — the widget renders perfectly and touches nothing. SS-04 A socket-kill drill during 500 concurrent staging chats: all sessions on polling ≤ 10 s, zero message loss, converged on recovery (Doc 04 guarantees visible end-to-end).

## 18. Acceptance Criteria

AC-01 Adapter conformance: the webchat adapter passes Doc 04’s suite unmodified; truthful-delivery rendering (double-check on socket ack, none faked elsewhere) DOM-asserted. AC-02 Isolation: the hostile-host suite (SS-03) green; loader footprint audit (globals, listeners, DOM) matches the declared inventory exactly. AC-03 Origin defense-in-depth: each of the three layers (SEC-02) independently blocks in fixtures (disable two, the third holds); EC-12 wildcard boundaries pinned. AC-04 Visitor-scope matrix: a visitor token reaches exactly its own conversation/survey/context endpoints; cross-visitor attempts 404 (Doc 01 SEC-06 style). AC-05 Flow states: scripted journeys (AI-resolve+CSAT, handoff+queue+agent, off-hours form, blocked visitor) render every §8 state; queue-position honesty (EC-06) verified. AC-06 Proactive guards: cooldown, dismissal-respect, focus-politeness (EC-05) fixtures. AC-07 Signed identity: valid/invalid/replayed/switched (EC-04) fixtures with provenance labels per Doc 08 BR-01. AC-08 Transport: FR-21 fallback drill (SS-04) automated; multi-tab leader handoff ≤ 2 s. AC-09 Performance: NFR-01 budgets in CI on throttled profiles + the WebView lab; zero host-page layout shift measured. AC-10 A11y & i18n: WCAG audit on the full matrix incl. screen readers; language-resolution matrix incl. setLanguage() and completeness gates.

## 19. Future Expansion

- Customer portal (“my tickets” view for visitors) — Doc 06 §19’s public status mapping is the prerequisite.

- Co-browsing/screen-annotation (heavy consent design needed — explicitly deferred).

- Web push (service-worker) for return notifications beyond open tabs.

- Widget-embedded article deflection analytics deep-dive (which article the visitor read before *not* chatting — extends FR-24’s context allowlist).

- Additional surfaces on the same adapter: an in-app native SDK for the Android app (replacing the WebView embed) — the visitor API (T-03) is designed to be SDK-consumable as-is.

## 20. Risks

R-01 Host-page conflicts across the wild web (High likelihood) → iframe isolation, the hostile-host suite, silent-fail posture (NFR-06), and beacon-driven diagnosis; the widget must earn a “never broke anyone’s site” record. R-02 The white-label site constraint (the operator’s main betting site is provider-hosted — Doc-series context) limiting installs (High/Medium) → the support-subdomain + app-WebView strategy (SS-01) is the designed path; the head-injection request to the provider remains a documented option; risk owned at the business level, the widget is ready either way. R-03 WebView fragmentation (Medium/Medium) → pinned matrix (NFR-05), device lab in CI, graceful no-mount below matrix. R-04 Proactive misuse pressure (“fire it everywhere”) (Medium/Low) → BR-06’s structural guards + the deflection-vs-annoyance reporting in Doc 15 keep the argument evidence-based. R-05 Bundle growth (High/Medium) → the 50 KB budget in CI (AC-09), lazy chunks, quarterly review (Doc 07 R-03 discipline shared).

## 21. Recommendations

- Build the adapter + visitor API against Doc 04’s fake-adapter learnings first, then the panel — the widget UI is the easy half; the guarantees underneath are the product.

- Stand up the hostile-host fixture page in week one and run it on every PR; host-page safety is a reputation you cannot patch retroactively.

- Launch on the support subdomain + Android WebView (SS-01 path) without waiting on the white-label provider conversation; the widget shipping shouldn’t block on a third party’s head-injection policy.

- Keep the loader boring forever: no features migrate into it beyond mounting, origin checks, and hooks — every loader byte and capability is host-page risk surface.

- Treat EC-06’s queue-position honesty and BR-03’s earned-permission rule as brand commitments in operator onboarding — the widget is the operator’s storefront; polite defaults are why visitors will actually use it.

*End of Document 13. Next in series: Document 14 — SMS Notification Module.*