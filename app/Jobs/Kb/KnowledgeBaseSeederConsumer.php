<?php

declare(strict_types=1);

namespace App\Jobs\Kb;

use App\Domain\Kb\Seeding\KnowledgeBaseSeeder;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 10 FR-04 / Doc 01 FR-06's KB half (S10 step 8; A-45 AS RULED — the catalog's
 * DECLARED consumer of tenant.provisioned, implemented; this also discharges the Doc 08
 * forward-note on declared-but-unimplemented consumers). Doc 01's provisioning step stays
 * its own no-op record; the tenant goes Active, the event fires, and this durable consumer
 * seeds the starter set right after (the disclosed millisecond asynchrony — accepted at
 * the countersign). Idempotent on event_id: the seeder's fingerprint contract makes any
 * redelivery a no-op.
 */
final class KnowledgeBaseSeederConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(KnowledgeBaseSeeder $seeder): void
    {
        /** @var object{name: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name']);
        if ($event === null || $event->name !== 'tenant.provisioned') {
            return;
        }
        $seeded = $seeder->seed();
        Log::info('kb.starters.seeded', ['event_id' => $this->eventId, 'seeded' => $seeded]);
    }
}
