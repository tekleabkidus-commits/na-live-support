<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S08 SCHEMA AMENDMENT 2 — the EXPAND release (C-12), countersigned at
 * `a23befe` with four rulings BEFORE this file existed (the B1 split).
 * ANSWERS "S08 SCHEMA AMENDMENT 2" + its COUNTERSIGN RECORD are the analysis
 * of record; this docblock is the pointer, not the argument.
 *
 * EXPAND POSTURE (nothing here breaks the still-live S20 resolver):
 * - customer_identities gains type/value/source/first_seen_at/metadata as
 *   NULLABLE; the (tenant,type,value) unique index is FULL (NULLs are
 *   distinct, so legacy-shaped inserts keep working until the step-2 resolver
 *   rewrite; step 2's ENFORCE migration re-backfills stragglers, then SET NOT
 *   NULL). THIS INDEX IS SABOTAGE #3's SUBJECT (AC-02/BR-04).
 * - customers.created_source stays NULLABLE for the same reason.
 * - customers.phone / customers.username enter DEPRECATION: still written by
 *   the old resolver until step 2; the eventual DROP is GATED per the
 *   amendment's Note-1 paragraph (DeprecatedColumnDropGate — the drop
 *   migration author reads the AMENDMENT, and the gate refuses a drop that
 *   cannot prove every value preserved).
 *
 * R-19b per column (full analysis in the amendment):
 * - ORDERING timestamps (clock_timestamp() default + app column-revoked):
 *   customer_notes.created_at (notes render newest-first),
 *   customer_merge_snapshots.created_at (EC-04's outermost-first chain order),
 *   rg_flag_history.created_at (history/export order). SABOTAGE #14 TERRITORY.
 * - PINNED-PROVENANCE copies (app-writable BY DOCUMENTED DISCIPLINE, each a
 *   byte-copy of its source's DB stamp, proven by test when its writer lands):
 *   customers.last_contact_at (source message's created_at),
 *   customers.rg_flag_set_at (owning rg_flag_history row's created_at),
 *   customers.blocked_at (block operation's audit stamp).
 * - customer_identity_conflicts.created_at + customer_identities.first_seen_at:
 *   hygiene/provenance, app-writable (no ordering key or cursor reads them).
 *
 * GRANT PHYSICS (ADR 0003 / countersign ruling 2 — mutation by column list):
 * - customer_notes: UPDATE reaches exactly (pinned, updated_at) — pinning is
 *   the ONE mutation FR-17 names; body-edit has no document line, so it has
 *   no grant (widening is a filed amendment, not a convenience).
 * - customer_merge_snapshots: UPDATE reaches exactly (undone_at).
 * - rg_flag_history: INSERT-ONLY — UPDATE and DELETE both revoked (AC-05
 *   immutability as physics). CHECK: actor_id IS NULL exactly when source=ai.
 * - customer_identity_conflicts: UPDATE reaches exactly
 *   (resolved_at, resolved_by) — resolution is a MARKER, never row removal.
 * - DELETE revoked on every table here (Doc 01 BR-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        // ── 1. customers: profile, RG, blocking, lifecycle, rollups ─────────
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('language')->nullable();
            $table->text('language_source')->nullable();
            $table->text('country')->nullable();
            $table->text('country_source')->nullable();

            $table->boolean('rg_flag')->default(false);
            $table->uuid('rg_flag_set_by')->nullable();
            $table->timestampTz('rg_flag_set_at', 6)->nullable();
            $table->text('rg_flag_reason')->nullable();

            $table->boolean('blocked')->default(false);
            $table->text('blocked_reason')->nullable();
            $table->uuid('blocked_by')->nullable();
            $table->timestampTz('blocked_at', 6)->nullable();

            $table->uuid('merged_into_id')->nullable();
            $table->text('created_source')->nullable(); // NOT NULL at step 2's enforce

            $table->integer('tickets_count')->default(0);
            $table->integer('open_tickets')->default(0);
            $table->decimal('satisfaction_avg', 3, 2)->nullable();
            $table->integer('satisfaction_count')->default(0);
            $table->integer('contacts_30d_count')->default(0); // ruling 3: window in the NAME; Doc 15 owns the rate ([D15])
            $table->timestampTz('last_contact_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE customers ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX customers_tags_gin ON customers USING gin (tags)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_language_source_check CHECK (language_source IN (\'detected\', \'staff\'))');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_country_source_check CHECK (country_source IN (\'staff\', \'geoip\'))');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_created_source_check CHECK (created_source IN (\'telegram\', \'webchat\', \'staff\', \'api\'))');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_merged_into_fk FOREIGN KEY (merged_into_id) REFERENCES customers (id)');

        // ── 2. customer_identities: the typed evolution (expand half) ───────
        Schema::table('customer_identities', function (Blueprint $table): void {
            $table->text('type')->nullable();          // NOT NULL at step 2's enforce
            $table->text('value')->nullable();         // NOT NULL at step 2's enforce
            $table->text('source')->nullable();        // channel_auto | staff | api (FR-05)
            $table->timestampTz('first_seen_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE customer_identities ADD COLUMN metadata jsonb NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_type_check CHECK (type IN (\'telegram\', \'web\', \'phone\', \'email\'))');
        DB::statement('ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_source_check CHECK (source IN (\'channel_auto\', \'staff\', \'api\'))');

        // BR-04's physics — SABOTAGE #3's SUBJECT. Full index: NULL types
        // (legacy-shaped rows pre-enforce) never collide; typed rows do.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customer_identities_type_value_unique
                ON customer_identities (tenant_id, type, value)
            SQL);

        // ── 3. customer_notes (FR-17) ───────────────────────────────────────
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('customer_id');
            $table->uuid('author_id');          // notes always have a human author
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });
        DB::statement('CREATE UNIQUE INDEX customer_notes_one_pinned ON customer_notes (tenant_id, customer_id) WHERE pinned');
        DB::statement('CREATE INDEX customer_notes_list_index ON customer_notes (tenant_id, customer_id, created_at DESC)');

        // ── 4. customer_merge_snapshots (FR-13/T-03; ruling-4 name) ─────────
        Schema::create('customer_merge_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('survivor_id');
            $table->uuid('merged_id');
            $table->jsonb('payload');           // the FULL re-pointing map (AC-03's bar)
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('undone_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('survivor_id')->references('id')->on('customers');
            $table->foreign('merged_id')->references('id')->on('customers');
        });
        DB::statement('CREATE INDEX customer_merge_snapshots_chain_index ON customer_merge_snapshots (tenant_id, survivor_id, created_at DESC)');

        // ── 5. rg_flag_history (T-05, insert-only) ──────────────────────────
        Schema::create('rg_flag_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->text('action');             // set | cleared
            $table->text('source');             // staff | ai
            $table->uuid('actor_id')->nullable();
            $table->text('reason');
            $table->jsonb('excerpt_ref')->nullable();   // AI triggering excerpt ref ([D09])
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });
        DB::statement('ALTER TABLE rg_flag_history ADD CONSTRAINT rg_flag_history_action_check CHECK (action IN (\'set\', \'cleared\'))');
        DB::statement('ALTER TABLE rg_flag_history ADD CONSTRAINT rg_flag_history_source_check CHECK (source IN (\'staff\', \'ai\'))');
        // actor_id null EXACTLY when the AI set it (amendment item 5).
        DB::statement('ALTER TABLE rg_flag_history ADD CONSTRAINT rg_flag_history_actor_check CHECK ((source = \'ai\') = (actor_id IS NULL))');
        DB::statement('CREATE INDEX rg_flag_history_customer_index ON rg_flag_history (tenant_id, customer_id, created_at DESC)');

        // ── 6. customer_identity_conflicts (correction B2 + ruling 2) ───────
        Schema::create('customer_identity_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('type');
            $table->text('value');              // the shared value itself — PII: every
            // reading door sits inside C-10 + the
            // curated allowlist (ruling 2)
            $table->uuid('keeper_customer_id');
            $table->uuid('loser_customer_id');
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('keeper_customer_id')->references('id')->on('customers');
            $table->foreign('loser_customer_id')->references('id')->on('customers');
        });
        DB::statement('CREATE UNIQUE INDEX customer_identity_conflicts_pair ON customer_identity_conflicts (tenant_id, type, value, loser_customer_id)');

        // ── RLS + grant physics, every new table ────────────────────────────
        foreach (['customer_notes', 'customer_merge_snapshots', 'rg_flag_history', 'customer_identity_conflicts'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tableName} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$tableName}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$tableName} FROM app");
        }

        // APP_WRITABLE_COLUMNS — the deliberate splits (ruling 2's pattern,
        // applied three times). created_at excluded on the three ORDERING
        // tables; included on conflicts (hygiene, filed).
        DB::statement('GRANT INSERT (id, tenant_id, customer_id, author_id, body, pinned, updated_at) ON customer_notes TO app');
        DB::statement('GRANT UPDATE (pinned, updated_at) ON customer_notes TO app');

        DB::statement('GRANT INSERT (id, tenant_id, survivor_id, merged_id, payload, expires_at) ON customer_merge_snapshots TO app');
        DB::statement('GRANT UPDATE (undone_at) ON customer_merge_snapshots TO app');

        DB::statement('GRANT INSERT (id, tenant_id, customer_id, action, source, actor_id, reason, excerpt_ref) ON rg_flag_history TO app');
        // rg_flag_history: NO UPDATE grant, NO DELETE grant — insert-only physics.

        DB::statement('GRANT INSERT (id, tenant_id, type, value, keeper_customer_id, loser_customer_id, created_at) ON customer_identity_conflicts TO app');
        DB::statement('GRANT UPDATE (resolved_at, resolved_by) ON customer_identity_conflicts TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identity_conflicts');
        Schema::dropIfExists('rg_flag_history');
        Schema::dropIfExists('customer_merge_snapshots');
        Schema::dropIfExists('customer_notes');

        DB::statement('DROP INDEX IF EXISTS customer_identities_type_value_unique');
        Schema::table('customer_identities', function (Blueprint $table): void {
            $table->dropColumn(['type', 'value', 'source', 'first_seen_at', 'metadata']);
        });

        DB::statement('DROP INDEX IF EXISTS customers_tags_gin');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'language', 'language_source', 'country', 'country_source',
                'rg_flag', 'rg_flag_set_by', 'rg_flag_set_at', 'rg_flag_reason',
                'blocked', 'blocked_reason', 'blocked_by', 'blocked_at',
                'merged_into_id', 'created_source',
                'tickets_count', 'open_tickets', 'satisfaction_avg',
                'satisfaction_count', 'contacts_30d_count', 'last_contact_at',
                'tags',
            ]);
        });
    }
};
