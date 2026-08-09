<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The adapter-side update router (S23 plan, inherited row 3): one queued hop
 * on `telegram-in` that fans each accepted update to its door — message-
 * bearing updates to Doc 04's NormalizeInbound, lifecycle updates
 * (business_connection, my_chat_member) to the connection machinery,
 * callback_query to the callback path. Non-message updates CANNOT produce a
 * NormalizedInbound, which is why this hop exists adapter-side instead of a
 * core edit.
 *
 * STEP-4 BOUNDARY, stated: routing destinations land with their steps —
 * messages/edits/deletions at step 5, lifecycle at step 6, callbacks at
 * step 5. Until then every update is counted and logged by TYPE ONLY (no
 * payload content — T-07/SEC-03 discipline) and dropped; Telegram redelivers
 * nothing here because dev has no public ingress (A-24), so no real update
 * can reach this gap.
 */
final class RouteTelegramUpdate implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAware;

    private const KNOWN_TYPES = [
        'message', 'edited_message', 'callback_query', 'my_chat_member',
        'business_connection', 'business_message', 'edited_business_message',
        'deleted_business_messages',
    ];

    /** @param array<string, mixed> $update */
    public function __construct(
        public readonly string $botId,
        public readonly array $update,
    ) {
        $this->captureTenant();
        $this->onQueue('telegram-in');
    }

    public function handle(): void
    {
        $type = 'unknown';
        foreach (self::KNOWN_TYPES as $candidate) {
            if (array_key_exists($candidate, $this->update)) {
                $type = $candidate;
                break;
            }
        }

        Log::info('telegram update accepted', [
            'bot_id' => $this->botId,
            'update_type' => $type,
            'update_id' => $this->update['update_id'] ?? null,
        ]);
    }
}
