<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 12 — Automation (S12 step 13)
|--------------------------------------------------------------------------
|
| SEC-02: every outbound webhook rides the egress proxy (the Doc 04 SEC-03
| family; the same default as media.egress_proxy / telegram.egress_proxy). An
| EMPTY value would mean direct egress — WebhookDeliveries refuses to send at
| all in that case, and WebhookEgressTest pins exactly that. The retry schedule
| (EC-13: ×8) is a code constant on WebhookDeliveries, like the guard caps
| (BR-05) — a platform limit, not a setting.
|
*/

return [
    'webhook' => [
        'egress_proxy' => env('AUTOMATION_EGRESS_PROXY', 'http://egress-proxy:8888'),
        'timeout_s' => (int) env('AUTOMATION_WEBHOOK_TIMEOUT_S', 10),
    ],
];
