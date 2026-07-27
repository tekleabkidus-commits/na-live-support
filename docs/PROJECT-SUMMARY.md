# PROJECT SUMMARY — AI-First Support Platform for Betting Companies
_Handoff document · Last updated: July 27, 2026_

---

## 1. WHO / CONTEXT

- Operator runs a **betting website in Ethiopia** on a **white-label/turnkey platform** — controls the domain only, **no source code, no backend/API access** (deliberately decided NOT to request API access from the provider).
- Building during the Ethiopian betting shutdown to launch ahead of competitors when betting is re-allowed.
- Related projects: an Android app for the betting site (WebView-based, no backend access), and a search for a P2P cash-agent payment gateway for Africa.
- The owner is **not a programmer** — the plan is AI-driven development (Claude Design + Claude Code) with the owner as project manager/judge.

## 2. WHAT WE ARE BUILDING

An **AI-first customer support platform for betting companies** — "Zendesk + Intercom + AI Copilot, purpose-built for betting, Telegram-first." Channels are ONLY:
1. **Telegram** (primary)
2. **Website live chat widget**
3. **SMS** (notification-only, never a conversation channel)

Agents never open Telegram; everything happens in one unified platform. Fully white-label, multi-language (English + Amharic first), mobile-first, configurable entirely from the Backoffice with zero code changes.

## 3. KEY DECISIONS AND WHY (the decision log)

| Decision | Why |
|---|---|
| **Telegram model: ACCOUNT-FIRST via official Business connected bots.** Players message a human support ACCOUNT (@BrandSupport); a Business-Mode bot connected to that account bridges chats into the platform via `business_connection`/`business_message`, replying with `business_connection_id`. | Official Telegram feature (no ToS risk, no MTProto user-bots). Replies appear to come from the human account = trust. Owner's phone replies are captured too. |
| **Connection method is a Setting** per Telegram channel: Direct Bot / Business Account / Hybrid. | Operator flexibility; direct bot still needed for app deep links, /status, rich buttons. |
| **~24h reply window** (business mode) designed in fully: countdown chips, "Expiring windows" view, expired-composer options card, SMS re-engagement fallback, self-calibrating expiry. | Telegram limits on-behalf replies to recently active chats; the platform must be honest and operable around it. |
| **NO customer identity verification. No SMS/OTP.** Anyone may contact support on behalf of others. Everything labeled "staff-collected", never "verified" (a test asserts no verified-badge can exist). | Explicit operator policy. The betting backoffice — not this platform — is the money control point. |
| **Conversation ≠ Ticket** (separate objects). One permanent conversation per customer per channel endpoint; tickets are bounded work items inside it (one open-family ticket per conversation, DB-enforced). | Telegram chats never "end"; clean SLA/metrics require bounded tickets. Reopen-beats-duplicate within a window. |
| **Tickets are never deleted in v1** (delete permission exists but disabled platform-wide; legal-erasure workflow is the only path, Doc 17). | Betting disputes; a deleted dispute ticket is a liability. |
| **Tech stack: Laravel** (yes — decided). PHP 8.3+, Laravel 11/12 + Octane, PostgreSQL 16 (RLS multitenancy, JSONB, partitioned messages), Redis 7 (two instances: volatile cache vs durable queues/timers), Horizon (per-family queues), Reverb (WebSockets), Meilisearch, Pennant (flags), Sanctum/Fortify, Inertia + Vue 3 for the agent app, standalone Preact iframe widget, Nutgram for Telegram. | Webhook+queue+CRUD+realtime = Laravel's sweet spot; ecosystem covers everything. |
| **SMPP never in PHP** — local-telco SMPP runs in a Go/Node sidecar behind a private HTTP API. AI/OCR/transcription = external APIs from queued jobs. | Persistent-socket protocols and inference don't belong in PHP. |
| **SMS: local-gateway-first** with per-country longest-prefix routing + fallback + circuit breakers (Ethiopia: local gateway primary, aggregator fallback). Template-only, opt-out absolute, RG-flagged structurally excluded from broadcasts. Amharic = UCS-2 = 3–4× segment cost (shown in the editor). | Global aggregators fail in Ethiopia; deliverability is the module's reason to exist. Notification-only is a structural wall. |
| **AI: grounding-or-handoff.** Chatbot answers policy/money questions ONLY with KB citations; never states transaction facts ("so my deposit WAS received, right?" must not get a yes). Copilot NEVER auto-sends. AI always discloses it's an AI (code-shipped, hash-logged, not operator-removable). RG classifier is precision-first with TEMPLATE-ONLY responses (never generated text to distressed players). Kill switches per surface×channel×bot. | Money-adjacent domain: hallucinated money facts are the critical risk; RG is ethics + license insurance. |
| **Routing: deterministic 5-step pipeline** with a routing-decision record on every ticket ("why did this land here"). Strategies: round-robin / load-based / skill-based. **30-min starvation cap** is absolute (tunable, never removable). **RG routing is final** (nothing diverts flagged players from the RG queue). All admin-configurable from Backoffice. | Explainability kills disputes; fairness caps bound every pathology. Ship RR/load first; skill-based only after real language data exists. |
| **Reporting: one semantic metric dictionary** — every metric registered once (formula, exclusions, business-hours mode); four-surface parity (page=dashboard=export=API). Spam + test traffic never count. Per-agent metrics permission-gated everywhere (anti-surveillance). "Honest triad": AI resolution rate unrenderable without CSAT(AI) + reopen-after-AI. | "No two screens show two different numbers." |
| **Audit: append-only by DB grants** (physics, not policy), hash-chained per tenant-day with sealed daily hashes + optional external anchoring. Coverage matrix = a registry; CI blocks any mutation that doesn't audit. Erasure workflow with MANDATORY four-eyes (not disableable) and honest backup-expiry dates. | Regulated vertical; "who did what" must be provable, including against the platform itself. |
| **Widget: iframe-isolated, zero host-DOM access** (can't read cookies/forms), hostile-host test page on every PR, earned notification permission, queue position never counts upward. Launch path: **support subdomain + Android app WebView** (don't block on the white-label provider's head-injection policy). | Host-page safety is absolute; the white-label constraint is routed around, not fought. |
| **White-label: "nothing hardcoded" is a build gate** — CI string-lint + hostile-theme/pseudo-locale visual sweep. Contrast validator has NO override. Flags hide, never destroy. One incident page holds every stop button (AI kill switches, rules-pause, module flags), phone-usable at 2 AM. | The master requirement enforced mechanically, not culturally. |
| **The six registries (the platform's constitution):** permissions (~118 keys), settings, notification categories, audit coverage matrix, CI gate inventory, test traceability matrix — all cross-checked in CI. | A feature without registered permission/audit/test cannot ship. |

## 4. WHAT HAS BEEN PRODUCED (deliverables inventory)

1. **Master design document** (34 pages, docx): 20-phase product design + Laravel tech stack (Phase 21), column-level schema (Phase 22), API endpoint catalog (Phase 23).
2. **The 19-document enterprise specification series** (each standalone, ~10 pages, fixed 20-section template: Executive Summary → Purpose → Scope → FR/NFR → User Stories → Business Rules → UX → UI → Technical → Security → Performance → Accessibility → Localization → Edge Cases → Failure Scenarios → Success Scenarios → Acceptance Criteria → Future Expansion → Risks → Recommendations):
   - **01** Platform Foundation & Multitenancy (RLS + scopes, transactional outbox, settings engine, UUIDv7)
   - **02** Authentication, Sessions & Security Core (Fortify/Sanctum, TOTP 2FA, lockout, visitor tokens, webhook auth, no-impersonation stance)
   - **03** RBAC (118-permission catalog, scopes Own/Team/Dept/All, User Deny > User Allow > Role, overrides w/ expiry, four-eyes option)
   - **04** Conversations & Messaging Core (Channel Adapter contract, delivery state machine, per-conversation FIFO lanes, media scan pipeline, notes structurally undeliverable)
   - **05** Telegram Channel (Business connect wizard, connection lifecycle, reply-window machinery, owner-phone capture, dual-render menu designer)
   - **06** Ticketing (status state machine, one transition service + DB invariant, merge w/ 24h unmerge, split via linked_work, metric timestamps)
   - **07** Unified Inbox (4-column desktop / 2-level mobile, views + read-model projector, full keyboard map, offline/reconnect, note-vs-reply safety)
   - **08** Customer Profiles & Identity (resolver: match-or-create, never auto-link cross-type; merge w/ 30-day undo; RG flag absence-not-redaction; no-verification enforced)
   - **09** AI System (3 surfaces, action-enum pipeline, threshold simulation, RG template-only, decision log, injection red-team, kill switches)
   - **10** Knowledge Base (per-language variants w/ outdated states, AI-drafts-humans-publish, atomic retrieval-generation swap, internal index structurally separate, tombstones)
   - **11** Departments, Teams, Queues & Routing (the 5-step pipeline, strategies, starvation cap, routing records, restructure wizards)
   - **12** Automation, SLA, Forms & Custom Fields (position-ordered rules w/ dry-run + guards, SLA snapshot instances + pure clock library, dual-render forms w/ checkpoints, macros no-blank-variables, CSAT, tenant rules-pause switch)
   - **13** Web Chat Widget (loader+iframes, 3 tabs, AI→queue→agent flow, signed identity, staged CDN rollout, hostile-host suite)
   - **14** SMS Module (guard function truth table, DLR state machine w/ honest "expired", country routing + circuits, http_generic driver, broadcasts w/ guards)
   - **15** Reporting (metric dictionary, two-speed architecture, supervisor live dashboard, dashboard builder, drill-downs, nightly drift sampler)
   - **16** White-Label, Settings & Flags (theme lifecycle, translation manager, registry-rendered settings, notification 3-layer model, incident page, config export/import)
   - **17** Audit & Compliance (hash chains + seals, coverage matrix, erasure workflow, subject-access export, legal holds)
   - **18** Infrastructure & DevOps (topology, CI gate inventory w/ skip-protection, blue-green + expand-contract, alert catalog, backups "unverified = none", drill calendar, vendor register)
   - **19** QA Strategy (traceability matrix registry, shared oracles incl. governed RG corpus, test-type standards, UAT journeys, launch-readiness checklist)
3. **Design prompts for Claude Design** (see §6).

## 5. DEVELOPMENT PROCESS (the agreed pipeline)

**Docs (the law) → Claude Design (the look) → Claude Code (the build) → fresh-Claude review (the critic) → Owner (the judge).**

- **Claude Design first, in BATCHES not all-in-one:** Batch 1 = design system + Unified Inbox (iterate until loved — everything inherits it). Then: 2) inbox mobile + ticket detail; 3) widget; 4) customer profile/directory; 5) supervisor dashboard + reports; 6) Backoffice (Telegram wizard, settings, roles matrix); 7) auth + branding editor. Attach only the relevant docs per batch; open each batch with screenshots of the approved system ("match exactly"). Mobile-first for customer surfaces; desktop-primary but mobile-capable (documented patterns) for the agent app. Both viewports (375/1440) delivered together, every screen, every state; upfront checklist extracted from the docs + closing audit against it.
- **Claude Code builds module-by-module in the series' order:** Doc 01 → 02 → 03 → 04 (with the FAKE adapter first) → 06 → 05 → 07 → then the rest. One instruction per session ("Implement Doc 06's state machine; section-18 ACs are the test checklist"). Docs live in `/repo/docs` as markdown. Owner verifies via green tests + clicking through; expects fix-loops as normal.
- **Owner-provided real-world items:** BotFather token, Business account on a dedicated phone (staging + prod — "the support phone is production infrastructure"), Ethiopian SMS gateway credentials, hosting, decisions.
- **Honest boundaries stated:** one chat cannot build the whole platform; first design pass is ~70%; Claude Code sometimes over-claims (tests catch it); hire a freelance senior Laravel dev for a few hours to review auth/tenancy/money-adjacent code before real players; MVP (Docs 01–07 + basic 09) realistically 2–4 months.
- **Recommended MVP scope** (from the master doc): Telegram-only first (widget is fast-follow Wave 2), 3 fixed-schema forms before the form builder GA, RBAC built fully up front (can't retrofit), AI launched conservative and widened weekly via the simulation tool.

## 6. THE CLAUDE DESIGN PROMPT (Batch 1, final agreed version)

> You are the world's best product designer with 25+ years of experience designing enterprise workspaces used daily by millions — Zendesk, Intercom, Linear, Superhuman-class tools. You obsess over information hierarchy, muscle-memory navigation, and interfaces that disappear so the work shines. You never produce generic AI aesthetics.
>
> I'm building an AI-first customer support platform for betting companies — Telegram-first, African market, agents handle 60–120 conversations per shift. I've attached the specification documents. **Treat them as law: nothing in them may be missed, simplified, or replaced with your own invention.** Before designing, list every screen, component, and state the attached docs require — that checklist is your contract, and I will verify against it.
>
> **PART A — Design system first:** Dark + light themes; propose 2–3 color directions; Inter + Noto Sans Ethiopic; 4pt grid; every component the spec names: buttons (all variants + loading), status pills with exact fixed colors, priority dots, SLA countdown chips (green→amber→red), reply-window chips, message bubbles — customer / agent / AI (visibly AI-accented) / internal note (unmistakably different: distinct background, border, label — an agent must NEVER confuse note mode with reply mode), system events, tombstones — avatars with presence, data tables, tags, toasts, empty-state pattern, skeletons.
>
> **PART B — The Unified Inbox (Doc 07), interactive prototype:** Desktop 1440px exact four-column layout (Views rail, list, thread, context panel w/ Ticket/Customer/Copilot tabs). Mobile 375px exact documented pattern (list ↔ thread, bottom tabs, swipe-up context sheet, swipe-to-claim, sticky composer). Both viewports side by side; a weak mobile version is a rejection. **Navigation is the product:** any conversation in ≤2 taps/clicks; urgency visible at a glance (SLA red, expiring windows, unassigned badges); act without leaving the thread; keyboard shortcuts hinted on desktop; thumb-reachable primaries on mobile. **States mandatory:** normal, unread, composer normal/window-closing/window-expired card/blocked, note mode, collision warning, failed+retry, offline banner, empties, skeletons. Realistic data: Ethiopian names, deposit/withdrawal chats, Amharic+English incl. long Amharic strings.
>
> **Rules:** dense and fast over decorative (8-hour tool); nothing by color alone; 44px targets; WCAG AA both themes. **Finish by delivering your checklist with each item marked done so I can audit completeness.**

(Later batches reuse the skeleton, swap Part B + attachments, and add: "Every screen: 375px and 1440px together — mobile is not a bonus" + "Match the established visual language exactly — consistency violations are rejections.")

## 7. CURRENT STATUS & NEXT STEPS

**Done:** all documentation (master + 19 docs), tech stack decided, pipeline agreed, design prompts written.
**Next, in order:**
1. Run Claude Design Batch 1 (design system + inbox); iterate to approval.
2. Set up the repo (Laravel 11, Docker Compose w/ Postgres+Redis, docs in `/docs` as markdown).
3. Claude Code: Doc 01 foundation + first CI gates → Doc 02 → Doc 03 → Doc 04 with the fake adapter.
4. Create the staging Telegram bot + Business account on a dedicated device early (needed by the Doc 05 milestone).
5. Onboard the Ethiopian SMS gateway sandbox via the http_generic driver (by Doc 14).
6. Optional-but-recommended: 2–3 senior Laravel devs read Docs 01–04 and challenge them; expert review of auth/tenancy/money paths before launch.
