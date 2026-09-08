# S14 step 2a — SIX PACKETS PROPOSED (escalate-first; NONE landed; each countersigned BEFORE its landing step)

_Every diff below was produced by APPLYING the change on the step-1 tree (951401a + the uncommitted 2a filing), capturing `git diff`, and REVERTING; every diff passes `git apply --check` on the clean tree. Controls were executed RED on the tree WITHOUT the packet (`packet-controls-RED-01.txt`, `-02.txt`: 10 failed / 1 passed — the one pass is the intdiv pin that guards unchanged behaviour) and, where the packet's dependencies already exist, GREEN with it applied and then reverted (`halfproof-*.txt`). The control tests live in `controls/` until their packets land (a red test is never committed into the battery)._

## P-DLR-AUTH — Doc 02 core (R-D14-5; STOP-C, approved in shape with two amendments) — lands at step 7

**Defect the packet answers:** `VerifyWebhookSecret`'s mechanism set is CLOSED at `secret_header | body_hmac` and says so ("adding one is a Doc 02 change, not a route parameter"); the GeezSMS callback is unsigned and header-less (vendor notes §9) — the URL is the only carrier; `ResolveTenant` reads the key from `X-Tenant-Key`/Bearer only; the middleware stores nothing on the request.

**The diff (`P-DLR-AUTH.diff`, 9 files, +208/−7):**
1. `app/Http/Middleware/VerifyWebhookSecret.php` (Doc 02) — a THIRD mechanism `url_secret`: configured row → `{dlr_secret}` segment presence → `hash_equals(header_secret_hash, sha256(segment))` → the replay control on the body digest, run ONLY after the secret verifies. The docblock names the defense on this surface (URL secrecy over TLS + FR-13 idempotency; the window is FR-24 conformance) and the fail-closed configuration rule. The `default` arm names the three.
2. `app/Domain/Auth/Webhooks/WebhookReplayIgnored.php` (Doc 02, NEW) — STOP-C amendment 1: a DETECTED replay renders **202 `{"replay":"ignored"}`** with the metric `webhook.replay_ignored`, thrown from middleware so nothing is processed; never a 403 a gateway could treat as failure. The replay-cache OUTAGE stays `WebhookRejected::replayCacheUnavailable()` → 403 (A-1 unchanged).
3. `app/Http/Middleware/SmsDlrKeyFromPath.php` (Doc 14, NEW) — the `{gateway_key}` → `X-Tenant-Key` shim, MIRRORED from Doc 05's `TelegramWebhookKeyFromPath` (that class is Doc 05's by name and docblock).
4. `bootstrap/app.php` — the alias `sms.dlr-key`.
5. `routes/api.php` — the probe route REPLACED by two routes on the same Doc 02 stack (binder → throttle → secret → state): `POST webhooks/sms/dlr/{gateway_key}/{dlr_secret}` (`webhook.auth:url_secret`, name `foundation.webhook.sms_dlr`) and `POST webhooks/sms/dlr/{gateway_key?}` (`webhook.auth:body_hmac`, name `foundation.webhook.sms_dlr_signed` — the path key optional so Doc 02's header-borne WebhookHmacTest runs unmodified).
6. `app/Http/Controllers/Sms/SmsDlrController.php` (Doc 14, NEW) — the ACK-only door (202 with the Doc 01 resolution facts, ids only; `ProcessDlrJob` on `sms-dlr` lands with it at step 7; the payload is never logged — it carries the phone and the text).
7. `ci/audit-exempt-routes.php` / `ci/permission-exempt-routes.php` — the two PROBE justifications REMOVED; the real-handler justification in the Telegram shape for both routes (ACK-only ingress, mutations audited in the queued pipeline; machine ingress, staff permissions structurally do not apply).
8. `tests/Security/MiddlewareOrderTest.php` — the signed route joins the ordering loop.

**Controls (`controls/DlrAuthTest.php`):** RED on absence: all four 404 (`packet-controls-RED-01.txt`). GREEN with the packet applied (`halfproof-P-DLR-AUTH.txt`): **21 passed (863 assertions), EXIT 0** = the four controls (1 no secret configured → 403 · 2 wrong secret → 403, byte-identical body, the secret never echoed · 3 byte-identical replay → 202 `replay: ignored` + metric · 4 the right secret once → 202 with the tenant resolved from the PATH alone) + Doc 02's **WebhookHmacTest** and **MiddlewareOrderTest** green unmodified (the `body_hmac` surface, the FS-01 outage case, the ordering pins); Pint + PHPStan (app files) clean. Honest trail: the first half-proof run showed control 4 red on MY test's wrong `RlsSession` namespace and one nullable read in the stub — both fixed, the run repeated.

**STOP-C amendment 2 (the callback URL carries two secrets):** the structural no-body-logging test (`SmsEgressGuardTest`, step 4) asserts the assembled callback URL never reaches any log sink or capture — plant→detect→remove on the URL string.

**Alternative REJECTED:** a route with `webhook.auth` removed (a bare key-resolved door) — forbidden by the prompt (§10.5) and by Doc 02's two-surface law.

**Landing order (step 7, after countersign):** apply the diff → `ProcessDlrJob` + the DLR mapping + the sweeps → the four controls green + WebhookHmacTest + MiddlewareOrderTest + the `audit-exempt`/`permission-exempt` gates → `make lint` → BUILD-STATUS moves the packet to LANDED.

## P-SCRUB-LIVE — Doc 02 core (STOP-G, approved in shape with four conditions) — lands at step 7

**Defect:** a gateway token's SHAPE is unknown (vendor notes §2); `ScrubSecrets` redacts by key shape and by KNOWN value shapes; a token under an innocent key or inside a message string reaches the sink.

**The diff (`P-SCRUB-LIVE.diff`, 4 files, +75/−1):** `app/Support/Secrets/LiveSecretValues.php` (NEW: a final, non-instantiable, static, BOUNDED ≤ 32 set; values < 12 chars refused; `register/all/clear`); `VaultInterim::reveal()` registers the plaintext; `ScrubSecrets::scrubValue()` redacts every live value as an exact substring BEFORE the shape patterns; `TenantContext::clear()` calls `LiveSecretValues::clear()` — the request's `finally`, the job middleware and `RunsWithinTenant` all go through it.

**Controls (`controls/LiveSecretValuesScrubTest.php`):** RED on absence (the value lands; class not found). GREEN applied (`halfproof-P-SCRUB-LIVE.txt`): **22 passed (363 assertions), EXIT 0** = plant→detect→remove (the value logged BEFORE a reveal lands — the scanner proven; after `VaultInterim::reveal` the same value under `detail`, nested, and inside the message is `[scrubbed]`; after the TenantContext clear point a fresh unrevealed log of it lands again — the clear proven, not a leak) · bounded ≤ 32 · < 12 chars not registered · private constructor, final (nothing to serialize) · cleared at the `asTenant` boundary and by `TenantContext::clear()` — PLUS Doc 02's `LogScrubberTest`, `VaultRefScrubberPatternsTest`, `PhoneEmailScrubberPatternsTest`, `TelegramScrubberPatternsTest`, `SecretsNeverSerializeTest` green unmodified. Pint + PHPStan clean.

**Alternative named (not chosen):** no Doc 02 change; the pattern [OA] until a live token's shape is known. The structural no-body-logging test ships regardless (step 4).

## P-QUOTA-SMS — Doc 01 core (STOP-I, approved in shape with one condition) — lands at step 11

**Defect:** `Quota` is a four-case enum; windows are 60 s / 86 400 s / cumulative; `intdiv(time(), window)` cannot express a calendar month; FR-19 says "the Doc 01 quota system caps monthly segments (`sms.quota.segments`)".

**The diff (`P-QUOTA-SMS.diff`, 2 files, +58/−14):** `Quota::SmsSegmentsPerMonth = 'sms_segments_per_month'`; `settingKey()` becomes an explicit table (`sms.quota.segments` for the new case, `quota.<value>` for the four — unchanged); `isCalendarMonth()`; `calendarMonthTtlSeconds()` (35 days); `QuotaMeter::bucket()` — ONE seam returning `[suffix, ttl]`: the four cases keep `intdiv(time(), window) * window` and `window * 2` byte-for-byte; the month case reads the tenant-local `YYYY-MM` from DB time (`to_char(clock_timestamp() AT TIME ZONE tenants.timezone_name, 'YYYY-MM')`, C-11). `allow()`, `headroom()` and `warnOnFirstCrossing()` consume the seam.

**Controls (`controls/QuotaSmsMonthlyTest.php`):** RED on absence (`ValueError` — the case does not exist); the intdiv pin PASSES before and after (the condition's fixture: for `messages_per_minute`, `api_requests_per_minute`, `ai_tokens_per_day` the cache key the meter wrote IS `quota:<value>:<intdiv bucket>`). GREEN applied (`halfproof-P-QUOTA-SMS.txt`): **15 passed (48 assertions), EXIT 0** = the pin + the month case (keyed `sms.quota.segments`, bucket = the tenant-local month, 5 allowed then the 6th refused 429, `headroom` = {5, 5}) + Doc 01's **QuotaTest** and Doc 09's **QuotaEnforcementTest** green unmodified. Pint + PHPStan clean.

**Registry at landing:** `sms.quota.segments` (int, `tenant_editable: false`, the platform default stated in the settings amendment) — Doc 14's registry write.

## P-EXPIRED-CARD — Doc 07 core (STOP-E, approved in shape; D07-N3 corrected inside; D14-D02 unless Doc 07 filed its own) — lands at step 12

**Defect:** `ComposerPane.vue`'s `expired-sms` button has NO click handler; `ThreadController::conversationMeta` reads `customer_has_phone` from the deprecated `customers.phone` mirror (D07-N3); the built copy ("Send SMS notification") differs from the frozen B1-S02 card ("Send SMS re-engagement · … · SMS is notification-only — the customer cannot reply to it").

**The diff (`P-EXPIRED-CARD.diff`, 5 files, +132/−13):** `ThreadController::conversationMeta` — `customer_has_phone` = a `customer_identities` row of type `phone` (the guard's own predicate); `sms_available` from `App\Domain\Sms\Gateways\GatewayAvailability::exists()` (Doc 14, step 4). `ThreadPane.vue` passes `sms_available`. `ComposerPane.vue` — the button fires `POST /inbox/thread/{conversationId}/sms/reengage` with `{event: 'window_reengagement'}` and NOTHING else (Doc 14's door, step 5: an event + ids; the request class carries no text field — `SmsNoFreeTextTest` sweeps `Inbox\*Sms*` controllers); the answer renders INSIDE the drawn card: queued / duplicate "Already sent :minutes min ago" (EC-10) / skipped{reason} / error; no gateway → `expired-sms-unavailable` (phone-guidance-only, §8); no phone → the built absence. `lang/{en,am}/inbox.php` — the drawn copy verbatim for the button and the notification-only line; the new state lines; am machine-authored (R-14 queue, non-destructive).

**Controls:** `controls/ExpiredCardSmsMetaTest.php` RED on absence (`packet-controls-RED-02.txt`: `customer_has_phone` false with an identity present and a NULL mirror). The browser legs (`inbox-expired-sms.spec.ts`: one click → queued chip; second → the notice; no gateway → guidance; no phone → absence) land at step 12 with the packet. Half-proof: Pint clean; PHPStan's only finding is the step-4 dependency `GatewayAvailability` (`halfproof-P-EXPIRED-CARD.txt`) — the diff is lint-green the moment step 4 exists. Doc 07's inbox composer specs green unmodified is the landing control.

**D14-D02:** the copy alignment is filed as a Doc 14 deviation (class 2 — the drawn copy used verbatim where Doc 14 supplies the data) unless `docs/evidence/S07-design/*` shows Doc 07 filed its own — checked at landing, cited never re-filed.

## P-TIMELINE-SMS — Doc 07 core (STOP-F, approved in shape) — lands at step 12

**Defect:** the timeline door filters `name LIKE 'ticket.%'` (`TicketActionsController.php:187`) with a key-scoped allowlist (:199); an `sms.*` lifecycle row never appears; `TicketTimeline.vue` renders per name.

**The diff (`P-TIMELINE-SMS.diff`, 4 files, +78/−4):** the name filter becomes `ticket.%` OR an EXPLICIT list of the eight `sms.*` lifecycle names (never `sms.%` — opt-out/health/broadcast/cost stay off the timeline); `$detailKeys` += `sms_message_id`, `event`, `lane`, `template_version_id` (ids and enums only, SEC-02); the rows are enriched from `App\Domain\Sms\Timeline\SmsTimelineFacts::forMessageIds()` (Doc 14, step 12) with the row's LIVE `state` and the DRAWN masked phone (`+251 91 ••• 4417`) — never the full number, never a body; `TicketTimeline.vue` gains the `SmsFacts` type, the glyph map (◷ ☾ ✓ ✓✓ ! — `expired` renders ✓ with "Sent · unconfirmed"), `copySmsText` (the B12-S02 "Copy text" case) — the row template and the menu case render at step 12 with the tokens; `lang/{en,am}/inbox.php` — the nine chip words and the drawn menu sentence verbatim ("SMS cannot recall or change a sent message, so Edit and Delete are not in this menu. Nothing is greyed out — the channel simply does not offer it.").

**Controls:** `controls/TimelineSmsRowsTest.php` RED on absence (`packet-controls-RED-02.txt`: 0 SMS rows vs 1). Half-proof: Pint clean; PHPStan's only finding is the step-12 dependency `SmsTimelineFacts` (`halfproof-P-TIMELINE-SMS.txt`). The DOM legs (`inbox-sms-timeline.spec.ts`: US-02 "delivered turns green" on a real DLR from the FIXTURE; the `expired` wording; the menu case) and `sms-no-inferred-delivered.spec.ts` land at step 12. Doc 07's timeline specs green unmodified is the landing control.

## P-NAV-SMS — Doc 07/F1 shell core (STOP-O, approved in shape; the P-NAV-WIDGETS precedent 22108e6) — lands at step 13

**Defect:** `BackofficeShell.vue` draws "Channels" dim and has no Settings group; the frozen B6-S01/B6-S06 slots are "Channels · ✉ SMS" and "Settings · SMS" ("an unlinked screen is the worse deviation").

**The diff (`P-NAV-SMS.diff`, 8 files, +31/−2):** two real rows (`bo-nav-sms` → `/admin/sms`, `bo-nav-sms-settings` → `/admin/sms/settings`) with accessible names; `active` union gains `'sms' | 'sms-settings'`; the names in EVERY map that feeds the shell — `lang/{en,am}/kb.php`, `lang/{en,am}/widget.php`, the `WorkflowShell.vue` bridge + `lang/{en,am}/workflow.php` `ui.nav` (the P-NAV-WIDGETS lesson: a row without its name is an axe `link-name` red on every page that feeds the shell).

**Controls:** the F-C3 shared-shell axe sweep (all shell pages, light + dark) at landing, with the `link-name` legs as the red-if-missing control; `ScreensTest` asserts the two rows carry their names on every shell page. Half-proof: Pint clean; PHPStan on `lang/{en,am}/workflow.php` reported **PRE-EXISTING duplicate array keys** (`run_window_label` at en:195/326, am:81; `place_menu` at en:803/860, am:297) — NOT this packet's lines (`lang/` is outside phpstan.neon's paths, so `make lint` never saw them): a Doc 12 finding by name, **D12-N1**, for Doc 12's window (the later key silently wins).

**Alternative REJECTED:** `v-if` on the rows — it removes them from non-SMS pages: the inconsistent nav R-D13-2.1 ruled worse.

## Named findings surfaced by this step (for other windows; not landed)
- **D07-N3** — `conversationMeta.customer_has_phone` reads the deprecated `customers.phone` mirror (corrected INSIDE P-EXPIRED-CARD as ruled).
- **D08-N1** — `ChannelIdentityMap`'s docblock cites a non-existent `ChannelIdentityMapTest` (the live assertion is `IdentityInvariantsTest:99–135`).
- **D12-N1** — duplicate array keys in `lang/{en,am}/workflow.php` (`run_window_label`, `place_menu`); `lang/` is outside PHPStan's paths.

_END OF PACKETS — nothing follows this line._
