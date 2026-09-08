# ADVISOR-RULINGS-D14 — standing rulings of the Doc 14 advisor
_Filed at the opening of the Doc 14 inter-document window (2026-09-08), each
owner-confirmed in the advisor chat. This file is the ruling record STEP 0 of
SESSION-14 cites; it commits together with the session prompt. Kit grounding
of record: v13 kit at a90b985 (HEAD=origin/main), 00e2473 (the Doc 13
acceptance) in ancestry, ANCESTRY-OK from the owner's terminal. The
kit-uncommitted failure occurred an EIGHTH time (the repo zip the owner
uploaded showed main at 00e2473 with the v12 bootstrap still in docs/) and was
corrected in one commit; the MTU 1350 fix was applied AGAIN on the push —
OWED PERMANENT stands._

## R-D14-1 — Row 0a, the gateway prerequisite: NO SANDBOX EXISTS; GeezSMS is the gateway of record; the integration is BUILT NOW through `http_generic`, the live legs ride [OA] (owner: "we dont need to test it now… just do the integration"; gateway: "could we integrate geez SMS")

Finding, verified against the vendor's own pages (the extraction the owner
ran, plus the owner's full-page screenshot of the Postman reference):
- GeezSMS (Aquila ICT Solution, Ethiopia) is an HTTP aggregator: base
  `https://api.geezsms.com/api/v1`; six endpoints (GET/POST `sms/otp`,
  GET/POST `sms/send`, POST `sms/send/bulk`, GET `balance`); the token
  travels as a query/form parameter named `token` (a header,
  `X-GeezSMS-Key`, is documented on `balance` only); `phone` "must start with
  2519"; `msg` "must be less than 335 characters and supports Unicode";
  POST `sms/send` accepts an optional `callback` URL; bulk takes a
  `contacts[]` array and a `notify_url`; no endpoint documents a response
  body; the only callback example carries five fields
  (`message_status: "success"`, `log`, `phone`, `message`, `api_log_id`) and
  the only status word shown is `success`; their terms say a success response
  is not a delivery guarantee.
- **No sandbox or test mode exists on the vendor side** (one Postman
  environment, `GeezSMS Prod`; the SDK's `sandbox: true` is undocumented).
  Nothing inbound (MO/STOP) is documented. No error codes, rate limits,
  segment billing or DLR retry policy are documented.

Ruling:
1. SESSION-14 builds Doc 14 in full NOW. Everything provable on the local
   stack is proven for real against `docker/mocks/sms` named as a
   **FIXTURE** — never the gateway, never called proven for a live row.
2. GeezSMS onboards through **`http_generic`** (T-02: URL, method, field
   mapping, auth style) exactly as Doc 14 §21 orders ("onboard the real
   Ethiopian gateway on staging via http_generic before writing any named
   driver"). A seeded GeezSMS mapping config is authored from the vendor
   notes; a named `geezsms` code driver is §19 future expansion, NOT v1.
3. The mock carries **two profiles**: (a) a REFERENCE profile with the full
   documented status vocabulary Doc 14's state machine needs (T-08's matrix,
   AC-08's zero-code onboarding); (b) a **GeezSMS profile** that speaks the
   vendor's documented contract byte-for-byte (the `token` form field, the
   five-field callback, `success` as the only status). The same mapping
   config that onboards profile (b) onboards GeezSMS later with only the URL
   and the vault ref changed — "proves itself on day one".
4. The rows only a real gateway can prove ride a named **[OA] bucket,
   unblocker = GeezSMS credentials + a public callback URL** (the same public
   origin as Doc 13's row 0b — a Cloudflare Tunnel on an owned domain covers
   both): the live send leg of FR-07/FR-11; FR-13's real carrier status
   mapping beyond the documented `success`; FR-15's inbound STOP from a real
   gateway (no MO is documented); EC-08's real sender-ID rejection; SS-02/
   US-05's invoice reconciliation; SS-03/US-04's midnight failover in
   production; R-04's per-market checklist; the §21 real-gateway onboarding
   proof (the mock's is the local half). The session verifies this list
   against the document text at plan time and may amend it with citations.
5. **A `success` callback is NEVER mapped to `delivered`.** Until GeezSMS
   documents a delivery status, a message on that gateway reaches
   `delivered` only through a status the mapping table names as a handset
   delivery; the honest terminal is FR-12's `expired` ("sent — delivery
   unconfirmed"). Any mapping that infers delivery is class-1 against NFR-04.
6. The token is a `vault_secrets` ref (RIDER 1 / ADR 0021 interim) and a
   log-scrubber VALUE pattern; the driver uses the POST form only (the token
   never rides a URL that lands in a log); `api.geezsms.com` is an egress
   allowlist entry by name (ADR 0008's proxy).
7. The [OA] rows close in a short owner-assisted addendum session (the
   Doc 05 / Doc 13 shape), never folded into another document's window. Its
   first live run uses a test number and a STAGING token; the staging token
   is rotated before any production key (incident #16, row 0c).
8. Row 0b (the public origin for Doc 13's [OA] rows) stays parked by the
   owner's choice, restated once in the prompt and never again there.

## R-D14-2 — Doc 14 design: OPTION A with NINE named binding seams (owner: "approved")

Finding, verified both directions: PROGRAM-REPORT-v1 names Doc 14 undesigned
with no batch scheduled; `design/frozen/` = **204** at 00e2473 (the kit's
number holds; the owner's export zip reference copy carries the same
listing); no frozen file draws any Doc 14 surface. The other direction is NOT
empty — nine frozen files bind Doc 14 at the seam:
1. `B1-DS-design-system-v2` — the message-state glyph row (◷ queued ·
   ◷ sending · ✓ sent · ! failed · ⊘ canceled) and the blocked-channel
   "Consider sending an SMS instead · Send SMS" affordance.
2. `B1-S02-inbox-states-v2` — the expired-window card: "Send SMS
   re-engagement · Reply from the phone · Wait — SMS is notification-only,
   the customer cannot reply to it" (Doc 07's built ComposerPane
   `expired-sms`; Doc 14's `window_reengagement` sits behind it, and §8's
   "SMS is off until a gateway exists" degrade renders INSIDE that card as
   phone-guidance-only).
3. `B12-S02-edit-marker-v2` — the SMS timeline entry's menu ("SMS cannot
   recall or change a sent message, so Edit and Delete are not in this
   menu · Copy text").
4. `B3-S05-identity-forms-hours-v1` — the offline receipt's SMS line
   ("the SMS that follows is a notification — never a channel the customer
   can reply into"; the masked number).
5. `B4-S05-rg-blocking-v1` — the RG flag "stops promotional messages and
   non-essential SMS" (Doc 08 FR-15's [D14] half).
6. `B4-S08-cross-cutting-v1` — the phone renderings: "In the SMS receipt
   +251 91 ••• 4417" (SEC-02's masking).
7. `B5-S04-reports-home-v1` / `B5-S05-report-anatomy-v1` /
   `B5-S06-report-families-v2` — Doc 15's SMS report family (Sent ·
   Delivered · undelivered rate · per-gateway card; filters Template ·
   Gateway). Doc 14 emits the feed vocabulary; Doc 15 renders.
8. `B6-S01-telegram-bots-connection-v1` / `B6-S06-channel-health-v2` — the
   nav slots "Channels · ✉ SMS" and "Settings · SMS" where Doc 14's screens
   MOUNT (an unlinked screen is the worse deviation).
9. `B6-S12-notification-preferences-v2` (the staff-SMS column: "never
   must-SMS", the column explains its unavailability), `B6-S13-feature-
   flags-v2` (`flag.sms`), `B6-S16-incident-page-v2` ("SMS · Stop"; "Hold
   all sends"), `B7-S17-branding-inventory-v1` ("SMS sender IDs — Doc 14
   owns this") — Doc 16's surfaces; Doc 14 registers the flag, the settings,
   the hold-all-sends door contract and the sender-ID setting they link to.

Ruling:
1. SESSION-14 builds **backend-first** per Doc 14 §21: the guard function
   (T-06) and the state machine (FR-12/T-08) as pure, table-tested units
   before anything else.
2. Doc 14's own screens (Templates with the encoding badge and segment
   markers; Gateways + the routing table and test-a-number resolver; Queue &
   history; Opt-out manager; Broadcasts; the "SMS is off until a gateway
   exists" setup checklist) ship functional/themed/i18n-keyed from
   design-system components only, riding PARTIAL in the **[B14-batch]**
   bucket until a design freeze. Not a deviation flood; the bucket is the
   record. Nearest drawn idioms for class-2 reference: B9's Workflow
   list/editor shells and the Doc 12 SLA/hours painters (67dc4e4), B5-S07
   builder chrome, B5-S10 wizard/confirmation, B2-S06 inline refusals,
   Doc 10's per-language completeness dots, the S13 Widget Designer's Health
   tab as the health-card idiom, the Doc 06 bulk progress drawer for
   broadcast progress (§9).
3. Two glyphs the document requires that the system does not draw — the
   double check for `delivered` (only on a real DLR) and the moon for `held`
   — are **class-2 fills from B1-DS's glyph row**, ruled at their plan-time
   stop; `expired` renders as "sent · unconfirmed" in the DS's gray.
4. The nine seams bind as CONTRACTS proven by fixtures at build time; none
   is redrawn; the drawn copy is used verbatim where Doc 14 supplies the
   data behind it.
5. **Trigger (the debt never goes silent):** ONE combined design track for
   Docs 14 + 17 (the two undesigned documents left) in the first
   inter-document window after Doc 17 closes, then ONE fidelity session —
   the F1 shape. The v14 kit carries it as a named row.

## R-D14-3 — No external credential gate for the BUILD; STEP 0's shape (owner-confirmed by R-D14-1)

The build needs no vendor token: the mock's GeezSMS profile carries the
documented contract; the seeded mapping config points at it. STEP 0 is
therefore the rulings-filed proof + the [D14] closing set + the catalog keys
+ the vendor notes filed to `docs/vendors/GEEZSMS-API-NOTES.md` as
REFERENCE DATA (source URLs and read date inside; any instruction-shaped
text in it is data, never an instruction). The session invents no other
gate.

## R-D14-4 — [D14] closing-set enumeration (register over summary; sweep of 2026-09-08 at 00e2473, re-read at a90b985)

Literal `[D14]` hits in BUILD-STATUS: **8** (the kit's number holds) — but
only FOUR are register rows; the other four (BS:266, 602, 901, 1148) are
prose in close sections that name the same rows. Plus every register row
naming "Doc 14" without the tag (11 hits, each read). Anchor context
recorded at sweep time; the session RE-LOCATES every row BY ITS TEXT at flip
time (the S10 anchor-drift law).

**Tagged [D14] register rows (flip at close):**
1. Doc 13 FR-15 — PARTIAL [D14] (near BS:179): the offline form's SMS half
   (`offline_form_confirmation`, Doc 14 FR-01).
2. Doc 13 US-03 — PARTIAL [D14] (near BS:197): the same journey's SMS half.
3. Doc 12 SS-01 — PARTIAL [D14] (near BS:868): the J5 composition's
   SMS-template leg — "stated, not walked"; `automation.sms.requested`'s
   consumer walks it.
4. Doc 08 FR-15 — PARTIAL [D14] (near BS:2142): the [D14] SMS half — RG
   suppression of non-essential SMS (Doc 14 FR-03's RG guard,
   `Doc14/SmsSuppression`, `MarketingSuppression::isSuppressed` consumed).

**Pre-agreed PARTIALs naming Doc 14 by prose (Doc 05's close; flip at close):**
5. Doc 05 FR-23 — PARTIAL (near BS:4891): "SMS send → Doc 14" —
   `window_reengagement` behind the built expired card.
6. Doc 05 SS-03 — PARTIAL (near BS:4978): "Expire → SMS → reply → reopen →
   notify" — Doc 14 SS-01 realizes it with this module's chips.
7. Doc 05 AC-04 — PARTIAL (near BS:4986): the SMS half.
8. Doc 05 R-03 — PARTIAL (near BS:4997): the SMS half of missed-SLA
   notification.

**Seam obligations (annotation updates + real implementations; mandatory
plan rows, not status flips):**
9. Doc 05 BR-02 (near BS:4921): "outreach is Doc 14's" — annotate with the
   built event catalog and BR-01's structural wall.
10. Doc 13 frame register row 31 / BS:602's class-1 note: "the offline
    receipt's SMS line is copy only until Doc 14 sends" — the line becomes
    true; annotate `docs/evidence/S13-design/register.md` row 31.
11. Doc 02's DLR ingress: the probe route `POST webhooks/sms/dlr`
    (`tenant:sms_gateway_key` · `webhook.throttle` · `webhook.auth:body_hmac`
    · `tenant.state:inbound` → `IngressProbeController`) is REPLACED by the
    real handler; the two probe exemption lines Doc 14 owns are removed
    (`ci/audit-exempt-routes.php:36` "Doc 14 must remove this line";
    `ci/permission-exempt-routes.php:101` "Doc 14 revisits"); A-1's revisit
    condition (ANSWERS ~198; BS:7653) is NOT triggered — GeezSMS's retry
    semantics are UNKNOWN, recorded as an open question, not a revisit.
12. Doc 02's seeded `webhook_credentials`: "Doc 14's gateway setup become
    the production write paths" (BS:7619) — creating a gateway writes its
    webhook credential through Doc 02's existing minting door (Doc 05's bot
    registration is the precedent); never a second minter.
13. A-42's reopener (ANSWERS ~1813: "Doc 13 and Doc 14 are A-42's
    REOPENERS"): re-derived for SMS at plan time and filed as an A-6x —
    SMS is notification-only, no conversation arrives, the opt-out registry
    is PHONE-keyed (FR-14), an inbound STOP anchors nothing and mints no
    identity; "phone anchors nothing" stays true for Doc 14 for that reason.
14. EVENT-CATALOG's DECLARED Doc 14 consumers (A-45: implemented, never
    replaced by a hook), each a durable consumer per ADR 0011 with
    `fixtures/tickets/consumers/doc14.json` tightened in the same commit:
    `Doc14/Sms` on `ticket.resolved` (ResolveTicket's own docblock: "FR-06's
    triggers CSAT flow and SMS event"), `automation.sms.requested`
    (Doc 12's `send_sms_template` action — a template REFERENCE and ids,
    no text key exists in the closed payload) and `customer.suppression.set`;
    `Doc14/SmsSuppression` on `customer.rg_flag.set` / `.cleared`.
15. The UNDECLARED half: `ticket.created`, `ticket.assigned`,
    `ticket.closed` carry no Doc 14 consumer today, and the `sms.*` family
    (FR-20: `sms.queued|held|sent|delivered|undelivered|failed|skipped|
    expired`, `sms.optout.added|removed`, `sms.gateway.health_changed`,
    `sms.broadcast.started|progress|completed|canceled`,
    `sms.cost.threshold`) does not exist — both grow ONLY by filed
    event-family / consumer amendment (rule 16, the S12 shape), filed before
    the catalog entry lands.
16. Top-table row 14 flips to BUILT at the close-out and CLOSED/ACCEPTED at
    acceptance, citing this file and R-D14-1's [OA] rows.

**Catalog verification ordered (plan-time STOP if any key missing):** the
registry holds exactly EIGHT `sms.*` keys — `sms.send`, `sms.templates.view`,
`sms.templates.create`, `sms.templates.edit`, `sms.templates.delete`,
`sms.gateways.view`, `sms.gateways.manage` (HIGH per Doc 03 §4.1),
`sms.optout.manage`. Doc 14's text names BY KEY: `sms.send` (FR-02 — an
explicit agent action that TRIGGERS a template, never composes),
`sms.optout.manage` and `sms.templates.view` (SEC-02), plus `customers.export`
-class (SEC-04) and `reports.view` (SEC-06). Doors the text leaves un-keyed
(broadcast launch, held-message release-now, manual circuit reset, rate-card
edit) bind to EXISTING keys by a plan-time ruling with the document's own
words cited — never an invented key. `sms.quiet_hours`, `sms.quota.segments`,
`sms.broadcast.monthly_cap`, the cost alert threshold, the no-route policy and
`sms.*` per-event toggles are SETTINGS (registry entries), never permission
keys. The catalog is CLOSED at 114 (EXECUTED count, never read).

SESSION-14's step-0 enumeration is checked against the register's rows,
never this list's summary; a mismatch is investigated, never averaged.

## R-D14-5 — The DLR-ingress authentication shape: a NAMED Doc 02 packet, P-DLR-AUTH, at plan time

Finding from the code: `VerifyWebhookSecret`'s mechanism set is CLOSED at
`secret_header | body_hmac` and its own text says "adding one is a Doc 02
change, not a route parameter". GeezSMS's documented callback carries no
signature and no header. FR-07 gives each gateway a "DLR config (callback
secret / polling per driver)"; SEC-01 says DLR endpoints are "authenticated
per Doc 02 FR-24 with replay windows".

Ruling: the session does NOT widen `webhook.auth` by itself. At plan time it
proposes **P-DLR-AUTH** (escalate-first, Doc 02 core): the shape that lets a
per-gateway callback secret authenticate an unsigned callback (the URL is the
only carrier the vendor's API allows), bounded by Doc 02's `ReplayGuard` on
the gateway-scoped message identifier, with the fail-closed control (no
secret → 403) and the replay control both executed RED first. The packet is
countersigned BEFORE landing; the `body_hmac` surface stays for gateways that
sign. Until it lands, DLR ingestion is proven against the mock's REFERENCE
profile over `body_hmac`.

## R-D14-6 — Kit corrections filed as honest trail (the verify-your-own-kit law; no blame row)

1. **Doc 14 has 20 FRs, not 24.** The kit's calibration "FR-24" was the
   cross-document trap it warned about: FR-23 is Doc 05's (the expired-window
   fallback) and FR-24 is Doc 02's (DLR webhook auth), both cited inside §4.
   Private enumeration: FR 20 / NFR 6 / US 7 / BR 7 / T 8 / SEC 6 / EC 12 /
   FS 5 / SS 4 / AC 10 / R 5 = **90 ids**, every family gapless from section
   text.
2. "The DLR machine wires INTO Doc 04's delivery state machine" is
   overstated as written: `DeliveryStateMachine` governs Doc 04 `messages`;
   SMS messages are NOT conversation messages (BR-01) and live in
   `sms_messages` (T-01) with their own normative machine (FR-12/T-08).
   Doc 04's LAWS bind by pattern (`sent` never rolls back; `delivered` only
   on a real receipt; precedence), the mechanism is Doc 14's own on its own
   entity — the session verifies this reading against the code and may
   correct it with evidence.
3. "Quiet hours read Doc 12's REAL calendars" overstates FR-16: the window
   comes from the SETTING (`sms.quiet_hours`, default 21:00–08:00 tenant
   timezone); the Doc 12 calendar link is OPTIONAL ("source-linked to Doc 12
   calendars optional"). When linked, the HoursContract is consumed, never
   re-implemented; the setting is the required source.
4. "[D14] hits: 8" holds as a count; four of the eight are prose mentions,
   the REGISTER rows are four (R-D14-4 rows 1–4).
5. The advisor's own earlier line "a named `geezsms` HTTP driver" was
   corrected on Doc 14 §21 / AC-08's evidence before this ruling was filed
   (R-D14-1.2): GeezSMS is an `http_generic` MAPPING, not a driver.

## Standing context this generation inherits

Docs 01–13 CLOSED AND OWNER-ACCEPTED (Doc 13 acceptance 00e2473; verdicts of
record 33990000043 / 34155857302 / 34180127974); escalate-first over ALL of
Docs 01–13; permission catalog CLOSED at 114 (executed count, never read);
standing rules EIGHTEEN (docs/PROJECT-HANDOFF-v7.md §6 in-repo authoritative);
**R-D13-6 stands as the owner's cadence and power law** (per-batch: targeted
suites per step — the DOC suites + every touched document's suites + the
CONTRACT suites of every registry the step writes + the shared-shell axe sweep
when the Backoffice shell is touched + gates + lint; the FULL battery at each
batch checkpoint and at close; NEVER ask the owner to plug in, never wait for
power, state the power line in every report, discharging timing figures are
"battery-run, indicative", a laptop-killed run is relaunched never salvaged);
**R-D13-7 stands** (a rider whose table holds secret material, PII or
money-adjacent data is a countersign-BEFORE-migration stop — every Doc 14
table qualifies: vault refs and rate cards on `sms_gateways`, phone numbers on
`sms_messages`/`sms_optouts`); governance incidents SIXTEEN + the S10/S11/S13
named findings; risks 0a–0m standing (0l binds every browser fixture AND
scene budget — dedicated customers per scene, reseed + `inbox:views-rebuild`
the sanctioned heal, see D07-N2); named findings carried BY NAME for other
windows (D07-N1/N2 + the inbox-ticket-panel:334 flake; D09-N1/N2 — the RISK
row that an operator cannot kill the chatbot on the widget alone;
F-13b-5/F-13b-6; the AC-03 soak class; F-C4; the three Doc 12 door findings;
F1-FO-1…8) — not Doc 14's to build unless a Doc 14 step touches one, then
escalate-first with countersign; the channel FABRICATES as well as mangles —
only the owner's plain-terminal `git log` makes anything real; every ruling
block ends with an explicit END line; a truncated ruling is not a ruling.
Open owner items at every checkpoint: 0b the public origin for Doc 13's [OA]
rows (parked by choice; the same tunnel unblocks Doc 14's callback legs);
0c staging-key rotation before any production key (incident #16); 0d Addis AI
DPA-or-switch before production egress (ADR 0019, [OA]); 0e Doc 05 [OA] rows
parked; 0f wall-power figures owed (three p99 tails; the /inbox Lighthouse);
R-14 Amharic native review (cumulative, +336 keys from Doc 13, 23 destructive;
Doc 14's `sms.*` strings and the seeded am templates join it); the `/etc/
wsl.conf generateResolvConf=false` permanent fix STILL OWED; MTU 1350 OWED
PERMANENT (applied again 2026-09-08); HTTP pushes over ~10 MiB fail with 408 —
push commit-by-commit; the CI Node 20 deprecation (Doc 18).

END OF RULINGS FILE — nothing follows this line.
