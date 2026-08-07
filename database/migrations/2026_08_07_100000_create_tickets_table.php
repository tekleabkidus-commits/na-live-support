<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets (Doc 06 FR-01) — the bounded unit of support work inside a permanent
 * conversation. Field list filed as interpretation A-7 (FR-01's "Phase-22 schema"
 * reference resolves to nothing per ruling Q1; this row derives from Doc 06's own text
 * plus Doc 01/Doc 04 conventions and nothing else).
 *
 * Shape decisions and their spec anchors:
 * - The 8 statuses are PLATFORM-FIXED (R-01) and enforced as a CHECK: a ninth status is
 *   a schema change with a series-wide review, never a data drift.
 * - T-02: the one-open-family-ticket invariant is the partial unique index
 *   `tickets_one_open_family` — physics BEHIND TicketTransitionService's check (§21:
 *   the one hotfix that bypasses the service must hit the index, not create a
 *   duplicate-ticket incident). `linked_work = true` rows (split targets, FR-21) are
 *   the ONE sanctioned multi-open case and are excluded from the index; their cap of 3
 *   is the service's job.
 * - R-19b (ADR 0010): every ordering- or metric-bearing timestamp is DATABASE time from
 *   birth. `created_at` and `last_status_changed_at` default to clock_timestamp() (per
 *   row, never transaction-start now()); the FR-24 metric columns are stamped by the
 *   R-20 transition trigger (lands with the transition service, step 2). The app role
 *   cannot NAME any of these columns — INSERT and UPDATE both column-revoked below —
 *   so no code path can ever supply an app-clock value (R-19a physics, not discipline).
 * - `waiting_customer_seconds` / `waiting_staff_seconds` are integers, not timestamps,
 *   but they are REPORTED METRICS derived from time (FR-24) — same grant treatment, so
 *   only the trigger's DB-time arithmetic can move them.
 * - `spam_marked_at` drives EC-09's 7-day terminality boundary (a DB-time comparison),
 *   so it is in the granted set; `spam_marked_by` is an actor id and stays app-written.
 * - BR-01: tickets are never deleted in v1 — REVOKE DELETE (ADR 0003 grant physics).
 *   `tickets.delete` exists in the catalog and is platform-disabled; Doc 17's erasure
 *   workflow is the only future path.
 * - `department_id` has NO foreign key: departments are Doc 11's structures (the
 *   MembershipSource seam is deliberately fail-closed until then). `channel_unreachable`
 *   is Doc 05's field, schema-only here (BR-07/EC-10 orthogonality rules only).
 * - `parent_ticket_id` is RESERVED (§19 problem/incident parents) — no v1 code reads it.
 * - `tags` is a text[] with a GIN index (FR-19/T-07's rewrite target).
 * - tickets is a HOT table (ci/migration-safety.php): all indexes via raw DDL.
 */
return new class extends Migration
{
    /**
     * Every column of `tickets` EXCEPT the DB-time/metric set — what the app may INSERT
     * and UPDATE. Kept explicit rather than derived: a new column must be added here
     * deliberately, and forgetting fails loudly on the first write instead of silently
     * re-opening the R-19 hole. TicketGrantPhysicsTest derives the same split from
     * information_schema and fails if the two ever disagree.
     */
    private const APP_WRITABLE_COLUMNS = [
        'id', 'tenant_id', 'conversation_id', 'customer_id', 'number',
        'subject', 'subject_source', 'status', 'priority', 'source',
        'assignee_id', 'department_id', 'escalation', 'pending_reason',
        'spam_marked_by', 'reopened_count', 'channel_unreachable', 'linked_work',
        'origin_ticket_id', 'previous_ticket_id', 'merged_into', 'parent_ticket_id',
        'form_data', 'tags', 'updated_at',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('conversation_id');
            $table->uuid('customer_id');
            $table->bigInteger('number');       // FR-02: per-tenant, from tenant_sequences

            $table->text('subject')->nullable();        // FR-03: optional at creation
            $table->text('subject_source')->nullable(); // ai|staff
            $table->text('status')->default('new');
            $table->text('priority')->default('normal');
            $table->text('source');                     // FR-01's creation-path enum

            $table->uuid('assignee_id')->nullable();
            $table->uuid('department_id')->nullable();  // Doc 11's structure — no FK
            $table->jsonb('escalation')->nullable();    // FR-01 {path, reason, target, at}
            $table->text('pending_reason')->nullable();

            $table->uuid('spam_marked_by')->nullable();
            $table->timestampTz('spam_marked_at', 6)->nullable();
            $table->integer('reopened_count')->default(0);
            $table->boolean('channel_unreachable')->default(false); // Doc 05, schema-only
            $table->boolean('linked_work')->default(false);         // FR-21 split targets

            $table->uuid('origin_ticket_id')->nullable();   // FR-21: split origin
            $table->uuid('previous_ticket_id')->nullable(); // FR-08: beyond-window successor
            $table->uuid('merged_into')->nullable();        // FR-20/EC-03: transitive chain
            $table->uuid('parent_ticket_id')->nullable();   // §19 RESERVED

            $table->jsonb('form_data')->nullable();

            // FR-24 metric columns — stamped ONLY by the R-20 trigger (step 2), DB time.
            $table->timestampTz('first_response_at', 6)->nullable();
            $table->timestampTz('agent_first_response_at', 6)->nullable();
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->bigInteger('waiting_customer_seconds')->default(0);
            $table->bigInteger('waiting_staff_seconds')->default(0);
            $table->timestampTz('last_status_changed_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()')); // R-19b
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('conversation_id')->references('id')->on('conversations');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('assignee_id')->references('id')->on('users');
            $table->foreign('spam_marked_by')->references('id')->on('users');
        });

        // Self-referencing FKs go AFTER create: Laravel appends fluent-index commands
        // (the ->primary() above) last, so an in-closure self-FK would ALTER before
        // tickets(id) has its unique constraint and die with SQLSTATE 42830.
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_origin_ticket_id_foreign FOREIGN KEY (origin_ticket_id) REFERENCES tickets (id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_previous_ticket_id_foreign FOREIGN KEY (previous_ticket_id) REFERENCES tickets (id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_merged_into_foreign FOREIGN KEY (merged_into) REFERENCES tickets (id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_parent_ticket_id_foreign FOREIGN KEY (parent_ticket_id) REFERENCES tickets (id)');

        // FR-19: free-form per-tenant tags. text[] has no schema-builder type — raw DDL.
        DB::statement("ALTER TABLE tickets ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated', 'resolved', 'closed', 'spam'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_source_check CHECK (source IN ('ai_handoff', 'agent', 'automation', 'api', 'offline_form'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_subject_source_check CHECK (subject_source IS NULL OR subject_source IN ('ai', 'staff'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_pending_reason_check CHECK (pending_reason IS NULL OR pending_reason IN ('awaiting_customer', 'awaiting_internal:department', 'awaiting_internal:named_user', 'awaiting_internal:external'))");

        // FR-02: numbers are per-tenant unique and never reused.
        DB::statement('CREATE UNIQUE INDEX tickets_tenant_number_unique ON tickets (tenant_id, number)');

        // T-02: THE open-family invariant, as index physics behind the service check.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tickets_one_open_family
                ON tickets (conversation_id)
             WHERE status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')
               AND linked_work = false
            SQL);

        DB::statement('CREATE INDEX tickets_tenant_status_index ON tickets (tenant_id, status)');
        DB::statement('CREATE INDEX tickets_tenant_assignee_index ON tickets (tenant_id, assignee_id)');
        DB::statement('CREATE INDEX tickets_conversation_index ON tickets (tenant_id, conversation_id)');
        DB::statement('CREATE INDEX tickets_tags_gin ON tickets USING gin (tags)');

        // BR-01 as physics (ADR 0003).
        DB::statement('REVOKE DELETE ON tickets FROM app');

        // R-19a/R-19b: the app role cannot NAME the DB-time/metric columns, either door.
        $columns = implode(', ', self::APP_WRITABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON tickets FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON tickets TO app");
        DB::statement('REVOKE UPDATE ON tickets FROM app');
        DB::statement("GRANT UPDATE ({$columns}) ON tickets TO app");

        DB::statement('ALTER TABLE tickets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tickets FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON tickets
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
