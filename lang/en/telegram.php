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

    'callback' => [
        'neutral_toast' => 'This button has expired — send the message again or type /menu.',
    ],

    'mode' => [
        'direct' => 'Direct bot',
        'business' => 'Business account',
        'hybrid' => 'Hybrid',
    ],

    // Doc 05 §14 — admin UI copy for the B6-S01/S02/S03 screens (step 10).
    'ui' => [
        'bots' => [
            'title' => 'Telegram bots',
            'subtitle' => 'Register bots, choose a connection method, and watch their health.',
            'empty_title' => 'No bots yet',
            'empty_body' => 'Add your first bot with its BotFather token to start receiving Telegram messages.',
            'col_bot' => 'Bot',
            'col_mode' => 'Mode',
            'col_health' => 'Health',
            'col_connections' => 'Connections',
            'mode_direct' => 'Direct bot',
            'mode_business' => 'Business account',
            'mode_hybrid' => 'Hybrid',
            'health_healthy' => 'Healthy',
            'health_degraded' => 'Degraded',
            'health_down' => 'Down',
            'wizard_title' => 'Add a bot',
            'token_label' => 'BotFather token',
            'token_placeholder' => 'Paste the token — it is never shown again',
            'token_rejected' => 'That token was rejected by Telegram. Check it and try again.',
            'token_validated' => 'Token validated and webhook verified.',
            'mode_title' => 'Connection method',
            'mode_recommended' => 'recommended',
            'mode_help_direct' => 'Players message the bot itself with commands and menus.',
            'mode_help_business' => 'Players message a human-looking support account; the bot bridges it.',
            'mode_help_hybrid' => 'Both lines active — one customer, two conversations.',
        ],
        'connections' => [
            'step_botfather' => 'Enable Business Mode in BotFather',
            'step_phone' => 'On the support phone: add the bot as a chatbot',
            'step_confirm' => 'Confirm the account here',
            'title' => 'Business connections',
            'checklist_title' => 'Connect a support account',
            'step_done' => 'Done',
            'step_active' => 'In progress',
            'step_pending' => 'Not started',
            'confirm_title' => 'Confirm this is our account',
            'confirm_account' => 'Connect the account',
            'confirm_type_label' => 'Type the account name to confirm',
            'confirm_yes' => 'Yes, connect',
            'confirm_reject' => 'Reject',
            'state_pending' => 'Pending',
            'state_active' => 'Active',
            'state_paused' => 'Paused',
            'state_revoked' => 'Revoked',
            'col_account' => 'Account',
            'col_state' => 'State',
            'col_last_inbound' => 'Last inbound',
            'col_sends_blocked' => 'Sends blocked',
        ],
        'window' => [
            'title' => 'Reply window',
            'readonly_notice' => 'Editing the reply window needs a larger screen. Values are read-only here.',
            'window_hours_label' => 'Window length (hours)',
            'window_hours_help' => 'How long you can reply on behalf of the account after a customer writes.',
            'window_hours_bounds' => 'Minimum 1, maximum 24 — the platform ceiling matches Telegram behaviour.',
            'preview_label' => 'Preview',
            'chip_closes_in' => 'Closes in',
            'chip_expired' => 'Window expired',
            'chip_no_window' => 'No window',
            'calibration_title' => 'Calibration record',
            'calibration_observed' => 'Observed window',
            'calibration_none' => 'No calibration yet — the window learns from the first early closure.',
            'simulate_expiry' => 'Simulate window expiry',
        ],
    ],
];
