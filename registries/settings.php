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
];
