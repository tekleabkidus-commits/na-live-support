<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 items 11–14 (countersigned 2026-09-01): custom-field definitions +
 * options, forms + immutable versions (Doc 12 FR-17/FR-18/FR-20/FR-22, BR-06, EC-06/08).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   field_definitions        INSERT business columns; UPDATE EXACTLY (label, validation,
 *                            agent_editable, customer_visible, position, active,
 *                            deactivated_at, updated_at) — key/type/entity IMMUTABLE BY
 *                            GRANT (FR-18's "type changes prohibited" as column physics);
 *                            NO DELETE (Doc 08 EC-09's retired render needs the row forever).
 *   field_definition_options INSERT business columns; UPDATE (label, position, active,
 *                            label_snapshot, updated_at) — value immutable; NO DELETE.
 *   forms                    INSERT+UPDATE business columns; NO DELETE.
 *   form_versions            INSERT-only — NO UPDATE, NO DELETE (BR-06: an immutable
 *                            version; EC-06's checkpointed flow can never find its schema
 *                            changed); published_at app-DENIED (version-history ordering).
 *
 * "Keys never reused" (FR-18): the unique on (tenant_id, entity, key) deliberately has
 * NO active-scoping — it holds over live AND retired rows, so a retired key can never
 * be re-minted. Stated here so no future author narrows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE field_definitions (
                id               uuid           NOT NULL PRIMARY KEY,
                tenant_id        uuid           NOT NULL REFERENCES tenants (id),
                entity           text           NOT NULL,
                key              text           NOT NULL,
                label            jsonb          NOT NULL DEFAULT '{}'::jsonb,
                type             text           NOT NULL,
                validation       jsonb          NOT NULL DEFAULT '{}'::jsonb,
                agent_editable   boolean        NOT NULL DEFAULT true,
                customer_visible boolean        NOT NULL DEFAULT false,
                position         integer        NOT NULL DEFAULT 0,
                active           boolean        NOT NULL DEFAULT true,
                deactivated_at   timestamptz(6),
                created_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT field_definitions_entity_check CHECK (entity IN ('ticket', 'customer')),
                CONSTRAINT field_definitions_key_check CHECK (key ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT field_definitions_type_check CHECK (type IN (
                    'text', 'number', 'email', 'phone', 'date', 'time', 'checkbox',
                    'radio', 'dropdown', 'multi_select', 'url', 'file', 'rich_text'
                ))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX field_definitions_key_never_reused ON field_definitions (tenant_id, entity, key)');
        DB::statement('CREATE INDEX field_definitions_order ON field_definitions (tenant_id, entity, position)');

        DB::statement(<<<'SQL'
            CREATE TABLE field_definition_options (
                id             uuid           NOT NULL PRIMARY KEY,
                tenant_id      uuid           NOT NULL REFERENCES tenants (id),
                field_id       uuid           NOT NULL REFERENCES field_definitions (id),
                value          text           NOT NULL,
                label          jsonb          NOT NULL DEFAULT '{}'::jsonb,
                position       integer        NOT NULL DEFAULT 0,
                active         boolean        NOT NULL DEFAULT true,
                label_snapshot jsonb,
                created_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX field_definition_options_value ON field_definition_options (tenant_id, field_id, value)');

        DB::statement(<<<'SQL'
            CREATE TABLE forms (
                id                 uuid           NOT NULL PRIMARY KEY,
                tenant_id          uuid           NOT NULL REFERENCES tenants (id),
                name               text           NOT NULL,
                entity_bindings    jsonb          NOT NULL DEFAULT '{}'::jsonb,
                active             boolean        NOT NULL DEFAULT true,
                current_version_id uuid,
                created_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE form_versions (
                id           uuid           NOT NULL PRIMARY KEY,
                tenant_id    uuid           NOT NULL REFERENCES tenants (id),
                form_id      uuid           NOT NULL REFERENCES forms (id),
                version      integer        NOT NULL,
                schema       jsonb          NOT NULL,
                published_by uuid           NOT NULL,
                published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX form_versions_sequence ON form_versions (tenant_id, form_id, version)');
        DB::statement(<<<'SQL'
            ALTER TABLE forms
                ADD CONSTRAINT forms_current_version_fk
                FOREIGN KEY (current_version_id) REFERENCES form_versions (id)
        SQL);

        foreach (['field_definitions', 'field_definition_options', 'forms', 'form_versions'] as $table) {
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

        DB::statement('GRANT INSERT (id, tenant_id, entity, key, label, type, validation, agent_editable, customer_visible, position, active) ON field_definitions TO app');
        DB::statement('GRANT UPDATE (label, validation, agent_editable, customer_visible, position, active, deactivated_at, updated_at) ON field_definitions TO app');

        DB::statement('GRANT INSERT (id, tenant_id, field_id, value, label, position, active) ON field_definition_options TO app');
        DB::statement('GRANT UPDATE (label, position, active, label_snapshot, updated_at) ON field_definition_options TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, entity_bindings, active, current_version_id) ON forms TO app');
        DB::statement('GRANT UPDATE (name, entity_bindings, active, current_version_id, updated_at) ON forms TO app');

        DB::statement('GRANT INSERT (id, tenant_id, form_id, version, schema, published_by) ON form_versions TO app');
        // form_versions: NO UPDATE grant of any kind — BR-06 as physics.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS forms DROP CONSTRAINT IF EXISTS forms_current_version_fk');
        DB::statement('DROP TABLE IF EXISTS form_versions');
        DB::statement('DROP TABLE IF EXISTS forms');
        DB::statement('DROP TABLE IF EXISTS field_definition_options');
        DB::statement('DROP TABLE IF EXISTS field_definitions');
    }
};
