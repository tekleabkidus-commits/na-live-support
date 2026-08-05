<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Presence;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Models\Conversation;
use App\Support\Cache\TenantCache;

/**
 * FR-18/EC-12: typing, both directions, as VOLATILE STATE with TTL physics.
 *
 * Staff → customer: at most one adapter signal per conversation per 4 s
 * (FR-18's cap), and only where the capability flag says the channel can show
 * it — the throttle is a volatile key whose TTL IS the window, so a burst of
 * keystrokes costs one channel call.
 *
 * Customer → staff: the signal is a volatile key with a 6-second TTL — EC-12's
 * auto-expiry is Redis physics, not a cleanup job, so a Reverb node dying
 * mid-typing CANNOT leave a stuck indicator (the key dies on its own) and
 * repeated signals never stack (same key, refreshed TTL). The step-8 realtime
 * push reads this state; Doc 07 renders it.
 */
final class TypingSignals
{
    /** FR-18: adapter typing signal at most every 4 s while composing. */
    private const STAFF_SIGNAL_WINDOW_SECONDS = 4;

    /** EC-12: customer typing auto-expires ≤ 6 s after the last signal. */
    private const CUSTOMER_TYPING_TTL_SECONDS = 6;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly AdapterRegistry $registry,
    ) {}

    /** Staff is composing: signal the channel, capability-gated + throttled. */
    public function staffComposing(Conversation $conversation): void
    {
        $adapter = $this->registry->adapter($conversation->channel);

        if (! $adapter->capabilities()->supportsTyping || ! $adapter instanceof SupportsTyping) {
            return; // the capability flag drives behavior — no channel conditionals (FR-09)
        }

        $throttleKey = 'typing-signal:'.$conversation->identifier();

        if ($this->cache->get($throttleKey) !== null) {
            return; // inside the 4 s window — the channel already knows
        }

        $this->cache->put($throttleKey, '1', self::STAFF_SIGNAL_WINDOW_SECONDS, 'TTL expiry (the window IS the invalidation)');

        $adapter->sendTyping($conversation->external_chat_id);
    }

    /** A customer typing signal arrived from the channel: record with expiry. */
    public function customerTyping(Conversation $conversation): void
    {
        $this->cache->put(
            'customer-typing:'.$conversation->identifier(),
            '1',
            self::CUSTOMER_TYPING_TTL_SECONDS,
            'TTL expiry (EC-12: auto-clear ≤ 6 s, stuck indicators impossible)',
        );
    }

    /** The read side (step-8 realtime push + EC-12's sync endpoint). */
    public function isCustomerTyping(Conversation $conversation): bool
    {
        return $this->cache->get('customer-typing:'.$conversation->identifier()) !== null;
    }
}
