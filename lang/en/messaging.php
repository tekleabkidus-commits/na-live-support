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
        'connection_paused' => 'The connected support account has paused this bot — reconnect it from the phone to resume replies.',
        'rate_limited' => 'The channel is rate-limiting sends; delivery will retry automatically.',
        'network_error' => 'A network error interrupted delivery; delivery will retry automatically.',
        'channel_unavailable' => 'The channel is currently unavailable; delivery will retry automatically.',
        'credentials_unavailable' => 'Channel credentials are temporarily unavailable; delivery will retry automatically.',
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
    // Doc 04 FS-05 — the dead-letter ops view (R-17 class-2 fill).
    'dead_letters' => [
        'title' => 'Parked messages',
        'breadcrumb' => 'Conversations › Parked messages',
        'intro' => 'Incoming messages that failed processing three times are parked here with their full content. Nothing is lost — re-drive sends a message back through processing.',
        'empty' => 'No parked messages. Incoming messages are flowing normally.',
        'col_parked_at' => 'Parked',
        'col_channel' => 'Channel',
        'col_error' => 'Error',
        'col_attempts' => 'Attempts',
        'col_actions' => 'Actions',
        'payload_show' => 'Show raw payload',
        'payload_hide' => 'Hide raw payload',
        'redrive' => 'Re-drive',
        'redrive_confirm' => 'Send this message back through processing? If it fails three more times it will park here again.',
        'redrive_yes' => 'Yes, re-drive',
        'redrive_cancel' => 'Cancel',
        'redriven_ok' => 'Message re-driven.',
        'not_parked' => 'This item was already re-driven — likely by another operator just now.',
        'load_more' => 'Load more',
    ],
];
