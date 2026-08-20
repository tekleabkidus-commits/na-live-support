<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_decisions (SCHEMA AMENDMENT 3 item 1, countersigned; Doc 09 FR-20, T-08,
 * NFR-05, SEC-06, EC-05/EC-11, AC-03) — monthly RANGE partitions on created_at,
 * the messages/events precedent (flag 0 of the amendment: FR-20's "(Doc 01
 * schema)" reads as Doc 01's schema CONVENTIONS — no such table exists in
 * Doc 01, verified; Q9 prose-descriptive).
 *
 * Shape decisions, each with its anchor:
 * - layer_hashes NOT NULL (T-02): every prompt layer's version hash INCLUDING
 *   the code-shipped immutable honesty layer's; the T-02 proof asserts it.
 * - Join identifiers WITHOUT FKs (conversation/ticket/customer/message ids):
 *   a high-volume partitioned table referencing hot tables would tax every
 *   write for a constraint the enrichment consumers re-verify by re-reading
 *   truth (the ConsumerRegistry posture); identifiers-only per Q7. tenant_id
 *   keeps its FK, matching messages.
 * - The SEC-06 payload pair (request/response) lives 30 days, then the
 *   REDUCTION JOB (platform role — the Doc 01 SEC-02 retention-job class)
 *   nulls both and stamps payload_hash + payloads_reduced_at. Those two
 *   columns are PLATFORM-ONLY IN BOTH DIRECTIONS (app INSERT and UPDATE both
 *   denied) — privacy by schedule is grant physics, not policy.
 * - outcome/outcome_meta are the ONE legal app mutation (T-08 enrichment).
 * - created_at is ORDERING-RELEVANT (the log renders in order; AC-03's
 *   simulation reads a day): clock_timestamp() default + the R-19a grant.
 * - REVOKE DELETE; retention = partition detach, platform work.
 * - Partition RLS: a parent's policies do not govern direct partition
 *   queries — every partition gets enable+force+policy (the messages lesson;
 *   RlsCoverageTest's pattern sweep). AiDecisionPartitions (Doc 09-owned,
 *   promised here by name) maintains the window via ai:partitions-maintain.
 */
return new class extends Migration
{
    /** Months of future partitions to pre-create (Doc 01 EC-10 convention). */
    private const MONTHS_AHEAD = 3;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ai_decisions (
                id                   uuid        NOT NULL,
                tenant_id            uuid        NOT NULL,
                surface              text        NOT NULL,
                task                 text        NOT NULL,
                provider             text        NOT NULL,
                model                text        NOT NULL,
                config_version_id    uuid,
                layer_hashes         jsonb       NOT NULL,
                conversation_id      uuid,
                ticket_id            uuid,
                customer_id          uuid,
                message_id           uuid,
                endpoint_ref         text,
                language             text,
                intent               text,
                confidence           numeric,
                action               text,
                rg_risk              numeric,
                tokens_in            integer     NOT NULL DEFAULT 0,
                tokens_out           integer     NOT NULL DEFAULT 0,
                latency_ms           integer     NOT NULL DEFAULT 0,
                error_category       text,
                outcome              text,
                outcome_meta         jsonb,
                request_payload      jsonb,
                response_payload     jsonb,
                payload_hash         text,
                payloads_reduced_at  timestamptz,
                created_at           timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at           timestamptz,
                PRIMARY KEY (id, created_at),
                CONSTRAINT ai_decisions_surface_check CHECK (surface IN ('chatbot', 'copilot', 'supervisor')),
                CONSTRAINT ai_decisions_task_check CHECK (task IN (
                    'classify_message', 'chatbot_turn', 'draft_reply', 'improve_text',
                    'summarize_thread', 'translate', 'transcribe_voice', 'ocr_receipt',
                    'generate_subject', 'qa_prescore', 'daily_digest', 'anomaly_scan'
                )),
                CONSTRAINT ai_decisions_error_category_check CHECK (
                    error_category IS NULL OR error_category IN
                    ('invalid_action', 'provider_refusal', 'timeout', 'quota_exhausted', 'provider_error', 'guard_refused')
                )
            ) PARTITION BY RANGE (created_at)
        SQL);

        DB::statement('ALTER TABLE ai_decisions ADD CONSTRAINT ai_decisions_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)');

        // Parent-declared indexes mirror onto every partition, existing and future.
        DB::statement('CREATE INDEX ai_decisions_window_index ON ai_decisions (tenant_id, created_at)');
        DB::statement('CREATE INDEX ai_decisions_conversation_index ON ai_decisions (tenant_id, conversation_id, created_at) WHERE conversation_id IS NOT NULL');
        DB::statement('CREATE INDEX ai_decisions_ticket_index ON ai_decisions (tenant_id, ticket_id) WHERE ticket_id IS NOT NULL');

        $month = Carbon::now('UTC')->startOfMonth()->subMonth();
        for ($i = 0; $i <= self::MONTHS_AHEAD + 1; $i++) {
            $this->createMonthlyPartition($month);
            $month = $month->copy()->addMonth();
        }

        DB::statement('CREATE TABLE ai_decisions_default PARTITION OF ai_decisions DEFAULT');
        $this->protect('ai_decisions_default');

        // APP_WRITABLE_COLUMNS (the deliberate split): INSERT names every
        // column EXCEPT created_at (R-19a), payload_hash and
        // payloads_reduced_at (reduction-job columns, platform-only both
        // directions); UPDATE reaches exactly the enrichment trio.
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ai_decisions FROM app');
        DB::statement(<<<'SQL'
            GRANT INSERT (
                id, tenant_id, surface, task, provider, model, config_version_id,
                layer_hashes, conversation_id, ticket_id, customer_id, message_id,
                endpoint_ref, language, intent, confidence, action, rg_risk,
                tokens_in, tokens_out, latency_ms, error_category, outcome,
                outcome_meta, request_payload, response_payload, updated_at
            ) ON ai_decisions TO app
            SQL);
        DB::statement('GRANT UPDATE (outcome, outcome_meta, updated_at) ON ai_decisions TO app');

        DB::statement('ALTER TABLE ai_decisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_decisions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ai_decisions
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }

    /** Named ai_decisions_YYYY_MM — AiDecisionPartitions mirrors this, load-bearing. */
    private function createMonthlyPartition(Carbon $month): void
    {
        $start = $month->copy()->startOfMonth();
        $name = sprintf('ai_decisions_%s', $start->format('Y_m'));

        DB::statement(sprintf(
            'CREATE TABLE %s PARTITION OF ai_decisions FOR VALUES FROM (%s) TO (%s)',
            $name,
            sprintf("'%s'", $start->toDateTimeString()),
            sprintf("'%s'", $start->copy()->addMonth()->toDateTimeString()),
        ));

        $this->protect($name);
    }

    /** The messages lesson verbatim: every partition carries its own forced policy. */
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
