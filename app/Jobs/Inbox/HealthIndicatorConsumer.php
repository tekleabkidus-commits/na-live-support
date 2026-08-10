<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Domain\Inbox\Health\ChannelHealthState;
use App\Domain\Inbox\Realtime\ViewDeltaPusher;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * `channel.health.changed` v2's FIRST consumer (inherited row 5): the FR-03
 * header indicator's feed. A ConsumerRegistry entry (ADR 0011) — never a
 * relay edit — with the event's first consumer-contract fixture
 * (fixtures/inbox/consumers/doc07-events.json, enforced by
 * InboxEventConsumerContractsTest).
 *
 * C-09 — HOW this consumer is idempotent on event_id: its one effect is
 * "latest status per endpoint" (a hash-field SET) plus an ephemeral push;
 * re-applying the same transition sets the same state, and the push is
 * render-idempotent (the indicator shows state, it never counts frames).
 * TRANSITION-ONLY is CONSUMED (A-22): the payload IS the transition; nothing
 * here infers one from heartbeats — proven structurally and behaviourally in
 * HealthConsumerTest, with the FS-02-style kill/re-drive drill.
 */
final class HealthIndicatorConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(ChannelHealthState $state, ViewDeltaPusher $pusher): void
    {
        /** @var object{id: string, tenant_id: string, name: string, schema_version: int, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }

        /** @var array{tenant_id: string, channel: string, channel_ref_id: string, status: string} $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);

        $state->set($payload['channel'], $payload['channel_ref_id'], $payload['status']);

        // FR-03: degraded/down shows the colored dot + banner; recovery clears
        // it — the indicator RENDERS state (step 8), this push is the signal.
        $pusher->toInbox('inbox.channel_health', [
            'type' => 'channel_health',
            'channel' => $payload['channel'],
            'channel_ref_id' => $payload['channel_ref_id'],
            'status' => $payload['status'],
        ]);
    }
}
