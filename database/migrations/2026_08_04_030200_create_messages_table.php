<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages (Doc 04 FR-05/FR-06/T-01) — monthly RANGE partitions on created_at, per the
 * Doc 01 Phase-22 conventions the events table established. Same two consequences,
 * both deliberate: the PK is (id, created_at) because every unique constraint on a
 * partitioned table must include the partition key (id remains the UUIDv7 identity,
 * C-01), and R-05 applies — ALL access via App\Domain\Conversations\Storage
 * repositories, never ad-hoc Eloquent on partitions.
 *
 * Shape decisions and their spec anchors:
 * - body ≤ 16 KB is a CHECK in octet_length (FR-06) — physics, not validation.
 * - `delivery_state` exists only for direction=out (FR-13's machine governs outbound;
 *   inbound has no delivery lifecycle) — paired CHECK below.
 * - `is_internal` ⇔ kind='note' by CHECK: the row-level mirror of SEC-07's type-level
 *   exclusion. A note row cannot claim to be external; a customer row cannot hide as
 *   internal.
 * - `quarantined` is FR-11's blocked-state inbound flag (stored, no fan-out except
 *   audit; Doc 06 consumes the spam disposition — bucket).
 * - `channel_occurred_at` preserves the channel's clock as METADATA (BR-04/FR-07);
 *   display order is (created_at, id) — receipt-time truth. EC-10's "sent earlier"
 *   line reads this column.
 * - `client_idempotency_key` is T-03's outbound half (Doc 01 T-08 convention). Its
 *   lookup index is NOT unique: a partitioned unique index would have to include
 *   created_at, which cannot span retries across a month boundary — uniqueness is
 *   enforced by the outbound compose path's Redis SETNX + row lookup, tested there.
 * - No unique index on external_message_id either, same partition-key reason; inbound
 *   dedupe is the Redis SETNX 48 h window (FR-10 step 2) and edits arriving with a
 *   known external id become REVISIONS (FR-21), never second rows.
 * - REVOKE DELETE (ADR 0003): tombstones are UPDATEs (deleted_at); rows leave only
 *   when retention drops a whole partition via the migrator (T-08, step 9).
 *
 * DEFAULT partition = safety net, monitored empty (Doc 01 EC-10 pattern); a row there
 * pages, it never "saves the day" silently — FS-04's test drives that path.
 * MessagePartitions (app/Domain/Conversations/Storage) maintains the window and is
 * promised here by name, exactly as the events migration promised EventPartitions.
 */
return new class extends Migration
{
    /** Months of future partitions to pre-create (Doc 01 EC-10 convention). */
    private const MONTHS_AHEAD = 3;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE messages (
                id                      uuid        NOT NULL,
                tenant_id               uuid        NOT NULL,
                conversation_id         uuid        NOT NULL,
                direction               text        NOT NULL,
                author_type             text        NOT NULL,
                author_id               uuid,
                kind                    text        NOT NULL,
                body                    text,
                media                   jsonb       NOT NULL DEFAULT '[]',
                language                text,
                external_message_id     text,
                reply_to_message_id     uuid,
                delivery_state          text,
                delivery_error          jsonb,
                is_internal             boolean     NOT NULL DEFAULT false,
                quarantined             boolean     NOT NULL DEFAULT false,
                edited_at               timestamptz,
                deleted_at              timestamptz,
                client_idempotency_key  text,
                channel_meta            jsonb       NOT NULL DEFAULT '{}',
                channel_occurred_at     timestamptz,
                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz,
                PRIMARY KEY (id, created_at),
                CONSTRAINT messages_direction_check CHECK (direction IN ('in', 'out')),
                CONSTRAINT messages_author_type_check CHECK (author_type IN ('customer', 'agent', 'ai', 'system')),
                CONSTRAINT messages_kind_check CHECK (kind IN ('text', 'photo', 'video', 'voice', 'audio', 'document', 'sticker', 'system', 'note')),
                CONSTRAINT messages_body_size_check CHECK (body IS NULL OR octet_length(body) <= 16384),
                CONSTRAINT messages_note_internal_check CHECK ((kind = 'note') = is_internal),
                CONSTRAINT messages_delivery_state_check CHECK (
                    delivery_state IS NULL OR delivery_state IN
                    ('queued', 'sending', 'sent', 'delivered', 'failed_retryable', 'failed_final', 'canceled')
                ),
                CONSTRAINT messages_outbound_has_state_check CHECK (
                    (direction = 'out' AND delivery_state IS NOT NULL)
                    OR (direction = 'in' AND delivery_state IS NULL)
                )
            ) PARTITION BY RANGE (created_at)
        SQL);

        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_conversation_id_foreign FOREIGN KEY (conversation_id) REFERENCES conversations (id)');

        // messages is a HOT table (ci/migration-safety.php): raw DDL indexes, declared
        // on the parent so Postgres mirrors them onto every partition, existing and
        // future. (tenant, conversation, created_at, id) is the thread-page shape —
        // FR-07's display order IS this index.
        DB::statement('CREATE INDEX messages_thread_index ON messages (tenant_id, conversation_id, created_at, id)');
        DB::statement('CREATE INDEX messages_external_id_index ON messages (tenant_id, external_message_id) WHERE external_message_id IS NOT NULL');
        DB::statement('CREATE INDEX messages_idempotency_index ON messages (tenant_id, client_idempotency_key) WHERE client_idempotency_key IS NOT NULL');
        DB::statement("CREATE INDEX messages_outbound_pending_index ON messages (tenant_id, delivery_state, created_at) WHERE direction = 'out'");

        $month = Carbon::now('UTC')->startOfMonth()->subMonth();
        for ($i = 0; $i <= self::MONTHS_AHEAD + 1; $i++) {
            $this->createMonthlyPartition($month);
            $month = $month->copy()->addMonth();
        }

        DB::statement('CREATE TABLE messages_default PARTITION OF messages DEFAULT');
        $this->protect('messages_default');

        // Tombstones, not deletions (ADR 0003; retention drops partitions as migrator).
        DB::statement('REVOKE DELETE ON messages FROM app');

        DB::statement('ALTER TABLE messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE messages FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON messages
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }

    /** Named messages_YYYY_MM — MessagePartitions mirrors this convention, load-bearing. */
    private function createMonthlyPartition(Carbon $month): void
    {
        $start = $month->copy()->startOfMonth();
        $name = sprintf('messages_%s', $start->format('Y_m'));

        DB::statement(sprintf(
            'CREATE TABLE %s PARTITION OF messages FOR VALUES FROM (%s) TO (%s)',
            $name,
            sprintf("'%s'", $start->toDateTimeString()),
            sprintf("'%s'", $start->copy()->addMonth()->toDateTimeString()),
        ));

        $this->protect($name);
    }

    /**
     * A parent's policies do not govern DIRECT queries against a partition — every
     * partition gets its own enable+force+policy or it is a month-sized hole in
     * NFR-01 (the lesson the RLS migration recorded for events; the messages half of
     * RlsCoverageTest asserts no partition is ever bare).
     */
    private function protect(string $partition): void
    {
        DB::statement("ALTER TABLE {$partition} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$partition} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$partition}
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
        DB::statement("REVOKE DELETE ON {$partition} FROM app");
    }
};
