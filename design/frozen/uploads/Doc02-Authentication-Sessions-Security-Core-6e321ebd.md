Table of Contents

# Document 02 — Authentication, Sessions & Security Core

**Product:** AI-First Support Platform for Betting Companies **Document series:** 02 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, security engineers, QA, DevOps, product **Depends on:** Doc 01 (tenancy, settings engine, event bus, error format). Consumed by: all other documents. **Stack context:** Laravel 11+ (Fortify for staff auth flows, Sanctum for tokens), Redis, PostgreSQL

## 1. Executive Summary

This document specifies every identity and session mechanism in the platform: staff authentication (password + TOTP two-factor), session lifecycle and device management, account lockout and recovery, personal and machine API tokens (Sanctum), widget visitor tokens, inbound webhook authentication, IP restrictions, password policy enforcement, security headers, and the auth-related settings exposed to operators. It defines all states, screens, failure modes, and audit events. Authorization (what an authenticated principal may do) is deliberately excluded — it is Doc 03 (RBAC). Customer identity on channels (who a Telegram user is) is Doc 08; this document covers only the *technical tokens* that authenticate channel traffic.

## 2. Purpose

- Give one authoritative definition of “who is talking to the platform” for every entry surface: staff browser, staff mobile browser, public API, chat widget, Telegram webhook, SMS DLR webhook, Reverb socket.

- Specify flows so completely (states, errors, copy slots, timing) that frontend and backend teams can build the login/2FA/session surfaces in parallel without further product input.

- Define the security bar (policies, lockout, headers, audit) that Doc 19’s security test plan verifies.

## 3. Scope

**In scope:** staff login/logout, 2FA enrolment/challenge/recovery, password lifecycle (set, reset, expiry, policy), sessions & devices UI, remember-me, idle & absolute timeouts, API tokens (personal + machine), widget visitor tokens, webhook inbound auth, IP allowlists, auth events & audit hooks, auth-related settings keys, security headers & CSRF/CORS posture. **Out of scope:** RBAC (Doc 03), SSO/SAML (future expansion §19), customer/player identity semantics (Doc 08), infrastructure secrets management (Doc 18), encryption at rest (Doc 18).

## 4. Functional Requirements

### 4.1 Staff login

FR-01 Login form: email + password; on success without 2FA → session established; with 2FA enabled → 2FA challenge step before any session privileges. FR-02 Errors are non-enumerating: invalid email and invalid password return the identical generic message; response time equalized (±50 ms) to prevent timing enumeration. FR-03 Lockout: after auth.lockout.attempts (default 5) failed attempts per account per rolling auth.lockout.window (default 15 min) → account locked for auth.lockout.duration (default 15 min); lockout message does not reveal remaining time (“try again later”); unlock also possible by admin action or completed password reset. IP-based throttling runs independently (default 20/min/IP on auth endpoints). FR-04 Login success SHALL record: time, IP, user agent, device fingerprint hash → creates/updates a Device record and emits auth.login.succeeded; failures emit auth.login.failed with reason category (bad_credentials, locked, suspended_tenant, disabled_user, ip_blocked, 2fa_failed). FR-05 Disabled user or suspended tenant → login blocked with distinct, non-technical messages; both audited.

### 4.2 Two-factor authentication (TOTP)

FR-06 Enrolment: profile security page → QR (otpauth URI) + manual secret → require one valid code to activate → generate 8 single-use recovery codes (shown once, downloadable, stored hashed) → emit auth.2fa.enabled. FR-07 Challenge: 6-digit TOTP, ±1 time-step tolerance; 5 attempts then the login attempt is voided (counts toward lockout); “use a recovery code” alternative consumes one code and shows remaining count; at 2 codes remaining, banner prompts regeneration. FR-08 Admin reset: an admin with the relevant permission may clear a user’s 2FA (user re-enrols at next login); typed-name confirmation; high-visibility audit auth.2fa.reset_by_admin. FR-09 Enforcement policy: setting auth.2fa.required_roles (list) — users in those roles cannot reach any screen except 2FA enrolment until enrolled; grace period auth.2fa.grace_days (default 3) with countdown banner.

### 4.3 Sessions & devices

FR-10 Server-side sessions (Redis driver); cookie HttpOnly; Secure; SameSite=Lax; concurrent sessions capped at auth.session.max_concurrent (default 5, oldest evicted). FR-11 Idle timeout auth.session.idle_minutes (default 480) and absolute lifetime auth.session.absolute_hours (default 72), both tenant-tunable; expiry redirects to login preserving the return URL with a “session expired” notice. FR-12 Devices screen (profile → Security): active sessions table — device label (parsed UA), first seen, last active, IP, approximate location (GeoIP city), “current” badge; actions: revoke one, revoke all others; revocation immediate (session key deleted; that session’s sockets force-closed). FR-13 “Remember me” extends only cookie lifetime to the absolute limit; never bypasses 2FA on a new device (device binding via fingerprint hash + signed cookie). FR-14 Password change and any 2FA change revoke all other sessions automatically.

### 4.4 Password lifecycle

FR-15 Policy (settings-driven): min length default 12; character-class requirement toggle (default off — length over composition); breached-password check via k-anonymity API toggle (default on, fail-open with log); disallow reuse of last auth.password.history (default 5, hashed); max age auth.password.max_age_days (default 0 = disabled). FR-16 Reset flow: request by email → uniform “if the account exists…” response → signed single-use token valid auth.password.reset_ttl (default 60 min) → set new password (live policy validation + strength meter) → all sessions revoked → auth.password.reset_completed. Token reuse/expiry shows a dedicated error screen with “request again.” FR-17 Admin-created users start in invited state with a set-password link (7-day validity, resendable); admins never choose passwords for users.

### 4.5 API tokens (staff + machine)

FR-18 Personal access tokens (Sanctum): name, expiry (30/90/365/none per policy setting), ability scopes selected from the RBAC permission catalog (Doc 03); a token can never exceed its owner’s live permissions (intersection evaluated at request time); plaintext shown once; last-used displayed; individually revocable. FR-19 Machine tokens: admin-created, bound to a service-account user (non-login) with its own role; same scope model; rotation flow: create replacement → overlap window → revoke old (all audited). FR-20 Failures per Doc 01 error format: 401 invalid_token / 401 token_expired / 403 insufficient_scope (naming the missing permission).

### 4.6 Widget visitor tokens

FR-21 First widget load: POST /widget/v1/session with widget key + origin → validated against the widget’s domain allowlist → opaque 128-bit visitor token (Redis, sliding 30-day TTL) bound to {tenant, widget, visitor_id}; authorizes only the widget endpoint subset and that visitor’s own Reverb channels. FR-22 Theft containment: token paired with a rotating secondary cookie; mismatch forces silent re-issue (history preserved server-side via visitor_id, distinct from the token).

### 4.7 Inbound webhook authentication

FR-23 Telegram: per-bot secret verified via the X-Telegram-Bot-Api-Secret-Token header (set at webhook registration); mismatch → 403 + security metric; bot route keys unguessable (Doc 01 SEC-03). FR-24 SMS DLR: per-gateway HMAC of body (or the gateway’s native signature scheme per driver) + replay window ≤ 5 min via timestamp/nonce cache. FR-25 Webhook endpoints: CSRF-exempt, rate-limited per key, never establish sessions.

### 4.8 Auth events (published on the Doc 01 bus)

auth.login.succeeded|failed, auth.logout, auth.session.revoked, auth.2fa.enabled|disabled|reset_by_admin|challenge_failed, auth.password.changed|reset_requested|reset_completed, auth.token.created|revoked|scope_denied, auth.ip.blocked — consumed by Audit (Doc 17) and security monitoring (Doc 18).

## 5. Non-functional Requirements

NFR-01 Login p99 ≤ 400 ms excluding intentional equalization; 2FA verify p99 ≤ 150 ms. NFR-02 Session lookup ≤ 1 ms p99 per request. NFR-03 Password hashing: argon2id tuned to ~100 ms server cost; parameters reviewed annually. NFR-04 Fail-open on enrichment dependencies (GeoIP, breach check) with named metrics; never block auth on them. NFR-05 All auth screens fully functional on mobile browsers. NFR-06 Every FR has automated coverage; enumeration, lockout, and token-scope tests are release gates (Doc 19).

## 6. User Stories

US-01 As an agent, I log in on my phone with email, password, and authenticator code in under 20 seconds. US-02 As an agent who lost my phone, I use a recovery code, then re-enrol 2FA on my new device. US-03 As an admin, I spot an unfamiliar login location on my devices page and revoke that session instantly. US-04 As an operator admin, I require 2FA for Manager+ roles with a 3-day grace period, from settings, without engineering. US-05 As an integration developer, I create a machine token scoped to tickets.read only, and rotate it with zero downtime. US-06 As a security officer, I can show an auditor every login, from where, and every privileged auth change with old/new values. US-07 As a returning web visitor, my chat history is still there a week later without creating an account.

## 7. Business Rules

BR-01 No shared logins; the platform never offers a “team login”; concurrent-session caps and device visibility discourage sharing. BR-02 Admins can never view or set another user’s password or 2FA secret — only trigger resets. BR-03 Machine tokens always act as their visible service account in audit, never as “the system.” BR-04 Disabling a user revokes all their sessions and tokens atomically. BR-05 Auth policies are tenant settings, but platform minimums (password ≥ 10, lockout enabled) cannot be weakened. BR-06 Visitor tokens grant zero access to staff surfaces structurally (separate guard, route group, cookie scope) regardless of any scope misconfiguration.

## 8. UX Requirements

- **Login:** single brand-themed card; states: idle → submitting (button spinner, locked fields) → error (inline, generic) → 2FA step (auto-focus, paste-friendly, auto-submit on 6th digit) → redirect. “Forgot password” always visible; language switcher on card.

- **2FA enrolment wizard:** scan QR → enter code → save recovery codes; the codes step blocks continue until “I have saved these” is checked or the file is downloaded.

- **Devices:** current device pinned first; single revoke confirms inline; “revoke all others” uses a modal.

- **Timeout:** warning toast at T-2 min with “stay signed in”; after expiry, unsaved composer drafts persist locally and restore after re-login (integrates with Doc 07 draft persistence).

- **Invited user:** set-password screen doubles as profile completion (name, language, optional avatar).

- Every error state has a designed i18n copy slot under the auth.* namespace.

## 9. UI Requirements

- Components: brand card; inputs with floating validation; password field with visibility toggle + localized strength meter; 6-digit segmented code input; QR block with copyable secret; recovery-code grid (monospace, copy-all, download); devices table with UA icon + location text.

- Loading: skeletons for tables, spinner-in-button pattern; no full-page blockers.

- Empty state: “You’re only signed in here.”

- Error screens: expired/used link (illustration + request-new); locked account (neutral copy).

- Responsive: single column ≤ 480 px; code input stays 6 segments at ≥ 320 px; touch targets ≥ 44 px.

- Animation: 150 ms step transitions; ≤ 400 ms success check; honors prefers-reduced-motion.

## 10. Technical Requirements

T-01 Fortify drives login/reset/2FA; views themed per Doc 16; Fortify events mapped to §4.8. T-02 Guards: staff (session), api (Sanctum), widget (custom guard); strictly partitioned route groups; middleware order: tenant-resolve → guard → tenant-state → 2FA-enforcement → RBAC (Doc 03). T-03 Device fingerprint = hash(UA family + platform + accept-language + stable client hints); “new device” login triggers an informational notice (toggleable setting). T-04 Sessions in Redis with tenant prefix; revocation = key delete + session.revoked:{id} publish so Reverb closes that session’s sockets. T-05 Token scope = token.scopes ∩ owner.effective_permissions via the Doc 03 resolver, cached 10 s per token. T-06 CSRF on all session routes; CORS: staff app same-origin; widget endpoints CORS-allowlisted per widget config; Bearer API CSRF-exempt. T-07 Security headers everywhere: nonce-based CSP (no unsafe-inline), HSTS 2y preload, X-Content-Type-Options, Referrer-Policy strict-origin-when-cross-origin; frame-ancestors none (staff) / widget allowlist (widget frame). T-08 GeoIP: local MMDB, weekly refresh, in-process lookups only. T-09 Breach check: k-anonymity range query, 500 ms timeout, fail-open with metric. T-10 All auth settings registered under auth.* with platform minimums in validation.

**Login flow (text diagram):**

POST /login → throttle(IP) → find user (tenant-scoped)
 → verify password (argon2id, timing-equalized)
   ├─ fail → count attempt → lockout? → generic error
   └─ ok → user.disabled? tenant.suspended? → blocked screens
        → 2FA enabled?
            ├─ no  → regenerate session id → establish session → device upsert → redirect
            └─ yes → 2FA challenge ── ok → regenerate id → session …
                                   └─ 5× fail → void attempt → back to login

## 11. Security Requirements

SEC-01 No user enumeration anywhere (login, reset, invite) — verified by response-diff and timing tests. SEC-02 Reset/invite tokens: 256-bit, single-use, hashed at rest, bound to email + purpose. SEC-03 Recovery codes hashed (argon2id); displayed exactly once. SEC-04 Session id regenerated at every privilege boundary (post-password, post-2FA) — fixation-proof. SEC-05 IP allowlists (per role, Doc 03) evaluated post-auth pre-session; failures return the generic error and audit auth.ip.blocked. SEC-06 Auth refuses to operate over plain HTTP in any environment. SEC-07 Admin impersonation is deliberately NOT provided in v1; support debugging uses read-only permissions instead. Documented publicly as a security stance. SEC-08 Logs carry trace_id but never passwords, codes, tokens, or secrets — enforced by a CI log-scrubber test with seeded fake credentials.

## 12. Performance Requirements

- Budgets held at the shift-start spike model: 500 agents within 10 minutes (~50 logins/s peak).

- Session middleware ≤ 1 ms p99; token middleware ≤ 2 ms p99 (scope cache hit).

- Session memory ≤ 8 KB each; 10k concurrent ≤ 100 MB Redis.

- Lockout counters and throttles are O(1) Redis operations.

## 13. Accessibility Requirements

WCAG 2.2 AA: labeled inputs with aria-describedby error association; the segmented code input is a single field for assistive tech (segmentation is visual only); focus moves to the step heading on wizard transitions; strength conveyed as text, not color; the QR step exposes the secret as accessible text; complete keyboard operability; role="alert" on error announcements; ≥ 4.5:1 contrast in both themes.

## 14. Localization Requirements

- All auth.* strings i18n-keyed; English + Amharic at launch; operator-overridable via Doc 16; fallback chain per Doc 01.

- Auth emails (invite, reset, new-device) templated per language with tenant branding; deliverability config per tenant sender (SPF/DKIM/DMARC — Doc 18).

- Locale-aware dates on the devices page; GeoIP place names localized where data allows, English fallback.

- RTL-safe markup (logical properties) from day one.

## 15. Edge Cases

EC-01 Phone clock skew → ±1 TOTP step covers ≤30 s; beyond that recovery codes; help copy mentions time sync. EC-02 Session revoked in another tab → next request 401 → re-login modal preserving in-page state. EC-03 Reset link opened on a different device than requested → allowed; new-device notice fires after login. EC-04 Invite expired → self-service resend (rate-limited); old link invalidated. EC-05 User disabled while online → next request 401; sockets closed ≤ 2 s; local drafts preserved. EC-06 Token owner loses a permission → live intersection drops it within the 10 s cache window (documented bound). EC-07 Server clock drift → NTP-disciplined; alert at >5 s (Doc 18); TOTP unaffected below tolerance. EC-08 Widget iframed by a disallowed page while the inner origin is allowed → ancestor check refuses; both origin and ancestor validated. EC-09 Visitor clears cookies → fresh visitor token; old history not auto-linked (linking is a staff action, Doc 08). EC-10 Concurrent 2FA enrolment in two tabs → single-secret-wins; loser gets a clear invalidation error. EC-11 Attacker triggers lockout on a victim email (DoS) → victim can always unlock via password reset; per-IP throttle caps attacker; optional CAPTCHA setting auth.captcha.after_failures.

## 16. Failure Scenarios

FS-01 Redis down → staff auth fail-closed (maintenance screen); webhooks unaffected (no sessions); auto-recovery; runbook in Doc 18. FS-02 Email provider down → resets/invites queued, retry ×6 backoff; UI notes possible delay; queue depth visible to admins. FS-03 Breach-check API down → skipped with metric (never blocks). FS-04 GeoIP corrupt → device rows store IP only; UI shows “location unavailable.” FS-05 Token table bloat → nightly prune of expired tokens with metrics and index plan documented.

## 17. Success Scenarios

SS-01 Shift-start spike authenticates within budget, zero false lockouts. SS-02 Valid-password-no-TOTP attacker establishes zero session state and reads zero data. SS-03 An operator passes a client security review using the devices page, auth settings, and audit exports alone. SS-04 Machine-token rotation with zero failed integration calls during overlap.

## 18. Acceptance Criteria

AC-01 Enumeration: byte-identical bodies and ±50 ms timing across existing/non-existing emails on login and reset. AC-02 Lockout matrix: 1–4 generic, 5th locks, reset unlocks, admin unlock audited. AC-03 2FA: ±1 step accepted, ±2 rejected, 5-failure void proven, recovery code single-use proven. AC-04 Session cap: 6th login evicts oldest; evicted sockets close ≤ 2 s. AC-05 Scope: tickets.read token gets 403 naming tickets.reply on reply; permission loss propagates ≤ 10 s. AC-06 Widget session: allowed origin succeeds; disallowed fails at both CORS and server layers independently. AC-07 Webhook secret mismatch → 403, nothing processed, metric incremented. AC-08 Log scrubber: seeded credentials absent from all sinks. AC-09 Visual regression passes for every §8 state in en + am, light + dark.

## 19. Future Expansion

- SSO (SAML2/OIDC) per tenant with JIT provisioning mapping IdP groups → roles; break-glass local accounts retained.

- WebAuthn/passkeys as 2FA and eventual password replacement (guard design is method-pluggable).

- Step-up auth for high-risk actions (auth.stepup.actions key reserved).

- Adaptive/anomaly auth (impossible travel → forced 2FA) atop existing device/Geo data.

## 20. Risks

R-01 Agent 2FA friction in low-connectivity contexts (High/Medium) → TOTP is offline; printable recovery codes; grace period. R-02 Fail-open paths masking outages (Medium/Medium) → named metrics + alerts per path. R-03 Token sprawl (Medium/High) → default expiries, last-used surfacing, quarterly unused-token report. R-04 Lockout as DoS (Medium/Low) → EC-11 controls; lockout-rate monitoring per tenant. R-05 Octane workers caching stale auth state (Medium/Low) → per-request Redis checks, never worker memory; AC-04 covers.

## 21. Recommendations

- Build guards, middleware order, and the token-scope resolver interface in week 1 alongside Doc 01 — Doc 03 plugs directly into them.

- Ship 2FA at MVP but enforce only for Admin-level roles first; broaden via settings after agent training.

- Publicize the “no impersonation” stance in security docs — it’s a differentiator with operators.

- Invest early in auth email deliverability per tenant; reset emails in spam become tickets about your support tool.

- Freeze auth event names now; Docs 15/17/18 build reports, audit views, and alerts against them.

*End of Document 02. Next in series: Document 03 — RBAC: Roles, Permissions **&** Overrides.*