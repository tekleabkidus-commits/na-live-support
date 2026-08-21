<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Settings registry (CLAUDE.md §2, Doc 01 FR-07 / T-04)
|------------------------------------------------------------------------------
|
| No setting may be read or written unless registered here; the settings
| engine (Doc 01, Session 1) loads this file as its source of truth.
|
| Entry schema (all fields required):
|   key             string  dot-namespaced, e.g. "sms.quiet_hours"
|   type            string  one of: "string" | "int" | "bool" | "json" | "encrypted-string"
|   default         mixed   must validate against `type` and `validation`
|   validation      string  Laravel validation rule string, e.g. "required|integer|min:0"
|   tenant_editable bool    false = platform-only setting
|   owning_doc      string  "DocNN"
|
| Reminder (CLAUDE.md §8): keys that would disable AI disclosure, the
| starvation cap, four-eyes erasure approval, or the contrast validator are
| forbidden and will be rejected in review — do not add them.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Platform-owned (tenant_editable: false)
    |--------------------------------------------------------------------------
    |
    | Readable by tenant code — these configure its behaviour — but not writable
    | through any operator surface. An operator must not be able to raise their own
    | quota ceiling or extend their own data-retention window.
    */

    [
        // BR-05: "Suspended tenants accrue inbound data ≤ 30 days; beyond that, inbound
        // is still ACKed to channels but stored in cold summary form … Configurable at
        // platform level." The last sentence is why this is a setting rather than a
        // constant, and `tenant_editable: false` is why it is the PLATFORM's dial.
        'key' => 'tenant.suspended_inbound_retention_days',
        'type' => 'int',
        'default' => 30,
        'validation' => 'required|integer|min:1|max:365',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        // NFR-06 quota ceilings. Per-tenant overrides live in `tenants.quota_overrides`;
        // these are the platform defaults every tenant starts from.
        'key' => 'quota.messages_per_minute',
        'type' => 'int',
        'default' => 600,
        'validation' => 'required|integer|min:1',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        'key' => 'quota.ai_tokens_per_day',
        'type' => 'int',
        'default' => 1_000_000,
        'validation' => 'required|integer|min:0',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        'key' => 'quota.storage_gb',
        'type' => 'int',
        'default' => 50,
        'validation' => 'required|integer|min:1',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        'key' => 'quota.api_requests_per_minute',
        'type' => 'int',
        'default' => 1200,
        'validation' => 'required|integer|min:1',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        // NFR-06: "raises an internal alert at 80%". A dial rather than a literal so
        // Doc 18 can tune alert noise without a deploy — but platform-owned, because an
        // operator silencing their own warnings defeats R-04's transparency argument.
        'key' => 'quota.alert_threshold_percent',
        'type' => 'int',
        'default' => 80,
        'validation' => 'required|integer|min:1|max:99',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        // EC-09: "A tenant's quota lowered below current usage → no destructive action;
        // writes above quota blocked going forward, alert raised, grace policy
        // configurable." This is that grace policy: how long a tenant already over a
        // freshly-lowered ceiling keeps being served before enforcement bites.
        'key' => 'quota.grace_minutes_after_lowering',
        'type' => 'int',
        'default' => 60,
        'validation' => 'required|integer|min:0|max:10080',
        'tenant_editable' => false,
        'owning_doc' => 'Doc01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator-editable (tenant_editable: true)
    |--------------------------------------------------------------------------
    |
    | Rendered by Doc 16's Backoffice. Doc 01 registers only the foundation-owned ones;
    | each later document registers its own.
    */

    [
        // §14's fallback chain: user language → tenant default → English. This is the
        // middle term. Constrained to the seeded reference data (FR-10) rather than to
        // any BCP-47 tag, because a language with no translations is not a choice.
        'key' => 'tenant.default_language',
        'type' => 'string',
        'default' => 'en',
        'validation' => 'required|string|exists:ref_languages,code',
        'tenant_editable' => true,
        'owning_doc' => 'Doc01',
    ],
    [
        // NFR-08/C-03: everything is stored UTC and this is applied at presentation only.
        'key' => 'tenant.display_timezone',
        'type' => 'string',
        'default' => 'Africa/Addis_Ababa',
        'validation' => 'required|string|exists:ref_timezones,code',
        'tenant_editable' => true,
        'owning_doc' => 'Doc01',
    ],
    [
        'key' => 'tenant.currency',
        'type' => 'string',
        'default' => 'ETB',
        'validation' => 'required|string|exists:ref_currencies,code',
        'tenant_editable' => true,
        'owning_doc' => 'Doc01',
    ],

    /*
    |--------------------------------------------------------------------------
    | One encrypted-string, so the type is exercised rather than theoretical
    |--------------------------------------------------------------------------
    */

    [
        // C-13 says the vault holds secrets and the DB holds vault references, and Doc 18
        // owns the vault. Until it exists, the encrypted-string type is the interim
        // storage — and it needs at least one real registered key, or T-04's encryption
        // and key-rotation support would be untested code.
        //
        // The value never appears in an audit payload, an event, or a bulk settings read
        // (see SettingsRepository); only a by-key read returns it.
        'key' => 'tenant.outbound_webhook_secret',
        'type' => 'encrypted-string',
        'default' => '',
        'validation' => 'present|string|max:512',
        'tenant_editable' => true,
        'owning_doc' => 'Doc01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (Doc 02 T-10 — every auth setting under auth.*)
    |--------------------------------------------------------------------------
    |
    | BR-05: auth policies are tenant settings, but PLATFORM MINIMUMS live in the
    | validation rules and cannot be weakened from any operator surface — the
    | settings engine rejects the write. The two lines BR-05 names explicitly:
    | password min length can never go below 10, and lockout can never be
    | disabled (attempts and duration have hard floors, no 0-means-off value
    | exists). CLAUDE.md §8's reminder applies: no key disabling a safety
    | mechanism may ever be added here.
    |
    | Keys and defaults are Doc 02 §4's verbatim: FR-03 lockout, FR-10/FR-11
    | sessions, FR-15/FR-16 password lifecycle, FR-09 2FA policy, FR-18 token
    | expiry policy, T-03 new-device notice, EC-11 captcha threshold.
    |
    */

    [
        'key' => 'auth.lockout.attempts',
        'type' => 'int',
        'default' => 5,
        // Floor 3: below that, ordinary typos lock people out; ceiling 10: above
        // that, lockout stops containing online guessing (FR-03). No off value.
        'validation' => 'required|integer|min:3|max:10',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // Rolling window, minutes (FR-03 default 15 min).
        'key' => 'auth.lockout.window',
        'type' => 'int',
        'default' => 15,
        'validation' => 'required|integer|min:5|max:60',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // Lock duration, minutes (FR-03 default 15 min). Floor 5 keeps lockout
        // real (BR-05); admin unlock and completed reset end it early.
        'key' => 'auth.lockout.duration',
        'type' => 'int',
        'default' => 15,
        'validation' => 'required|integer|min:5|max:1440',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-03: IP throttling runs INDEPENDENTLY of account lockout, before
        // password hashing (SEC-05 pattern), and is the EC-11 attacker cap.
        // Platform-owned: a tenant must not be able to widen a pre-tenant,
        // pre-auth defence that protects the shared login surface.
        'key' => 'auth.throttle.ip_per_minute',
        'type' => 'int',
        'default' => 20,
        'validation' => 'required|integer|min:5|max:120',
        'tenant_editable' => false,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-25's per-key webhook limit (step-11 ruling, decision 4). The
        // spec mandates "rate-limited per key" without a number; T-10 makes
        // the number a registered auth.* setting rather than a constant.
        // Platform-owned — it protects shared ingress capacity; the floor
        // exists so the limit can never be strangled into an outage. Docs
        // 05/14 may re-default per channel when real traffic numbers exist.
        'key' => 'auth.webhook.rate_per_minute',
        'type' => 'int',
        'default' => 600,
        'validation' => 'required|integer|min:60|max:6000',
        'tenant_editable' => false,
        'owning_doc' => 'Doc02',
    ],
    [
        'key' => 'auth.session.max_concurrent',
        'type' => 'int',
        'default' => 5,
        'validation' => 'required|integer|min:1|max:20',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // Idle timeout, minutes (FR-11 default 480 = 8 h shift).
        'key' => 'auth.session.idle_minutes',
        'type' => 'int',
        'default' => 480,
        'validation' => 'required|integer|min:15|max:1440',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // Absolute session lifetime, hours (FR-11 default 72).
        'key' => 'auth.session.absolute_hours',
        'type' => 'int',
        'default' => 72,
        'validation' => 'required|integer|min:1|max:168',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-15 default 12; validation floor 10 is BR-05's named platform
        // minimum, enforced here so no operator surface can weaken it.
        'key' => 'auth.password.min_length',
        'type' => 'int',
        'default' => 12,
        'validation' => 'required|integer|min:10|max:128',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-15: length over composition — class requirement defaults off.
        'key' => 'auth.password.require_classes',
        'type' => 'bool',
        'default' => false,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-15/T-09: k-anonymity breach check, fail-open with metric (FS-03).
        'key' => 'auth.password.breach_check',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-15: disallow reuse of the last N passwords (hashed history).
        'key' => 'auth.password.history',
        'type' => 'int',
        'default' => 5,
        'validation' => 'required|integer|min:0|max:24',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-15: max password age, days; 0 = disabled (spec default).
        'key' => 'auth.password.max_age_days',
        'type' => 'int',
        'default' => 0,
        'validation' => 'required|integer|min:0|max:365',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-16: reset-token validity, minutes.
        'key' => 'auth.password.reset_ttl',
        'type' => 'int',
        'default' => 60,
        'validation' => 'required|integer|min:15|max:1440',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-09: role keys whose members must enrol in 2FA. Role SEMANTICS are
        // Doc 03's; this stores the list Doc 02's enforcement middleware reads
        // through the RoleMembership seam.
        'key' => 'auth.2fa.required_roles',
        'type' => 'json',
        'default' => [],
        'validation' => 'present|array',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        'key' => 'auth.2fa.grace_days',
        'type' => 'int',
        'default' => 3,
        'validation' => 'required|integer|min:0|max:30',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // EC-11: optional CAPTCHA after N failures; 0 = off. The setting exists
        // per spec even though no vendor is wired in v1 — wiring one later is a
        // configuration change, not a schema change.
        'key' => 'auth.captcha.after_failures',
        'type' => 'int',
        'default' => 0,
        'validation' => 'required|integer|min:0|max:20',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // FR-18: whether personal/machine tokens may be created with no expiry
        // ("30/90/365/none per policy setting" — this is the policy half; the
        // three fixed durations are not configurable).
        'key' => 'auth.token.allow_no_expiry',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],
    [
        // T-03: informational notice on login from a new device, toggleable.
        'key' => 'auth.notify.new_device',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc02',
    ],

    /*
    |--------------------------------------------------------------------------
    | Doc 03 — RBAC (S19)
    |--------------------------------------------------------------------------
    */

    [
        // FR-08b/T-06: four-eyes on high-risk grants. Default OFF is the
        // document's own decision (R-05: accepted; in-product tip recommends
        // enabling at ≥3 admins). The EC-08 deadlock guard — refuse to enable
        // without ≥2 users holding approval rights (ruling R-9) — is DYNAMIC
        // and lives in the settings write path (S19 step 8), not in this
        // static rule.
        'key' => 'rbac.four_eyes',
        'type' => 'bool',
        'default' => false,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc03',
    ],
    [
        // BR-02: overrides are exceptions, not policy — warn when more than
        // this many users carry an override for the SAME permission.
        'key' => 'rbac.override.warn_threshold',
        'type' => 'int',
        'default' => 10,
        'validation' => 'required|integer|min:1|max:1000',
        'tenant_editable' => true,
        'owning_doc' => 'Doc03',
    ],
    [
        // BR-04: an editor cannot grant a permission they do not themselves
        // hold. Default off — the single-owner-admin bootstrap pattern.
        'key' => 'rbac.strict_grant',
        'type' => 'bool',
        'default' => false,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc03',
    ],
    [
        // EC-10: soft-limit warning in the roles UI. Platform-only by ruling
        // R-11.3 — the architecture supports 500+; the warning threshold is
        // the PLATFORM's dial, not an operator preference.
        'key' => 'rbac.roles.soft_limit',
        'type' => 'int',
        'default' => 100,
        'validation' => 'required|integer|min:1',
        'tenant_editable' => false,
        'owning_doc' => 'Doc03',
    ],
    [
        // Doc 04 FR-16 token bucket, names filed as interpretation A-5:
        // steady-state refill per channel endpoint. tenant_editable false until
        // Doc 16 rules otherwise (A-5's own text).
        'key' => 'messaging.rate.outbound_per_second',
        'type' => 'int',
        'default' => 25,
        'validation' => 'required|integer|min:1|max:100',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        // A-5: bucket capacity; NFR-02's 50 msg/s burst-absorption figure is
        // the default's source. Over-limit messages WAIT in queued, never drop.
        'key' => 'messaging.rate.outbound_burst',
        'type' => 'int',
        'default' => 50,
        'validation' => 'required|integer|min:1|max:200',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        // NFR-05: the media size cap, enforced IN-STREAM mid-download (EC-02) —
        // never against the Content-Length header alone.
        'key' => 'media.max_mb',
        'type' => 'int',
        'default' => 25,
        'validation' => 'required|integer|min:1|max:100',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        // T-08 retention keys. min:6 IS the platform minimum T-08 demands — a
        // shorter policy would erase betting-dispute evidence (BR-03).
        // Partition drops are GLOBAL, so the retention run reads the PLATFORM
        // value of each key; per-tenant override semantics (if ever) belong to
        // the Doc 18 runbook + Doc 16 settings surface (pre-agreed bucket).
        'key' => 'retention.messages.months',
        'type' => 'int',
        'default' => 13,
        'validation' => 'required|integer|min:6|max:120',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        'key' => 'retention.media.months',
        'type' => 'int',
        'default' => 6,
        'validation' => 'required|integer|min:6|max:120',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        'key' => 'retention.revisions.months',
        'type' => 'int',
        'default' => 13,
        'validation' => 'required|integer|min:6|max:120',
        'tenant_editable' => false,
        'owning_doc' => 'Doc04',
    ],
    [
        // Doc 06 SS-03: all four tickets.* keys are tenant_editable by owner ruling
        // R-21 (US-07's spirit — operator policy). SS-03's change semantics: a change
        // applies to FUTURE resolutions only; armed timers keep their value, and the
        // settings page says exactly that (proven in TicketSettingsTest, step 5).
        // FR-05/BR-03: a reply IS a claim; R-04's per-tenant toggle is this key.
        'key' => 'tickets.implicit_claim',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-06/BR-04: resolution requires substance — a public staff/AI reply or an
        // explicit resolution note. tenant_editable per R-21.
        'key' => 'tickets.resolve.requires_reply',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-07: resolved → closed after this many hours of customer silence.
        // US-07's 72 h example sits inside the range.
        'key' => 'tickets.autoclose.hours',
        'type' => 'int',
        'default' => 48,
        'validation' => 'required|integer|min:1|max:720',
        'tenant_editable' => true,
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-08/EC-02: customer message within this many days of closed_at reopens
        // the same ticket (DB-time comparison, inclusive). US-07's 14 d fits.
        'key' => 'tickets.reopen.days',
        'type' => 'int',
        'default' => 7,
        'validation' => 'required|integer|min:1|max:90',
        'tenant_editable' => true,
        'owning_doc' => 'Doc06',
    ],

    // ── Doc 05 (Telegram) — S23 plan §3; editability per correction C2: set by
    //    what the frozen B6 frames draw, frame cited per entry. A-5's
    //    messaging.rate.* names are the precedent; telegram.* never collides.
    [
        // FR-21: the ~24 h on-behalf reply window. max:24 IS the platform
        // maximum (Telegram's own behavior); BR-05's never-extend-beyond-
        // observed is the calibration code's job, not this clamp's. Drawn
        // operator-editable at B6-S03 D01/D02 (C2 ⇒ true).
        'key' => 'telegram.business.window_hours',
        'type' => 'int',
        'default' => 24,
        'validation' => 'required|integer|min:1|max:24',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-22 DEFINES closing as ≤2 h — the threshold is the document's,
        // not the operator's (correction C1: tenant_editable FALSE). Platform
        // dial retained for a future doc-author ruling only.
        'key' => 'telegram.window.closing_hours',
        'type' => 'int',
        'default' => 2,
        'validation' => 'required|integer|min:1|max:12',
        'tenant_editable' => false,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-22's "optional agent notification" — the option the document
        // gives operators (C1). Drawn at B6-S03 D03 (C2 ⇒ true).
        'key' => 'telegram.window.closing_notify',
        'type' => 'bool',
        'default' => true,
        'validation' => 'required|boolean',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-20: per-bot token bucket. Telegram's global ~30 msg/s ceiling is
        // the platform maximum (J07 locked floor). Drawn at B6-S06 F08 with
        // the J03–J06 per-key row contract (C2 ⇒ true).
        'key' => 'telegram.rate.bot_per_second',
        'type' => 'int',
        'default' => 25,
        'validation' => 'required|integer|min:1|max:30',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-20: per-chat sustained rate — Telegram guidance is 1 msg/s and
        // that IS the platform maximum. Drawn at B6-S06 F08 (C2 ⇒ true).
        'key' => 'telegram.rate.chat_per_second',
        'type' => 'int',
        'default' => 1,
        'validation' => 'required|integer|min:1|max:1',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-20: per-chat burst capacity. Drawn at B6-S06 F08 (C2 ⇒ true).
        'key' => 'telegram.rate.chat_burst',
        'type' => 'int',
        'default' => 3,
        'validation' => 'required|integer|min:1|max:5',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-29: failure-rate %% at which a bot's rollup reads degraded.
        // Drawn at B6-S06 F04 with the per-key row contract (C2 ⇒ true).
        'key' => 'telegram.health.degraded_failure_pct',
        'type' => 'int',
        'default' => 10,
        'validation' => 'required|integer|min:1|max:100',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-29: failure-rate %% at which the rollup reads down. Drawn at
        // B6-S06 F04 (C2 ⇒ true).
        'key' => 'telegram.health.down_failure_pct',
        'type' => 'int',
        'default' => 50,
        'validation' => 'required|integer|min:1|max:100',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // FS-02: a normally-active bot silent longer than this raises the
        // no-inbound heartbeat alert. Drawn at B6-S06 F04 (C2 ⇒ true).
        'key' => 'telegram.health.silent_inbound_minutes',
        'type' => 'int',
        'default' => 60,
        'validation' => 'required|integer|min:5|max:1440',
        'tenant_editable' => true,
        'owning_doc' => 'Doc05',
    ],
    [
        // Doc 07 FR-28: minutes of no activity before auto-Away (with the
        // 60 s cancelable countdown toast). tenant_editable TRUE by the S07
        // plan-approval ruling (operator policy per BR-06/§21 — "the Away
        // timing should be tuned from dogfood data"); no Batch 1/2 frame
        // draws the editing surface — it arrives with Doc 16's settings
        // shell. Platform-maximum validation per the conventions.
        'key' => 'inbox.away.idle_minutes',
        'type' => 'int',
        'default' => 10,
        'validation' => 'required|integer|min:1|max:240',
        'tenant_editable' => true,
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 09 FR-03/§12: the per-tenant concurrent-AI-turn semaphore cap
        // (default 20 per §12's own figure; "tunable"). Platform-maximum
        // validation per the conventions; tenant_editable false — capacity
        // is platform policy, not operator policy.
        'key' => 'ai.concurrency.per_tenant',
        'type' => 'int',
        'default' => 20,
        'validation' => 'required|integer|min:1|max:100',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-21 (amendment flag 1, countersigned): ai.decision.logged
        // reaches the bus SAMPLED; the rate is operational platform policy
        // (per-mille — the settings registry carries no float type), never a
        // config version. Full fidelity lives in ai_decisions.
        'key' => 'ai.decisions.event_sample_permille',
        'type' => 'int',
        'default' => 10,
        'validation' => 'required|integer|min:0|max:1000',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 EC-12: the reserve band the CURRENT turn may finish inside
        // (5% = 50 permille, the document's own figure); platform policy.
        'key' => 'ai.quota.reserve_permille',
        'type' => 'int',
        'default' => 50,
        'validation' => 'required|integer|min:0|max:200',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 T-07/AC-10: the provider rate card — {provider:
        // {input_per_1k, output_per_1k, currency}}; cost = tokens × card.
        // Empty until the owner supplies the current sheet (ADR 0019).
        'key' => 'ai.rate_card',
        'type' => 'json',
        'default' => [],
        'validation' => 'array',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-08: the conversation window the chatbot sees (last N
        // customer/AI messages; notes never — SEC-04). Platform-max validation.
        'key' => 'ai.chatbot.window_messages',
        'type' => 'int',
        'default' => 12,
        'validation' => 'required|integer|min:2|max:50',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
    // Doc 09 EC-09 (S09 step 10): the cooldown between Copilot regenerations
    // — the one value §8/EC-09 leave unspecified ("with cooldown"); the ×3
    // limit and the 10 s debounce/timeout are the document's own constants.
    [
        'key' => 'ai.copilot.regenerate_cooldown_seconds',
        'type' => 'int',
        'default' => 5,
        'validation' => 'required|integer|min:0|max:300',
        'tenant_editable' => false,
        'owning_doc' => 'Doc09',
    ],
];
