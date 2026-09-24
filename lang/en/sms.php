<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 14 — SMS Notification Module strings (SESSION-14). Every user-visible SMS
| string is a key here (N-03, C-08); en + am from birth. The chip words are the
| §9 state vocabulary (NFR-04: `expired` reads "Sent · unconfirmed" — never a
| guessed delivery). Machine-authored Amharic joins the R-14 queue.
|------------------------------------------------------------------------------
*/

return [
    'chip' => [
        'queued' => 'Queued',
        'held' => 'Held — quiet hours',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'undelivered' => 'Undelivered',
        'expired' => 'Sent · unconfirmed',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
    ],
    'feature' => [
        'enabled' => 'SMS notifications',
    ],
    'notify' => [
        'cost_threshold' => 'SMS spend crossed the monthly threshold',
        'gateway_health' => 'An SMS gateway changed health',
        'staff_security' => 'Security notifications for my account',
    ],
    'module' => [
        'channels_title' => 'SMS',
        'settings_title' => 'SMS settings',
        'off_until_gateway' => 'SMS is off until a gateway exists.',
    ],
    'refused' => [
        'broadcast_template_refused' => 'Only a service announcement template can be broadcast.',
        'broadcast_template_unpublished' => 'Publish the template before pinning it to a broadcast.',
        'broadcast_not_editable' => 'Only a draft or scheduled broadcast can be edited.',
        'broadcast_not_launchable' => 'This broadcast has already launched, finished or been canceled.',
        'broadcast_not_cancelable' => 'Only a scheduled, sending or paused broadcast can be canceled.',
        'broadcast_confirmation_required' => 'Type the broadcast name to confirm sending to an audience this large.',
        'broadcast_monthly_cap' => 'The monthly broadcast cap for this workspace has been reached.',
        'window_not_expired' => 'The reply window is still open — reply in the conversation instead. SMS re-engagement is for expired windows only.',
        'event_disabled' => 'The ticket-updated SMS event is switched off for this workspace.',
        'no_phone' => 'This customer has no phone number on file.',
        'duplicate' => 'The same SMS went out for this ticket inside the duplicate window.',
        'gateway_not_found' => 'That gateway does not exist here.',
        'prefix_invalid' => 'A country prefix is E.164: + and up to fifteen digits.',
        'fixture_refused' => 'A fixture gateway never exists in production.',
        'unknown_driver' => 'The driver is http_generic or smpp_sidecar.',
        'config_secret_key' => 'A credential never rides the mapping — it is stored as a vault reference.',
        'config_invalid' => 'The gateway mapping is incomplete or malformed.',
        'unknown_event' => 'That is not a template event.',
        'body_too_long' => 'The template is empty or over the 1000-character ceiling.',
        'arbitrary_url' => 'A template cannot carry a URL — use {{link}}; links only resolve to the platform’s own pages (SEC-05).',
        'variable_not_allowed' => 'This event does not provide one of the variables used.',
        'link_domain_unset' => '{{link}} needs the short-link domain (SMS settings) before it can publish.',
        'duplicate_template' => 'One template per event per language.',
        'template_not_found' => 'No such template.',
        'version_not_found' => 'No such version.',
        'fixture_phone_required' => 'Outside production a test-send only targets a +251-90000xxxx fixture number.',
    ],
];
