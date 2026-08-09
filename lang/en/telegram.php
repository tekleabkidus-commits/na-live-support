<?php

declare(strict_types=1);

// Doc 05 operator-facing strings (§14: all under telegram.*, en + am).
// Customer-facing bot content is OPERATOR CONTENT (telegram_templates /
// menus), never i18n keys — the §14 boundary. Grows per step; every key
// added here lands in lang/am/telegram.php in the same commit.
return [
    'notify' => [
        'connection_state' => 'Telegram connection paused or disconnected',
        'misconfigured_source' => 'Business messages arriving on a direct-mode bot',
        'connection_pending' => 'Telegram connection awaiting confirmation',
        'connection_flap_digest' => 'Telegram connection instability digest',
        'window_closing' => 'Reply window closing soon',
    ],

    'state' => [
        'pending' => 'Pending',
        'active' => 'Active',
        'paused' => 'Paused',
        'revoked' => 'Revoked',
    ],

    'mode' => [
        'direct' => 'Direct bot',
        'business' => 'Business account',
        'hybrid' => 'Hybrid',
    ],
];
