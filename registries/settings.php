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
];
