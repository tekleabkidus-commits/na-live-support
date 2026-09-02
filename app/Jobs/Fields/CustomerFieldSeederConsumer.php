<?php

declare(strict_types=1);

namespace App\Jobs\Fields;

use App\Domain\Fields\Seeding\CustomerFieldSeeder;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P-FIELDS (S12 batch D): the DURABLE tenant.provisioned consumer that lands
 * the Doc 08 betting trio as real customer field definitions — the
 * KnowledgeBaseSeederConsumer shape verbatim (ADR 0011; Doc 01's provisioning
 * step stays its own no-op record; the tenant goes Active, the event fires,
 * this seeds right after). Idempotent on event_id: the seeder skips keys
 * that already exist, so a redelivery is a no-op.
 */
final class CustomerFieldSeederConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(CustomerFieldSeeder $seeder): void
    {
        /** @var object{name: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name']);
        if ($event === null || $event->name !== 'tenant.provisioned') {
            return;
        }
        $seeded = $seeder->seed();
        Log::info('fields.customer_trio.seeded', ['event_id' => $this->eventId, 'seeded' => $seeded]);
    }
}
