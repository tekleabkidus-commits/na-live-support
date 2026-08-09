<?php

declare(strict_types=1);

// Doc 05 channel configuration (ADR 0012; SEC-07; ADR 0008).
return [
    // ALL Telegram traffic rides the egress proxy (SEC-07: api.telegram.org
    // only, and only through the allowlist). Same wiring shape as
    // media.egress_proxy; the broken-proxy test pins that no direct-egress
    // fallback exists.
    'egress_proxy' => env('TELEGRAM_EGRESS_PROXY', 'http://egress-proxy:8888'),

    // The Bot API origin. Tests point this at mock-telegram (Session 0 stub:
    // CI and local dev NEVER call api.telegram.org); step-0-style live proofs
    // are the only real-API callers in dev.
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    'timeout_seconds' => 30,
    'connect_timeout_seconds' => 10,
];
