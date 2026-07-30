<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox (Doc 01 FR-08, T-03, NFR-04, FS-02, AC-04).
 *
 * Publishing a domain event is an INSERT here, inside the business transaction.
 * Never a direct dispatch (C-06). The two failure modes that motivates:
 *
 *   - dispatch-then-rollback: the queue holds a job for a write that never
 *     happened, and a consumer acts on a ticket that does not exist.
 *   - commit-then-crash: the write landed, the dispatch never ran, and the event
 *     is gone with nothing to detect it. Reporting is then quietly wrong
 *     forever — Doc 01 §21's exact argument for adopting this at MVP scale.
 *
 * Both vanish when the event and the write share one transaction: they commit
 * together or not at all. A relay worker then moves rows to the queues and marks
 * them relayed, resuming from the last relayed id after a crash (FS-02). The
 * relay is at-least-once, so a duplicate delivery is expected and consumers are
 * idempotent on `event_id` (C-09) — that is what makes AC-04's
 * "exactly-once-in-effect" true despite at-least-once transport.
 *
 * ORDERING IS BY `id`, WHICH IS WHY IT MUST BE UUIDv7 (C-01/C-11). The relay
 * cursor is "everything after the last id I relayed". A v4 uuid would make that
 * cursor meaningless and a wall-clock column would make it wrong under skew
 * (EC-08). UUIDv7's time-ordered prefix makes `ORDER BY id` a real order.
 *
 * `payload` obeys SEC-07: ids, not PII, wherever the subscriber can re-fetch.
 * tests/Security/OutboxPayloadMinimizationTest.php enforces that against the
 * event catalog rather than trusting each publisher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();                  // UUIDv7: the relay cursor
            $table->uuid('tenant_id');

            // The domain event's own id, carried into `events` and used by every
            // consumer as its idempotency key (C-09). Distinct from `id`, which
            // identifies this outbox ROW; a re-published event keeps its event_id.
            $table->uuid('event_id');

            $table->string('name', 128);                    // e.g. "tenant.provisioned"
            $table->unsignedSmallInteger('schema_version')->default(1); // R-02: versioned payloads
            $table->jsonb('payload');

            $table->uuid('correlation_id')->nullable();      // C-14: request → jobs → events
            $table->uuid('causation_id')->nullable();        // the event that caused this one

            $table->timestampTz('occurred_at')->default(DB::raw('now()'));
            $table->timestampTz('relayed_at')->nullable();
            $table->unsignedSmallInteger('relay_attempts')->default(0);

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique('event_id');
        });

        // The relay's only hot query: unrelayed rows, in id order. A partial
        // index keeps it the size of the BACKLOG rather than the size of history,
        // so relay cost does not grow with the table.
        DB::statement('CREATE INDEX outbox_unrelayed_index ON outbox (id) WHERE relayed_at IS NULL');
        DB::statement('CREATE INDEX outbox_tenant_id_index ON outbox (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
