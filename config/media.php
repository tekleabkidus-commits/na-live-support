<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Media chain infrastructure endpoints (Doc 04 T-04, SEC-02/SEC-03; ADR 0008)
|------------------------------------------------------------------------------
|
| INFRA config, not settings: none of these is a tenant dial (the tenant-facing
| knob — media.max_mb — lives in registries/settings.php per NFR-05). None is a
| secret either (C-13: secrets live in the vault; a proxy URL and a clamd host
| are topology).
|
*/

return [
    // SEC-03: every media fetch goes THROUGH this proxy; the proxy's filter
    // file is the allowlist (docker/egress-proxy/filter). Empty string would
    // mean direct egress — the SEC-03 test exists to catch exactly that.
    'egress_proxy' => env('MEDIA_EGRESS_PROXY', 'http://egress-proxy:8888'),

    'clamd' => [
        'host' => env('CLAMD_HOST', 'clamav'),
        'port' => (int) env('CLAMD_PORT', 3310),
        // Connect + verdict budget per scan; NFR-05's ≤10 s p95 governs.
        'timeout_seconds' => (int) env('CLAMD_TIMEOUT', 20),
    ],

    // The cookie-less object-store disk (SEC-02). MinIO in dev; the production
    // media domain/CDN wiring is Doc 18's (ADR 0008 consequences).
    'disk' => env('MEDIA_DISK', 'media'),

    // SSE-S3 requires a KMS behind the store; dev MinIO runs without one, so
    // the header is flag-gated. Production turns it on (Doc 18, T-04's SSE).
    'sse' => (bool) env('MEDIA_SSE', false),

    // T-04: signed URL lifetime; re-issue on demand is the read path's job.
    'signed_url_ttl_seconds' => 600,

    // EC-11: how long an item may sit in `scanning` before it becomes
    // quarantined_pending with a staff notice.
    'scan_hold_minutes' => 15,
];
