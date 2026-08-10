# S23 close-out — the full 101-id self-audit (for the advisor's mechanical recount)

Source: docs/BUILD-STATUS.md, SESSION-23 step-12 section (verbatim extract).

### The full 101-id self-audit (id count anchored: FR 30 / NFR 6 / US 6 / BR 6 / T 9 / SEC 7 / EC 14 / FS 5 / SS 4 / AC 9 / R 5)

**Final tally: 84 DONE / 17 PARTIAL (every PARTIAL a pre-agreed bucket or an
owner-assisted blocker) / 0 NOT-IMPLEMENTED.** The recount ran BEFORE the
step-12 gap closures and found FR-26/US-05 NOT-IMPLEMENTED, FR-25/SEC-05
partial and EC-06 undriven — those five rows below carry their s12 closure.

| id | what it requires (short) | proof (test path / evidence) | status |
|---|---|---|---|
| FR-01 | Wizard: getMe, vault token, route key, setWebhook, verbatim allowed_updates | tests/Feature/Telegram/BotProvisioningTest.php | DONE (s4) |
| FR-02 | Webhook per Doc 04 FR-10; secret mismatch per Doc 02 FR-23 | tests/Feature/Telegram/WebhookRouteTest.php | DONE (s4) |
| FR-03 | Idempotent re-register, drops nothing | tests/Feature/Telegram/BotProvisioningTest.php | DONE (s4; commands re-registration added s12) |
| FR-04 | Delete needs zero connections OR cascade ack; archive, not destroy | tests/Feature/Telegram/BotProvisioningTest.php | DONE (s4) |
| FR-05 | Mode semantics: direct flags, business redirects, hybrid both | tests/Feature/Telegram/DirectSurfaceTest.php (ModePolicy) | DONE (s9) |
| FR-06 | Immediate effect; typed confirm leaving business; no disconnect | tests/Feature/Telegram/DirectSurfaceTest.php | DONE (s9) |
| FR-07 | Connect checklist; live wait; admin identity confirm | tests/Feature/Telegram/ConnectionLifecycleTest.php + browser S02 | DONE (s6/s10; live walk rides AC-03) |
| FR-08 | Connection registry: one bot ↔ many accounts, independent dimensions | schema + tests/Isolation/TelegramGrantPhysicsTest.php | DONE (s1/s6) |
| FR-09 | State machine from updates; three transition events; admin notify | tests/Feature/Telegram/ConnectionLifecycleTest.php | DONE (s6) |
| FR-10 | Revoked → channel_unreachable flags, rescue list, lane freeze | tests/Feature/Telegram/ConnectionLifecycleTest.php | DONE (s6; rescue-view render → Doc 07) |
| FR-11 | message/business_message → NormalizedInbound; owner-phone as agent | InboundMappingTest + OwnerPhoneAttributionTest | DONE (s5, A-27) |
| FR-12 | Edits → revision path; deletions → tombstones | tests/Feature/Telegram/InboundPipelineTest.php | DONE (s5) |
| FR-13 | Media mapping, 20 MB cap, SSRF-safe getFile | InboundMappingTest + Conversations/MediaPipelineTest (A-26 seam) | DONE (s5) |
| FR-14 | Callback ACK ≤1 s, nonce replay protection, flow routing | tests/Feature/Telegram/InboundPipelineTest.php (SEC-06/T-05) | DONE (s5/s9; flow execution → Docs 09/12, pre-agreed) |
| FR-15 | my_chat_member → blocked/unblocked events + annotation | tests/Feature/Telegram/InboundPipelineTest.php | DONE (s5) |
| FR-16 | Kind → method; business_connection_id; word-boundary split part i/n | tests/Feature/Telegram/OutboundSendTest.php | DONE (s7) |
| FR-17 | Delivered unsupported; single-check "Sent"; never fabricated | OutboundSendTest + conformance | DONE (s7; DOM half = AC-07 → Doc 07) |
| FR-18 | Error classification incl. retry_after override | tests/Feature/Telegram/ErrorClassificationTest.php | DONE (s7) |
| FR-19 | Typing every 4 s, best-effort, failures swallowed | tests/Feature/Telegram/OutboundSendTest.php | DONE (s7) |
| FR-20 | Token buckets per bot/chat; adaptive slowdown | tests/Feature/Telegram/RateLimiterTest.php (Redis-Lua) | DONE (s7) |
| FR-21 | Window expiry from DB-time inbound; calibration tightens | tests/Feature/Telegram/WindowCalibrationTest.php | DONE (s8) |
| FR-22 | open/closing/expired states; 5-min sweep; two events | WindowSweepTest + HealthRollupTest (cadence, s11) | DONE (s8/s11; automation consumption → Doc 12) |
| FR-23 | Expired composer contract: disabled, SMS/phone/wait options | WindowSweepTest (states) + SMS-gate seam | PARTIAL — rendering → Doc 07, SMS send → Doc 14 (pre-agreed buckets) |
| FR-24 | Inbound on expired → reopen, recompute, notify assignee | tests/Feature/Telegram/WindowReopenConsumerTest.php (C-09) | DONE (s8) |
| FR-25 | Five fixed commands, localized, per-language registration | DirectSurfaceTest (behaviours, s9) + BotProvisioningTest (setMyCommands ×3 scopes, s12) | **DONE (s9 + s12 — the registration half was the audit's find #2)** |
| FR-26 | Deep links: payload schema → acquisition metadata, graceful unknowns | DirectSurfaceTest FR-26/SEC-05 block + TelegramGrantPhysicsTest write-once | **DONE (s12 — the audit's find #1; was never built despite the step-5 plan row; AI first-turn use → Doc 09, pre-agreed)** |
| FR-27 | Menu designer: ≤12/rows≤3, versioned publish, rollback, dual preview | DirectSurfaceTest + browser S04 (§K) | DONE (s9/s11) |
| FR-28 | Templates per language, variables, operator-editable | DirectSurfaceTest ({{queue_wait}} stated seam) + browser S05 | DONE (s9/s11) |
| FR-29 | Health metrics + healthy/degraded/down rollup, thresholds from settings | tests/Feature/Telegram/HealthRollupTest.php (A-22) | DONE (s11) |
| FR-30 | Test tools: send-test, simulate-expiry (staging), re-register, masked viewer | HealthRollupTest + TelegramSecurityTest + browser S06 | DONE (s11) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| NFR-01 | Adapter ≤30 ms normalization / ≤80 ms send overhead | tests/Performance/TelegramBudgetsTest.php (strict PASS ×2, s12) | DONE (dev-hardware-indicative; L1 → Doc 19) |
| NFR-02 | 100 updates/s burst sustained, ACK budget intact | ACK p99 measured (TelegramBudgetsTest) | PARTIAL — sustained L1 burst → Doc 19 (pre-agreed) |
| NFR-03 | Sweep drift ≤5 min + event-driven immediates | HealthRollupTest (cadence) + WindowSweepTest + TelegramBudgetsTest (O(due)) | DONE (s8/s11/s12; 50k profile → Doc 19) |
| NFR-04 | Connection state reflects owner change ≤5 s | ConnectionLifecycleTest (6.66 ms measured) | DONE (s6) |
| NFR-05 | Publish propagation ≤10 s | DirectSurfaceTest (10.65 ms measured) | DONE (s9) |
| NFR-06 | Zero tokens/file URLs in logs | TelegramScrubberPatternsTest + log-scrubber gate | DONE (s1/s9) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| US-01 | Admin connects account via 3-step checklist, sees green | ConnectionLifecycleTest + browser S02 | PARTIAL — live connect walk rides AC-03 (owner-assisted-BLOCKED) |
| US-02 | Agent sees countdown; lapse explains options | Window suites + WindowChip (browser) | PARTIAL — composer rendering → Doc 07 (pre-agreed) |
| US-03 | Midnight phone reply appears in-thread, marked | OwnerPhoneAttributionTest | PARTIAL — "phone" badge render → Doc 07 (pre-agreed) |
| US-04 | Disconnect alert within seconds; affected tickets listed | ConnectionLifecycleTest (FR-10, FS-04, NFR-04) | PARTIAL — rescue-view render → Doc 07 (pre-agreed) |
| US-05 | Deep-linked player greeted with context | DirectSurfaceTest FR-26 end-to-end (acquisition recorded + seam reads) | **PARTIAL — recording + seam DONE (s12, the audit's find); the context-aware greeting is Doc 09's first turn (pre-agreed)** |
| US-06 | Owner redesigns menu, previews both, publishes | DirectSurfaceTest + browser S04 dual preview | DONE (s9/s11) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| BR-01 | One account ↔ one bot; topology in registry | schema + ConnectionLifecycleTest (EC-02 single row) | DONE (s1/s6) |
| BR-02 | Never initiate first contact | structural — sends only ride Doc 04 lanes on existing conversations | DONE (structural; outreach is Doc 14's) |
| BR-03 | Owner replies first-class, attributed, reported | OwnerPhoneAttributionTest (A-27 gate = sabotage #20) | DONE (s5/s6) |
| BR-04 | Single-check truth non-negotiable | conformance delivered-unsupported + FR-17 test | DONE (s3/s7; DOM half → Doc 07 via AC-07) |
| BR-05 | Windows tighten, never extend past observed behavior | WindowCalibrationTest (extend REFUSED both ways) | DONE (s8) |
| BR-06 | Redirect always carries tappable link | DirectSurfaceTest (refusal test = sabotage #14) | DONE (s9) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| T-01 | Doc 04 FR-08 adapter, exact capabilities | tests/Contract/AdapterConformance/TelegramAdapterConformanceTest.php | DONE (s3/s5) |
| T-02 | Per-bot runtime binding; key resolve before secret verify | WebhookRouteTest (order proof) + TelegramBotApiTest; ADR 0012 | DONE (s2/s4) |
| T-03 | Reserved owner-phone staff identity, lazy, reported | OwnerPhoneAttributionTest | DONE (s5) |
| T-04 | Column truth + ZSET index; sweep; event-driven bypass | WindowSweepTest + TelegramGrantPhysicsTest + cadence (s11) | DONE (s8/s11) |
| T-05 | HMAC nonce ≤64 bytes, single-use via Redis | InboundPipelineTest (SEC-06/T-05) | DONE (s5) |
| T-06 | Menu/template config caches, invalidated on publish | DirectSurfaceTest (FR-27/T-06/NFR-05) | DONE (s9) |
| T-07 | File URLs are secrets — never persisted or logged | InboundPipelineTest + TelegramBotApiTest + TelegramScrubberPatternsTest | DONE (s2/s5) |
| T-08 | retry_after honored; adaptive bucket in Lua | RateLimiterTest + ErrorClassificationTest | DONE (s7) |
| T-09 | Recorded-fixture suites; conformance unmodified | fixtures/telegram/* corpora; TelegramAdapterConformanceTest | DONE (s5/s7) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| SEC-01 | Tokens vault-only; UI never displays | TelegramBotApiTest (A-19 ciphertext) + TelegramSecurityTest (props sweep) | DONE (s2/s11, per A-19 interim ruling) |
| SEC-02 | 256-bit secret, rotated on re-register, constant-time check | BotProvisioningTest + WebhookRouteTest + WebhookComparatorPinTest | DONE (s4, sabotage #16) |
| SEC-03 | Viewer masks phones/contacts/forward-origin; permission-gated | tests/Security/TelegramSecurityTest.php (no-unmask-door proof) | DONE (s11) |
| SEC-04 | Unconfirmed connections never route; 10-min alert | ConnectionLifecycleTest (never-routes FIRST; sabotage #8) | DONE (s6) |
| SEC-05 | Deep-link payloads untrusted: validated, capped, indirection table | DirectSurfaceTest (cap-before-grammar; inert-string proof, structural + behavioural) | **DONE (s12 — closed with FR-26; was the audit's find)** |
| SEC-06 | Callback replay → neutral toast, logged | InboundPipelineTest (sabotage #7) | DONE (s5) |
| SEC-07 | All egress via allowlisted proxy, api.telegram.org only | TelegramBotApiTest (dead-proxy pin = sabotage #11) | DONE (s2) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| EC-01 | Wrong account → reject deletes pending, checklist restarts | ConnectionLifecycleTest | DONE (s6) |
| EC-02 | Two admins converge on one registry row | ConnectionLifecycleTest | DONE (s6) |
| EC-03 | can_reply=false → paused with explainer, inbound flows | ConnectionLifecycleTest | DONE (s6) |
| EC-04 | Hybrid: one customer, two conversations, grouped, warned | DirectSurfaceTest (hybrid mode handling) | PARTIAL — identity resolution → Doc 08, grouping/badge/warning → Doc 07 (pre-agreed) |
| EC-05 | Account rename updates forward, history kept | ConnectionLifecycleTest | DONE (s6) |
| EC-06 | Same-minute revoke + token loss converge; compound cause; rescue once | ConnectionLifecycleTest EC-06 compound test (real poll against a dead endpoint) | **DONE (s12 — the audit's find #3; each path was proven separately, the compound convergence was undriven)** |
| EC-07 | 20 MB limit → media_rejected note, caption survives | InboundPipelineTest + TelegramBotApiTest | DONE (s5) |
| EC-08 | retry_after vs remaining window: no doomed retries | ErrorClassificationTest | DONE (s7) |
| EC-09 | Split partial failure resumes at failed part | OutboundSendTest (sabotage #15) | DONE (s7) |
| EC-10 | Unthumbnailable sticker never blocks | InboundMappingTest | DONE (s5; placeholder render → Doc 07) |
| EC-11 | Duplicate connection updates idempotent by connection_id | ConnectionLifecycleTest (THREE REAL captured updates) | DONE (s6) |
| EC-12 | Forwarded-origin stored but masked; annotation shown | TelegramSecurityTest (UpdateMasker) | DONE (s11) |
| EC-13 | Receipt order wins; telegram_date preserved in channel_meta | WindowCalibrationTest (2020-date control) + InboundMappingTest | DONE (s5/s8) |
| EC-14 | Calibration tightens on early failure; telemetry | WindowCalibrationTest | DONE (s8) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| FS-01 | Outage: lanes queue, sweep pauses expiry, drain on recovery | WindowSweepTest (sabotage #10) + HealthRollup convergence | DONE (s8/s11; inbox banner → Doc 07) |
| FS-02 | Silent webhook detected; alert; one-click re-register | HealthRollupTest (positive AND negative controls) | DONE (s11) |
| FS-03 | Vault down → retryable credentials_unavailable, no fallback | TelegramAdapterConformanceTest (scripted credentials failure) | DONE (s7; ops alert wiring → Doc 18) |
| FS-04 | Flap damping: digest over 3/hr, states truthful | ConnectionLifecycleTest | DONE (s6) |
| FS-05 | ZSET loss → rebuild from column | WindowSweepTest (WindowIndexRebuilder; sabotage #13) | DONE (s8) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| SS-01 | Two accounts, routing by connection, per-account reports | FR-08 registry dimension + routing tag | PARTIAL — report lines → Doc 15 (pre-agreed) |
| SS-02 | Match-night 100/s × 5 min, zero drops | ACK budget held (TelegramBudgetsTest) | PARTIAL — sustained L1 burst → Doc 19 (pre-agreed) |
| SS-03 | Expire → SMS → reply → reopen → notify, on timeline | WindowReopenConsumerTest (reopen + notify) | PARTIAL — SMS send → Doc 14 (pre-agreed) |
| SS-04 | Conformance suite passes unmodified | TelegramAdapterConformanceTest + zero-core-edits diff assertion | DONE (s3/s5) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| AC-01 | Every allowed update type → exact NormalizedInbound | InboundMappingTest + fixture suite | DONE (s5) |
| AC-02 | Mode matrix incl. misconfiguration alert + redirect | DirectSurfaceTest (ModePolicy) | DONE (s9) |
| AC-03 | Live staged lifecycle walk | machinery proven (ConnectionLifecycleTest, EC-11 real captures) | PARTIAL — owner-assisted-BLOCKED (staging Business account, §21) |
| AC-04 | Window timelines, composer contract, SMS gating, reopen, calibration | Window suites (s8) | PARTIAL — composer render → Doc 07, automations → Doc 12, SMS → Doc 14 |
| AC-05 | Error fixture corpus lands specified states | ErrorClassificationTest | DONE (s7) |
| AC-06 | Bucket conformance, adaptive slowdown, FIFO | RateLimiterTest | DONE (s7) |
| AC-07 | UI never renders delivered for Telegram | capability-truth half (conformance) | PARTIAL — DOM half → Doc 07 (pre-agreed) |
| AC-08 | Designer round-trip; previews match staging renders; ≤10 s | automated halves (DirectSurfaceTest, browser S04) | PARTIAL — live render comparison owner-assisted-BLOCKED (same unblocker as AC-03); pixel half not claimed |
| AC-09 | Token absent logs/UI; routing block; replay; masking | TelegramScrubberPatternsTest + TelegramSecurityTest + ConnectionLifecycleTest + InboundPipelineTest | DONE (s1–s11, all four halves) |

| id | what it requires (short) | proof | status |
|---|---|---|---|
| R-01 | Business-API drift: isolation, calibration, changelog review | SS-04 + EC-14 proofs | DONE — quarterly changelog task → Doc 18 runbook handoff |
| R-02 | Phones as infrastructure: dedicated devices, documented | step-6 incident + close-out risks record | DONE (recorded; device policy → owner/Doc 18 onboarding) |
| R-03 | Missed SLAs from expiry: notifications, hooks, SMS, pause | FR-22 events proven | PARTIAL — SLA pause flag → Doc 12, SMS → Doc 14 (pre-agreed) |
| R-04 | Hybrid confusion: grouping, warning, training | EC-04 mode handling | PARTIAL — grouping/warning → Doc 07 (pre-agreed); training copy → owner |
| R-05 | Viral bursts: buckets degrade gracefully, queue not fail | RateLimiterTest | DONE (s7) |

Count check: 30+6+6+6+9+7+14+5+4+9+5 = 101. Tally: 84 DONE / 17 PARTIAL / 0 NOT-IMPLEMENTED.
