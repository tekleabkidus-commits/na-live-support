<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 14 — SMS Notification Module wiring (S14 step 4). Operator-facing knobs
| are SETTINGS (registries/settings.php, `sms.*`); this file is the platform's
| own plumbing: the egress proxy every gateway call rides (ADR 0008 — an EMPTY
| value refuses to send, never direct egress), the public base the DLR
| callback URL is built on (the dev stack's own origin; the real public origin
| is row 0b's tunnel — [OA]), and the SMPP sidecar's mTLS material (T-03: a
| PRIVATE HTTP API our own service exposes — not third-party egress, so it is
| called directly with client-certificate auth; the mock sidecar mints the
| CA/server/client certificates into a shared volume at start).
|------------------------------------------------------------------------------
*/

return [
    'egress_proxy' => env('SMS_EGRESS_PROXY', env('MEDIA_EGRESS_PROXY', 'http://egress-proxy:8888')),

    // The origin an aggregator's DLR callback reaches: the dev stack addresses the app as
    // https://app inside the network; production is the public origin (row 0b, [OA]).
    'dlr_callback_base' => env('SMS_DLR_CALLBACK_BASE', 'https://app'),

    'sidecar' => [
        // T-03: the sidecar's mTLS material — CA to verify the sidecar, the client cert/key it requires of us.
        'mtls_dir' => env('SMS_SIDECAR_MTLS_DIR', '/certs-smpp'),
        'timeout_s' => 10,
    ],

    'http' => [
        'timeout_s' => 15,
        'connect_timeout_s' => 5,
    ],

    // FR-09: consecutive gateway-level failures that trip the circuit, and the open→half_open cooldown.
    'circuit' => [
        'trip_after_failures' => 5,
        'failure_window_s' => 60,
        'half_open_after_s' => 30,
    ],
];
