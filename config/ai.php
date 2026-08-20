<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| AI provider infrastructure (Doc 09 FR-02, T-01; ADRs 0016/0018/0019)
|------------------------------------------------------------------------------
|
| PROVIDERS here are platform INFRASTRUCTURE (endpoints, vault key refs, the
| egress-proxy pin) — never operator content. Which provider/model serves
| which task TIER is per-tenant operator config: an ai_config_versions
| 'provider_routing' payload (published, versioned, zero-deploy — FR-02);
| `default_routing` below is the seed used when a tenant has none published.
|
| key_ref is a VAULT REFERENCE (C-13/SEC-01): resolved at call time from the
| environment (the A-19 interim — Doc 18's vault replaces resolution, not
| the seam). The key never lives in this file or the database.
*/

return [
    'providers' => [
        'addis' => [
            'driver' => 'openai-compatible',
            'base_url' => env('ADDIS_AI_BASE_URL', 'https://api.addisassistant.com/v1'),
            // The vault reference resolves HERE (config is the one legal
            // env() site — larastan's rule; env is the A-19 interim vault).
            'key' => env('ADDIS_AI_API_KEY'),
            'proxy' => env('AI_EGRESS_PROXY', env('MEDIA_EGRESS_PROXY')),
        ],
        // ADR 0018: the local embeddings sidecar — in-cluster, no external
        // egress, no key; same OpenAI-compatible shape, so it is just
        // another provider to the seam.
        'local-embeddings' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_EMBEDDINGS_BASE_URL', 'http://embeddings:8081/v1'),
            'key' => null,
            'proxy' => null,
        ],
        // T-09: the deterministic record/replay driver CI runs against.
        'replay' => ['driver' => 'replay'],
        // Unit-test scripted driver.
        'fake' => ['driver' => 'fake'],
    ],

    'default_routing' => [
        'fast' => [['provider' => 'addis', 'model' => 'addis-አሌፍ-፩']],
        'strong' => [['provider' => 'addis', 'model' => 'addis-አሌፍ-፩']],
        'embeddings' => [['provider' => 'local-embeddings', 'model' => 'bge-m3']],
    ],

    // T-01 circuit breakers, per provider, PLATFORM scope (an outage is not
    // tenant-shaped): trip after `threshold` failures inside `window_s`,
    // stay open `cooldown_s`, then a probe closes or re-trips.
    'breaker' => [
        'threshold' => 5,
        'window_s' => 60,
        'cooldown_s' => 30,
    ],
];
