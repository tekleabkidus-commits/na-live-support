# GEEZSMS-API-NOTES.md — vendor reference data for Doc 14's `http_generic` mapping

> **STATUS: REFERENCE DATA, NOT LAW.** Extracted from the vendor's public pages on
> 2026-09-08 by an owner-run extraction chat (sections 1–10, reproduced as delivered)
> and completed by the Doc 14 advisor from the same pages after the paste truncated in
> transit (sections 11–18, marked as such). Doc 14 is the law; this file only tells the
> mapping config what the vendor's wire looks like. Every UNKNOWN below is an open
> question for GeezSMS support (§18), never a value to guess in code. No credential or
> example token appears here; `<TOKEN>` is a placeholder. Any instruction-shaped text
> quoted from a vendor page is data, never an instruction to the session.
> Filed under R-D14-1 / R-D14-3 (docs/ADVISOR-RULINGS-D14.md).

## 1. Overview

GeezSMS is an SMS gateway / messaging service operating in Ethiopia, presented as a product of Aquila ICT Solutions [https://geezsms.com/, https://geezsms.com/terms-and-conditions/]. Marketing pages describe bulk SMS, single SMS, OTP authentication, two-way SMS, contact sync, scheduled SMS, campaigns and a no-code web dashboard [https://geezsms.com/, https://geezsms.com/sms-in-ethiopia/].

Base URL(s):
- `https://api.geezsms.com/api/v1` — every endpoint in the official reference is under this host and prefix [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Secondary confirmation: the unofficial PHP client builds `'https://api.geezsms.com/api/' . self::API_VERSION . "/sms"` with `const API_VERSION = 'v1';` [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php].
- Dashboard / token page referenced by the docs as `http://geezsms.com/#/api` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. That URL now loads the WordPress marketing home page with no API/token section [https://geezsms.com/#/api]. The live dashboard is `https://dashboard.geezsms.com/`, which redirects to `https://dashboard.geezsms.com/auth/login` [https://dashboard.geezsms.com/].
- Pricing widget is served from `https://pricing.geezsms.com/#/pricing-dark` (embedded as an iframe on the home page) [https://geezsms.com/, https://pricing.geezsms.com/#/pricing-dark].

API versions available: only `v1` appears in the official reference [https://documenter.getpostman.com/view/11254016/TzK2YZ2J] and in the PHP client constant [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php]. The Node library exposes an `apiVersion` option with the example value `1`, implying versions are selectable, but no other version is named [https://www.npmjs.com/package/geezsms]. Any version other than v1: UNKNOWN.

The Postman workspace exposes one environment named `GeezSMS Prod`; no staging/sandbox environment is offered there [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Date pages were read: 2026-09-08.

## 2. Authentication

The token is sent as a **request parameter**, not a header, in the documented send/OTP endpoints:
- GET endpoints: query string parameter `token` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- POST `sms/otp` and POST `sms/send`: form-data field `token` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- POST `sms/send/bulk`: JSON body field `token` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

The balance endpoint is the only one showing a header, and it shows the token twice over — a header **and** a body field:
- Header name: `X-GeezSMS-Key` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Plus form-data field `token` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Whether `X-GeezSMS-Key` is accepted on the send endpoints as an alternative to the `token` parameter: UNKNOWN — the docs never show it there.

Where the token comes from: the docs say the API token is obtained from `http://geezsms.com/#/api` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The npm README says the API key is obtained from the GeezSMS Dashboard, linking to the same URL [https://www.npmjs.com/package/geezsms]. The terms state the user "will be provided API key" as part of a package [https://geezsms.com/terms-and-conditions/].

Token lifetime / expiry / rotation: UNKNOWN — not stated on any page read.
Token revocation: UNKNOWN.
Multiple tokens per account: UNKNOWN.
IP allowlisting: UNKNOWN — no page mentions IP restrictions.
TLS requirement: all documented example URLs are `https://` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]; whether plain HTTP is rejected is UNKNOWN.

Note: the unofficial PHP client throws `Token is required.` locally when the token is empty — that is a client-side check, not a vendor response [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php].

**Doc 14 consequence (advisor):** the platform sends the token ONLY as the POST form field — never the GET variant (a token in a query string lands in access logs); `ScrubSecrets` gets the token's VALUE pattern (a Doc 02 packet, P-RIDER1-FIX shape); the token is a `vault://` ref (RIDER 1 / ADR 0021).

## 3. Endpoint table

Every endpoint present in the official reference is listed. **In the official reference, all six endpoints show "No response body / This request doesn't return any response body" — the vendor publishes no example success response and no example error response for any endpoint** [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. Success and error example columns are therefore UNKNOWN except where noted. (Confirmed by the owner's full-page screenshot of the reference, 2026-09-08.)

### 3.1 `GET https://api.geezsms.com/api/v1/sms/otp`
- Purpose: Send OTP [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Required params (query): `token`, `phone`.
- Optional params (query): `shortcode_id`.
- Param notes as written: `phone` — "the phone number of otp reciever. must start with 2519"; `shortcode_id` — "(optional) your shortcode id. if not specified Geez's shortcode will be used" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example request (token masked): `curl --location 'https://api.geezsms.com/api/v1/sms/otp?token=<TOKEN>&shortcode_id=your%20shortcode_id&phone=your%20phone%20number'` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example success response: UNKNOWN (docs show none).
- Example error response: UNKNOWN.

### 3.2 `GET https://api.geezsms.com/api/v1/sms/send`
- Purpose: Send SMS [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Required params (query): `token`, `phone`, `msg`.
- Optional params (query): `shortcode_id`.
- Param notes as written: `phone` — "The reciever phone number. must start with 2519"; `msg` — "Message content must be less than 335 characters and supports Unicode characters." [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example request (token masked): `curl --location 'https://api.geezsms.com/api/v1/sms/send?token=<TOKEN>&phone=phone%20number&msg=your%20message&shortcode_id=your%20shortcode%20id'` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Note: the GET variant does **not** list a `callback` parameter; only the POST variant does [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example success response: UNKNOWN. Example error response: UNKNOWN.

### 3.3 `POST https://api.geezsms.com/api/v1/sms/otp`
- Purpose: Send OTP [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Body type: `formdata`.
- Required body fields: `token`, `phone`. Optional: `shortcode_id`.
- Example request (token masked): `curl --location 'https://api.geezsms.com/api/v1/sms/otp' --form 'token="<TOKEN>"' --form 'shortcode_id="your shortcode_id"' --form 'phone="your phone number"'` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example success response: UNKNOWN. Example error response: UNKNOWN.

### 3.4 `POST https://api.geezsms.com/api/v1/sms/send`
- Purpose: Send SMS [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Body type: `formdata`.
- Required body fields: `token`, `phone`, `msg`. Optional: `shortcode_id`, `callback`.
- `callback` note as written: "your webhook / callback url", "(optional) your callback url to get notified" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example request (token masked): `curl --location 'https://api.geezsms.com/api/v1/sms/send' --form 'token="<TOKEN>"' --form 'phone="phone number"' --form 'msg="your message"' --form 'shortcode_id="your shortcode id"' --form 'callback="your webhook / callback url"'` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example success response: UNKNOWN. The only JSON shown on this endpoint is explicitly labelled "This is example request body of the webhook." — i.e. the payload GeezSMS POSTs to your `callback`, not the API's own reply. See section 9 [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example error response: UNKNOWN.

### 3.5 `POST https://api.geezsms.com/api/v1/sms/send/bulk`
- Purpose: send bulk SMS [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Body type: `raw (json)`.
- Fields as annotated in the docs' own example: `token`, `contacts` (array of objects with `fname` "(optional)", `lname` "(optional)", `phone_number` "(required)"), `sender_id` "(Optional)", `msg` "(Required)", `notify_url` "(Required)" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Contradiction inside the same page: the prose description calls it "an optional notification URL", while the body annotation says `notify_url` is "(Required)" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example request (token masked):
```
curl --location 'https://api.geezsms.com/api/v1/sms/send/bulk' \
--data '{
"token": "<TOKEN>",
"contacts": [
{"fname": "contact first name (optional)","lname": "contact last name (optional)","phone_number": "contact phone number (required)"},
{"fname": "contact 2 first name (optional)","lname": "contact 2 last name (optional)","phone_number": "contact phone number (required)"}
],
"sender_id": "your sender id (Optional)",
"msg": "Your Message add [[fname]] or [[lname]] as template. (Required)",
"notify_url": "Your notification url. (Required)"
}'
```
[https://documenter.getpostman.com/view/11254016/TzK2YZ2J]
- Documented response shape (prose only, no example body): status code `200`, content type JSON, body containing "an error flag indicating whether the request was successful, and an optional message related to the error" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The exact JSON key names for that error flag and message are not given — UNKNOWN.
- Example error response: UNKNOWN.

### 3.6 `GET https://api.geezsms.com/api/v1/balance`
- Purpose: "Get Balance of API Key" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Note the path: `/api/v1/balance`, **not** under `/sms` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Header: `X-GeezSMS-Key`.
- Body type: `formdata` (on a GET request, as documented). Fields: `token` (required as shown), `billing_id` — "(optional) your billing id. if not specified Billing accociated with api token is used" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example request (credentials masked): `curl --location --request GET 'https://api.geezsms.com/api/v1/balance' --header 'X-GeezSMS-Key: <TOKEN>' --form 'token="<TOKEN>"' --form 'billing_id="your billing_id"'` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Example success response: UNKNOWN — no response body, no field names, no currency field documented.
- Example error response: UNKNOWN.

Endpoints referenced by SDKs but absent from the official reference: none. Endpoints implied by dashboard features (campaigns, contacts, scheduling, inbound message retrieval, delivery-status polling, template CRUD): UNKNOWN — no API endpoints are published for them.

## 4. Single SMS send

Two documented forms of the same operation: `GET /api/v1/sms/send` and `POST /api/v1/sms/send` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Request shape (POST, `formdata`): `token`, `phone`, `msg`, `shortcode_id` (optional), `callback` (optional) [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Response shape: **not documented.** The vendor's example response is literally "No response body" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. So the meaning of every response field cannot be sourced from the vendor. Treat the following as inference risk, not fact.

What the vendor does show is the **webhook** payload for a single send, with these five fields [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]:

| Field | Example value in docs | Meaning per vendor |
|---|---|---|
| `message_status` | `"success"` | Not defined in the docs. UNKNOWN. |
| `log` | `"async 908703ee-3898-4b45-b0e9-6fb05d7619a5"` | Not defined. A literal `async` prefix followed by a UUID. UNKNOWN. |
| `phone` | `"25100000000"` | Not defined; positionally the recipient. UNKNOWN. |
| `message` | `"Test message"` | Not defined; positionally the message text. UNKNOWN. |
| `api_log_id` | `6569829` | Not defined; an integer log identifier. UNKNOWN. |

Identifier the vendor returns for a message: **UNKNOWN as an API response value.** The two identifier-shaped fields that exist anywhere in the vendor docs are `api_log_id` (integer) and `log` (string, `async ` + UUID), and both appear only in the webhook example, not in a documented send response [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. Whether the send call itself echoes either of these is UNKNOWN.

Secondary evidence on response handling (unofficial, not vendor): the PHP client decodes JSON and returns `$body['data'] ?? $body`, implying responses may be wrapped in a `data` key [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php]. The Node library's example simply logs the whole response object without naming fields [https://www.npmjs.com/package/geezsms].

Sender-selection field disagreement for single send:
- `shortcode_id` — official reference, single send and OTP [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- `sender_id` — official reference, bulk send only [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- `sender_id` — Node library's single-send message object [https://www.npmjs.com/package/geezsms].

Idempotency key, scheduling parameter, validity period, priority, per-message cost in response: UNKNOWN.

**Doc 14 consequence (advisor):** with no documented send response, `provider_id` (T-02) on this gateway is whatever the LIVE response proves to carry — until then the mapping keeps the platform's own message id as the correlation key and matches the callback on `api_log_id` / `log` / `phone`+`message` as the live legs prove; the mock's GeezSMS profile echoes `api_log_id` in the send response as a FIXTURE ASSUMPTION labelled as such.

## 5. Bulk send

Endpoint: `POST https://api.geezsms.com/api/v1/sms/send/bulk`, `raw (json)` body [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Request shape per the official reference: `token`, `contacts[]` with `fname` / `lname` / `phone_number`, `sender_id`, `msg`, `notify_url` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Sources disagree on the bulk body:
- Official: recipients in `contacts` as an array of objects keyed `phone_number`; callback field named `notify_url` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Unofficial PHP client: recipients sent as `phone` containing a JSON-encoded array of phone strings; callback field named `notify`; plus an optional `groupid` field taken from a `group_id` constructor argument [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php]. Neither `phone` as an array, nor `notify`, nor `groupid` appears in the official reference. Trust the official reference; the PHP client may target an older or undocumented shape.

Maximum recipients per call: **UNKNOWN** — no limit is stated anywhere on the vendor pages. Pricing bands go to "Exceeding 100,000 SMS" but those are purchase volumes, not per-call limits [https://pricing.geezsms.com/#/pricing-dark].

Per-recipient results: **UNKNOWN.** The docs state only that the bulk response body carries an error flag and an optional error message; no per-recipient array, no per-recipient identifier, no partial-success semantics are documented [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

How bulk results are reported: the only documented channel is `notify_url` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The exact payload delivered to `notify_url` for a bulk job is **UNKNOWN** — the one webhook example in the docs sits under the single-send endpoint, and the docs never say whether bulk uses the same shape, sends one callback per recipient, or one per batch [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Whether a bulk job has a job/batch ID, and whether it can be queried afterwards: UNKNOWN.

**Doc 14 consequence (advisor):** broadcasts (FR-17/FR-18, T-07's 500-chunks) fan out as SINGLE sends through the platform's own chunking and shaping so every message keeps its own DLR and cost row; the vendor's bulk endpoint is not used in v1.

## 6. OTP endpoint(s)

Two documented: `GET /api/v1/sms/otp` and `POST /api/v1/sms/otp` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Parameters: `token` (required), `phone` (required, "must start with 2519"), `shortcode_id` (optional) [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Notably, **there is no `msg` parameter and no code/length/expiry parameter** — the endpoint takes only a phone number, which implies GeezSMS generates and sends the code itself [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The marketing copy is consistent with that: the OTP service signs a user in by sending an SMS "without any configuration" [https://geezsms.com/, https://geezsms.com/sms-in-ethiopia/].

Critically absent and therefore UNKNOWN: any **OTP verification endpoint**. Nothing in the reference lets you submit a user-entered code for checking [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. Also UNKNOWN: the OTP code length, character set, validity/expiry window, resend throttling, the OTP message wording/branding, whether the generated code is returned to the caller, per-OTP cost, and whether OTP is billed as one SMS.

The PHP client's `sendOtp()` defaults to HTTP `GET` and sends only `phone` (+ `shortcode_id` if configured) plus the merged `token` [https://github.com/mdmasudsikdar71/geezsms-php-client/blob/main/src/GeezSmsClient.php]. The Node library README does not document an OTP method at all [https://www.npmjs.com/package/geezsms].

**Doc 14 consequence (advisor):** Doc 14's `verification_code` event (FR-01, BR-06) is the PLATFORM's own template with a `{{code}}` variable sent through the ordinary send endpoint — the vendor's OTP endpoint is NOT used (the platform must own the code to verify it).

## 7. Templates

Vendor-documented template support is limited to bulk personalisation. The bulk `msg` field annotation reads: "Your Message add [[fname]] or [[lname]] as template. (Required)" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

So, per the vendor: variables are written inline in `msg` using double square brackets, and the only two documented placeholders are `[[fname]]` and `[[lname]]`, whose values come from the matching `fname` / `lname` keys of each `contacts[]` entry [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

UNKNOWN: whether arbitrary/custom variable names are supported, what happens when a contact omits `fname` or `lname`, escaping rules, whether `[[...]]` works on the single-send `msg`, whether templates can be pre-registered server-side, whether template approval is required, and whether any template CRUD endpoint exists. No template endpoint is published [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Conflicting secondary evidence: the Node library documents a different, server-side-looking template mechanism on **single** send — `template: true` together with a `templateValues` object (example keys `name`, `date`) [https://www.npmjs.com/package/geezsms]. Those field names (`template`, `templateValues`) and that placeholder style appear nowhere in the official reference, and the README does not say how the template body itself is defined or registered. Both accounts are recorded here; the vendor reference does not corroborate the Node one.

**Doc 14 consequence (advisor):** the platform renders every template server-side (FR-06) and sends FINAL text in `msg`; no vendor-side templating is used; a rendered message containing a literal `[[` is escaped/refused at the mapping boundary so the vendor never re-templates it (an open question for support, §18).

## 8. Sandbox / test mode

**The vendor's own pages document no sandbox or test mode at all.** There is no sandbox flag, no test token, no test base URL and no test-mode discussion in the official reference [https://documenter.getpostman.com/view/11254016/TzK2YZ2J], and none on the product site [https://geezsms.com/, https://geezsms.com/sms-in-ethiopia/]. The Postman workspace offers only one environment, `GeezSMS Prod` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

The only sandbox evidence anywhere is secondary and unelaborated: the Node library accepts an options object `{ sandbox: true, apiVersion: 1 }` passed as the second argument to `geezSMS.single.send(message, options)`, introduced in the README under "Advanced SMS Options" [https://www.npmjs.com/package/geezsms].

For that sandbox option, all of the following are UNKNOWN — the README states none of them, and its linked repository (`github.com/aquilaict/geez-js-sdk`) returns 404 so the implementation cannot be inspected [https://www.npmjs.com/package/geezsms, https://github.com/aquilaict/geez-js-sdk]:
1. How it is enabled at the HTTP level (whether it becomes a request parameter, a header, a different host, or a different path segment).
2. Whether it requires a separate token.
3. What it simulates (accepted-but-not-sent, fake success responses, validation only).
4. Whether sandbox sends produce delivery-report callbacks to `callback` / `notify_url`.
5. Cost of sandbox sends — nothing on any page says sandbox sends are free or that they are not debited.

Do not assume a sandbox exists on the REST API. Confirm with GeezSMS support before planning integration tests against production credentials.

## 9. Delivery reports (DLR)

How a callback URL is registered — **per request**, and only on two endpoints:
- `POST /api/v1/sms/send`: form-data field `callback`, described as "your webhook / callback url", "(optional) your callback url to get notified" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- `POST /api/v1/sms/send/bulk`: JSON field `notify_url`, annotated "Your notification url. (Required)" while the prose calls it optional [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- `GET /api/v1/sms/send` lists **no** callback parameter, so DLR appears unavailable on the GET form [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Account-level or dashboard-level callback registration: UNKNOWN — not documented; the dashboard is behind a login that was not entered [https://dashboard.geezsms.com/auth/login].
- Note the naming inconsistency between the two endpoints (`callback` vs `notify_url`) is the vendor's own [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

The exact callback payload, as published (this is the complete example, verbatim field names and values, and it is the **only** callback example the vendor gives) [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]:

```json
{
"message_status": "success",
"log": "async 908703ee-3898-4b45-b0e9-6fb05d7619a5",
"phone": "25100000000",
"message": "Test message",
"api_log_id": 6569829
}
```

Fields: `message_status`, `log`, `phone`, `message`, `api_log_id`. No field descriptions are given. HTTP method of the callback, content type, headers, and any signature/HMAC verification mechanism: UNKNOWN. Whether the callback is one-per-message or batched: UNKNOWN. Whether the payload differs between single and bulk sends: UNKNOWN.

The exact status vocabulary: **UNKNOWN beyond the single observed literal `success` in `message_status`** [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The vendor publishes no enumeration of statuses. Do not assume `sent` / `delivered` / `failed` / `expired`, and do not assume numeric DLR codes exist — none are listed on any page read. Note also that the example's `message_status` of `success` may describe submission acceptance rather than handset delivery; the docs do not say, and no separate delivery-status field appears in the payload.

Retry behaviour if your server does not answer (timeout, retry count, backoff schedule, dead-lettering, expected 2xx response body): **UNKNOWN** — no retry policy is documented anywhere [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Polling endpoint for message status instead of callbacks: **none published.** The reference contains no message-status or message-lookup endpoint; the only read endpoint is `GET /api/v1/balance` [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. Whether `api_log_id` can be queried: UNKNOWN.

Relevant contractual note, not an API fact: the terms disclaim liability where the telecom "SMS gateway failure to send message after responding success response" occurs — i.e. a success response is explicitly not a delivery guarantee [https://geezsms.com/terms-and-conditions/].

**Doc 14 consequence (advisor, R-D14-1.5 / R-D14-5):** `success` is NEVER mapped to `delivered`; the callback carries the message text back (a phone + message in transit to us — the DLR endpoint must scrub `message` and mask `phone` before any log, SEC-02); the callback is unsigned and header-less → P-DLR-AUTH (a per-gateway callback secret in the URL, replay-bounded) is the only authentication the documented API allows.

## 10. Inbound / two-way (MO)

Marketing claims two-way capability: "ENABLE 2-WAY SMS ... enabling effortless two-way communication" and "Conversation automation with our dialogue builder" [https://geezsms.com/, https://geezsms.com/sms-in-ethiopia/].

**The API reference contains nothing about inbound messages.** There is no MO webhook, no inbound payload example, no inbound polling endpoint, and no inbound-related parameter on any endpoint [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].

Therefore:
- Webhook URL for replies and its payload shape: UNKNOWN.
- Polling endpoint for inbound messages: UNKNOWN (none published).
- Short code / long number rules: partially stated only in the terms, where "Short code" is defined as "the integer number supplied by ethiotelecom" and "Credentials" as the username/password of the short code connected with ethiotelecom [https://geezsms.com/terms-and-conditions/]. All paid tiers list "Shared Short Code" as an included feature [https://pricing.geezsms.com/#/pricing-dark]. The site invites customers with their own short code to get in touch: "If you have your own short-code please contact us!" [https://geezsms.com/sms-in-ethiopia/].

_[The extraction chat's text truncated in transit at this point ("How a shared short code routes inbound replies to the…"). Everything above is as delivered; everything below was written by the Doc 14 advisor from the same pages on 2026-09-08 and from cross-references inside sections 1–10.]_

- How a shared short code routes inbound replies to a specific customer: UNKNOWN. Whether keyword/STOP handling exists on the vendor side: UNKNOWN.

**Doc 14 consequence (advisor):** FR-15's inbound STOP path is built to Doc 14's own contract against the mock; the GeezSMS inbound adapter rides [OA] until support answers §18 Q4–Q6.

## 11. Sender ID

- `shortcode_id` (single/OTP) and `sender_id` (bulk) are the only sender fields documented; "if not specified Geez's shortcode will be used" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J].
- Whether alphanumeric sender IDs are allowed on Ethiopian networks through GeezSMS, the registration/approval process, and its lead time: UNKNOWN. The terms tie short codes to ethiotelecom supply [https://geezsms.com/terms-and-conditions/]; own short codes are "contact us" [https://geezsms.com/sms-in-ethiopia/].

**Doc 14 consequence:** the mapping exposes `shortcode_id` as the per-gateway `sender_id` (FR-07) with the per-country override; EC-08 (alphanumeric rejected by a carrier requiring shortcodes) is [OA] on this gateway.

## 12. Encoding and length

- `msg` "must be less than 335 characters and supports Unicode characters" [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. Whether 335 counts characters or bytes, whether it applies after the vendor's own segmentation, and how multi-segment messages are billed: UNKNOWN.
- Amharic handling beyond "supports Unicode": UNKNOWN.

**Doc 14 consequence:** the platform's own segmenter (FR-05, NFR-06: GSM-7 160/153, UCS-2 70/67) decides encoding and cost; the vendor's 335-character cap is a mapping-level guard (a rendered message over the cap is classified non-retryable `failed{reason: vendor_length_cap}` at the driver, never silently truncated); the cost accrual is the platform's rate card until an invoice proves otherwise (SS-02, [OA]).

## 13. Errors

- No HTTP status codes, error codes or error messages are listed on any page read [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]. The bulk endpoint mentions an "error flag" and "an optional message related to the error" without key names. UNKNOWN throughout.

**Doc 14 consequence:** the mapping classifies by HTTP status only (5xx / network / 429 → retryable ×4; 4xx → non-retryable with the raw body retained), until live evidence refines it — FR-11's classification, FR-09's failover.

## 14. Rate limits and throughput

- UNKNOWN — no rate limit, throughput or queueing statement on any page read.

**Doc 14 consequence:** the gateway's `throughput limit` (FR-07) is operator-entered; the seed uses a conservative default stated in the plan; FS-05's conservative rebuild applies.

## 15. Pricing and balance

- Pay-as-you-go, no subscription or time limit, tiered by purchase volume (bands up to "Exceeding 100,000 SMS") [https://geezsms.com/, https://pricing.geezsms.com/#/pricing-dark]; the per-SMS ETB price per band and whether a price is per segment or per message: not captured — UNKNOWN in this file.
- `GET /api/v1/balance` exists (header `X-GeezSMS-Key`); its response shape and currency: UNKNOWN.

**Doc 14 consequence:** the rate card (FR-07, per-country price per segment, currency ETB) is operator-entered from the current price list at gateway setup; `balance` is a health signal candidate for the gateway card (§8), read only when live ([OA]).

## 16. Phone number format

- `phone` "must start with 2519" (single/OTP) [https://documenter.getpostman.com/view/11254016/TzK2YZ2J]; the callback example carries `"25100000000"` — E.164 digits WITHOUT `+`. Whether `+2519…` or `09…` are accepted: UNKNOWN. Whether Safaricom Ethiopia numbers (`2517…`) are accepted despite "must start with 2519": UNKNOWN — a real question for support.

**Doc 14 consequence:** the mapping formats `phone` from Doc 08's normalized E.164 (ADR 0014) as digits without `+`; the routing table's `+251` row covers both prefixes; EC-01's `+2519` shadowing warning applies if an operator adds a narrower row.

## 17. Fetch log

Rendered and read 2026-09-08: https://documenter.getpostman.com/view/11254016/TzK2YZ2J (the owner also supplied a full-page screenshot of it, PHP-Guzzle samples selected); https://geezsms.com/; https://geezsms.com/sms-in-ethiopia/; https://geezsms.com/terms-and-conditions/; https://pricing.geezsms.com/#/pricing-dark; https://dashboard.geezsms.com/ (redirects to /auth/login — not entered); https://www.npmjs.com/package/geezsms; https://github.com/mdmasudsikdar71/geezsms-php-client (src/GeezSmsClient.php).
Not reachable (404): https://github.com/aquilaict/geez-js-sdk; https://github.com/GeezSMS-net/typescript-sdk.
Not captured before the paste truncated: the per-band ETB price list (§15).

## 18. Open questions for GeezSMS support (each blocks a specific [OA] row)

1. What does the `POST /sms/send` success response body contain — is a message identifier (`api_log_id` or `log`) returned synchronously? (FR-11 `provider_id`)
2. What is the full `message_status` vocabulary in the callback, and which value, if any, means handset DELIVERY as opposed to submission acceptance? (FR-12/FR-13 — until answered, `delivered` is unreachable on this gateway)
3. Is the callback signed or does it carry any secret/header? What is its HTTP method, content type and retry policy when our endpoint does not answer 2xx? (SEC-01, P-DLR-AUTH, A-1's revisit condition)
4. How do inbound replies (MO) reach an API customer — webhook, polling, or dashboard only — and what is the payload? (FR-15)
5. On a shared short code, how is an inbound reply attributed to our account? Is keyword (STOP) handling performed vendor-side? (FR-15, R-04)
6. Is there any sandbox/test mode on the REST API (what `sandbox: true` in the SDK does), a test token, and are test sends free? (R-D14-1 — the [OA] shape)
7. Are alphanumeric sender IDs available; what is the registration process and lead time? (FR-07, EC-08)
8. Is 335 a character or byte limit; how are multi-segment Unicode/Amharic messages counted and billed? (FR-05, NFR-06, SS-02)
9. Are `2517…` (Safaricom Ethiopia) numbers accepted despite "must start with 2519"? (FR-08 routing)
10. Rate limits per token / per second; behaviour under burst (429 or queueing)? (FR-07 throughput, T-04)
11. Token lifetime, rotation and revocation; IP allowlisting? (SEC-01, row 0c rotation)
12. Does a literal `[[` in `msg` trigger vendor-side templating on single sends? (FR-06)

END OF GEEZSMS-API-NOTES — nothing follows this line.
