<?php

declare(strict_types=1);

namespace App\Events\Conversations;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The one broadcast event class (FR-25). Its constructor sites are the two
 * PROTOCOL DOORS — Doc 04's RealtimePusher (conversation/user channels) and
 * Doc 07's ViewDeltaPusher (view/inbox channels, S07 step 3) — each of which
 * runs the v:1 / 32 KB / SEC-06 guards before dispatching. [Comment-only
 * amendment, advisor-approved at the S07 step-3 acceptance: the previous
 * "only constructor site" line predated Doc 07's door. Zero behavior change.]
 * Conversation channels are PRESENCE channels (FR-19: "who is viewing
 * conversation X" powers Doc 07's collision UX); user channels are private.
 */
final class RealtimePush implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $channelName,
        public readonly string $eventName,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return str_contains($this->channelName, '.conversation.')
            ? new PresenceChannel($this->channelName)
            : new Channel('private-'.$this->channelName);
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
