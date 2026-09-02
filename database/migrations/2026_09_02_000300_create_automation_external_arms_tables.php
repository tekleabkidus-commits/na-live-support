<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 — RIDER 5 (filed 2026-09-02 at S12 step 13, BEFORE this
 * migration — the rider law): the three tables FR-03's external arms need.
 *
 *   automation_webhook_destinations  SEC-02's per-tenant admin-managed allowlist.
 *   automation_webhook_deliveries    EC-13's attempt record (retry ×8 → abandon).
 *   customer_marketing_suppressions  FR-03 suppress — Doc 08 FR-15's [D12] flag,
 *                                    Doc 12-owned state with a reader contract.
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split:
 *   destinations  INSERT (id, tenant_id, name, url, host, created_by);
 *                 UPDATE (name, active, auto_disabled_at, failure_streak, last_error,
 *                         last_failed_at, last_delivered_at, updated_at) —
 *                 url/host IMMUTABLE by grant (the SSRF rules are checked at BIRTH
 *                 and physics keeps them true; a corrected target is a NEW row);
 *                 NO DELETE (deactivate by flag — an audited allowlist).
 *   deliveries    INSERT (id, tenant_id, destination_id, execution_id, rule_id,
 *                         event_name, payload);
 *                 UPDATE (attempts, state, last_status_code, last_error,
 *                         next_attempt_at, delivered_at, updated_at) — payload,
 *                 destination and provenance IMMUTABLE (what was sent is what was
 *                 recorded); NO DELETE (retention = the platform role's).
 *                 (`state`, not `status`: the BR-02 no-bypass grammar over the
 *                 automation namespace refuses any `'status' =>` assignment.)
 *   suppressions  INSERT (id, tenant_id, customer_id, reason, source, rule_ref);
 *                 UPDATE (cleared_at, cleared_by, updated_at) — identity/reason
 *                 immutable; NO DELETE (history retained, the rg_flag posture).
 * created_at is app-DENIED everywhere (R-19b). No FK from deliveries to the
 * executions row: the 90 d executions prune must never be blocked by a delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE automation_webhook_destinations (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                name              text           NOT NULL,
                url               text           NOT NULL,
                host              text           NOT NULL,
                active            boolean        NOT NULL DEFAULT true,
                auto_disabled_at  timestamptz(6),
                failure_streak    integer        NOT NULL DEFAULT 0,
                last_error        text,
                last_failed_at    timestamptz(6),
                last_delivered_at timestamptz(6),
                created_by        uuid           NOT NULL,
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT automation_webhook_destinations_https_check CHECK (url ~ '^https://')
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX automation_webhook_destinations_url ON automation_webhook_destinations (tenant_id, url)');

        DB::statement(<<<'SQL'
            CREATE TABLE automation_webhook_deliveries (
                id               uuid           NOT NULL PRIMARY KEY,
                tenant_id        uuid           NOT NULL REFERENCES tenants (id),
                destination_id   uuid           NOT NULL REFERENCES automation_webhook_destinations (id),
                execution_id     uuid,
                rule_id          uuid,
                event_name       text           NOT NULL,
                payload          jsonb          NOT NULL,
                attempts         integer        NOT NULL DEFAULT 0,
                state            text           NOT NULL DEFAULT 'queued',
                last_status_code integer,
                last_error       text,
                next_attempt_at  timestamptz(6),
                delivered_at     timestamptz(6),
                created_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT automation_webhook_deliveries_state_check CHECK (state IN ('queued', 'delivered', 'abandoned'))
            )
        SQL);
        DB::statement('CREATE INDEX automation_webhook_deliveries_destination ON automation_webhook_deliveries (tenant_id, destination_id, created_at)');
        DB::statement("CREATE INDEX automation_webhook_deliveries_queued ON automation_webhook_deliveries (tenant_id, next_attempt_at) WHERE state = 'queued'");

        DB::statement(<<<'SQL'
            CREATE TABLE customer_marketing_suppressions (
                id          uuid           NOT NULL PRIMARY KEY,
                tenant_id   uuid           NOT NULL REFERENCES tenants (id),
                customer_id uuid           NOT NULL REFERENCES customers (id),
                reason      text           NOT NULL,
                source      text           NOT NULL,
                rule_ref    text,
                cleared_at  timestamptz(6),
                cleared_by  uuid,
                created_at  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT customer_marketing_suppressions_source_check CHECK (source IN ('rule', 'staff'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX customer_marketing_suppressions_live ON customer_marketing_suppressions (tenant_id, customer_id) WHERE cleared_at IS NULL');

        foreach (['automation_webhook_destinations', 'automation_webhook_deliveries', 'customer_marketing_suppressions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
            DB::statement("GRANT SELECT ON {$table} TO app");
        }
        DB::statement('GRANT INSERT (id, tenant_id, name, url, host, created_by) ON automation_webhook_destinations TO app');
        DB::statement('GRANT UPDATE (name, active, auto_disabled_at, failure_streak, last_error, last_failed_at, last_delivered_at, updated_at) ON automation_webhook_destinations TO app');
        DB::statement('GRANT INSERT (id, tenant_id, destination_id, execution_id, rule_id, event_name, payload) ON automation_webhook_deliveries TO app');
        DB::statement('GRANT UPDATE (attempts, state, last_status_code, last_error, next_attempt_at, delivered_at, updated_at) ON automation_webhook_deliveries TO app');
        DB::statement('GRANT INSERT (id, tenant_id, customer_id, reason, source, rule_ref) ON customer_marketing_suppressions TO app');
        DB::statement('GRANT UPDATE (cleared_at, cleared_by, updated_at) ON customer_marketing_suppressions TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customer_marketing_suppressions');
        DB::statement('DROP TABLE IF EXISTS automation_webhook_deliveries');
        DB::statement('DROP TABLE IF EXISTS automation_webhook_destinations');
    }
};
