<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 items 15–16 (countersigned 2026-09-01): CSAT surveys + responses
 * (Doc 12 FR-24/FR-25, T-08, SEC-05, EC-11, BR-07).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   surveys          INSERT business columns; UPDATE EXACTLY (expired_at) — the sweep's
 *                    one flip (EC-11); sent_at app-DENIED (metric); NO DELETE.
 *                    token_hash only — the raw unguessable token is SENT, never stored
 *                    (the Doc 02 recovery-code precedent); expires_at MATERIALIZED at
 *                    arming (SS-03's settings-change immunity).
 *   survey_responses INSERT (id, tenant_id, survey_id, score, comment); UPDATE (score,
 *                    comment, edited_at) — the FR-25 1 h edit window is enforced at the
 *                    door by DB-time comparison; responded_at app-DENIED (Doc 15's CSAT
 *                    metric instant); NO DELETE. survey_id UNIQUE: single response per
 *                    survey as table physics (SEC-05; sabotage (g)'s target — a second
 *                    INSERT dies on the unique, not on discipline).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE surveys (
                id                  uuid           NOT NULL PRIMARY KEY,
                tenant_id           uuid           NOT NULL REFERENCES tenants (id),
                ticket_id           uuid           NOT NULL REFERENCES tickets (id),
                customer_id         uuid           NOT NULL REFERENCES customers (id),
                resolution_event_id uuid           NOT NULL,
                resolver_type       text           NOT NULL,
                token_hash          text           NOT NULL,
                channel             text           NOT NULL,
                sent_at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                expires_at          timestamptz(6) NOT NULL,
                expired_at          timestamptz(6),
                created_at          timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT surveys_resolver_type_check CHECK (resolver_type IN ('human', 'ai'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX surveys_one_per_resolution ON surveys (tenant_id, resolution_event_id)');
        DB::statement('CREATE UNIQUE INDEX surveys_token_hash ON surveys (token_hash)');
        DB::statement('CREATE INDEX surveys_cooldown ON surveys (tenant_id, customer_id, sent_at DESC)');
        DB::statement('CREATE INDEX surveys_expiry_sweep ON surveys (tenant_id, expires_at) WHERE expired_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE survey_responses (
                id           uuid           NOT NULL PRIMARY KEY,
                tenant_id    uuid           NOT NULL REFERENCES tenants (id),
                survey_id    uuid           NOT NULL REFERENCES surveys (id),
                score        smallint       NOT NULL,
                comment      text,
                responded_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                edited_at    timestamptz(6),
                created_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT survey_responses_score_check CHECK (score >= 1 AND score <= 5)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX survey_responses_single ON survey_responses (survey_id)');

        foreach (['surveys', 'survey_responses'] as $table) {
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

        DB::statement('GRANT INSERT (id, tenant_id, ticket_id, customer_id, resolution_event_id, resolver_type, token_hash, channel, expires_at) ON surveys TO app');
        DB::statement('GRANT UPDATE (expired_at) ON surveys TO app');

        DB::statement('GRANT INSERT (id, tenant_id, survey_id, score, comment) ON survey_responses TO app');
        DB::statement('GRANT UPDATE (score, comment, edited_at) ON survey_responses TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS survey_responses');
        DB::statement('DROP TABLE IF EXISTS surveys');
    }
};
