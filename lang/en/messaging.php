<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Messaging strings — English (Doc 04 §8, §14, FR-14; C-08; invariant N-03)
|------------------------------------------------------------------------------
|
| The delivery reason codes ride events and rows as stable identifiers
| (DeliveryFailureReason values); these keys are the guidance the UI maps each
| code to (§8's table). Rendering surfaces are Doc 07's — the KEYS are Doc 04's
| session obligation, en + am from birth (§14).
|
| The `sms` mention in blocked_by_user follows §8's own guidance text; SMS is
| notification-only (N-15) and the phrase suggests a channel, never a chat.
|
*/

return [
    'fail' => [
        'blocked_by_user' => 'The customer has blocked this channel — consider an SMS notification instead.',
        'window_expired' => 'The reply window for this channel has closed.',
        'invalid_content' => 'The channel rejected this content as invalid.',
        'rate_limited' => 'The channel is rate-limiting sends; delivery will retry automatically.',
        'network_error' => 'A network error interrupted delivery; delivery will retry automatically.',
        'channel_unavailable' => 'The channel is currently unavailable; delivery will retry automatically.',
        'too_large' => 'The attachment exceeds the configured limit of :max_mb MB.',
        'unknown' => 'Delivery failed for an unrecognized reason.',
    ],
    'state' => [
        'queued' => 'Queued',
        'sending' => 'Sending…',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'failed_retryable' => 'Retrying…',
        'failed_final' => 'Failed',
        'canceled' => 'Canceled',
    ],
    'cancel_reason' => [
        'conversation_blocked' => 'Canceled because the conversation was blocked before sending.',
    ],
    'draft' => [
        'updated_elsewhere' => 'This draft was updated on another device; showing the newer version.',
    ],
    'transcript' => [
        'watermark' => 'Exported by :name at :at',
        'deleted_tombstone' => '[message deleted]',
    ],
    'lane' => [
        'skipped' => 'A stuck message was set aside so newer replies could send; it will keep retrying.',
    ],
];
