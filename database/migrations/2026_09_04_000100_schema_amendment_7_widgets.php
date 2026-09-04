<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 7 (Doc 13, countersigned at S13 step 2a with conditions C1–C4 folded here): the widget's own
 * tables + the expand-contract growth of Doc 02's `widgets` (its docblock's own
 * sanction; the model growth is packet P-WIDGET).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   widgets                  Doc 02 left TABLE-LEVEL INSERT/UPDATE (only DELETE revoked) — the
 *                            amendment converts it to COLUMN grants so C1 can bite: every Doc 02
 *                            column incl. the hygiene pair stays app-writable (the Eloquent model
 *                            writes them); the new business columns are granted EXCEPT
 *                            identity_secret_rotated_at, which is the trigger's (C1, C-11: the
 *                            24 h overlap window is computed from it — never the app's clock);
 *                            UPDATE denies id/tenant_id/created_at; key_id stays updatable (a
 *                            Doc 01 key rotation re-points it); still NO DELETE.
 *   C2 (widget_health_counters.day): computed IN SQL from clock_timestamp() AT TIME ZONE the
 *                            tenant's display timezone inside the upsert — never a PHP date.
 *                            blocked_origins is DISPLAY-ONLY, last-writer-wins by design.
 *   C4 (ref_widget_bundles): the local widget:bundle-* console commands run on the platform
 *                            connection with the ACTOR LOGGED, local/testing/ci only; production
 *                            writes are the release pipeline's ([D18]).
 *   widget_config_versions   INSERT-only — NO UPDATE, NO DELETE (FR-20: an immutable
 *                            publish; rollback = a NEW row + the widgets pointer flip);
 *                            published_at app-DENIED (history ordering, DB time).
 *   widget_visitor_contexts  INSERT + UPDATE (business columns + updated_at); NO DELETE
 *                            (erasure is Doc 17's); captured_at app-DENIED, re-stamped
 *                            by a fill trigger on UPDATE (R-20: DB time orders captures).
 *   widget_health_counters   INSERT (initial values) + UPDATE (counters, blocked_origins,
 *                            channel_status*, updated_at) — increments are SET c = c + 1
 *                            inside the UPDATE (monotone by construction); NO DELETE.
 *   widget_beacon_samples    INSERT-only; occurred_at app-DENIED; NO UPDATE, NO DELETE.
 *   ref_widget_bundles       platform reference data (no tenant_id, no RLS): app SELECT
 *                            only; the platform_admin role writes it (release pipeline /
 *                            the local console commands that prove FR-04's pointer).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        // ── 1. widgets: expand-contract growth (Doc 02 table) ─────────────────
        DB::statement(<<<'SQL'
            ALTER TABLE widgets
                ADD COLUMN name                          text           NOT NULL DEFAULT '',
                ADD COLUMN live_version_id               uuid,
                ADD COLUMN bundle_channel                text           NOT NULL DEFAULT 'stable',
                ADD COLUMN test_mode                     boolean        NOT NULL DEFAULT false,
                ADD COLUMN identity_secret_ref           text,
                ADD COLUMN identity_secret_previous_ref  text,
                ADD COLUMN identity_secret_rotated_at    timestamptz(6),
                ADD COLUMN deleted_at                    timestamptz(6),
                ADD CONSTRAINT widgets_bundle_channel_check CHECK (bundle_channel IN ('stable', 'early'))
        SQL);

        // C1: identity_secret_rotated_at is stamped from the DB clock whenever the
        // secret ref changes — and only then. The app cannot write the column at all.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION widgets_stamp_secret_rotation() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF NEW.identity_secret_ref IS DISTINCT FROM OLD.identity_secret_ref THEN
                    NEW.identity_secret_rotated_at := clock_timestamp();
                ELSE
                    NEW.identity_secret_rotated_at := OLD.identity_secret_rotated_at;
                END IF;
                RETURN NEW;
            END
            $fn$;
        SQL);
        DB::statement('CREATE TRIGGER widgets_secret_rotation BEFORE UPDATE ON widgets FOR EACH ROW EXECUTE FUNCTION widgets_stamp_secret_rotation()');

        // ── 2. widget_config_versions ─────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_config_versions (
                id           uuid           NOT NULL PRIMARY KEY,
                tenant_id    uuid           NOT NULL REFERENCES tenants (id),
                widget_id    uuid           NOT NULL REFERENCES widgets (id),
                version      integer        NOT NULL,
                config       jsonb          NOT NULL,
                published_by uuid           NOT NULL,
                published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                status       text           NOT NULL DEFAULT 'live',
                note         text,
                created_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_config_versions_status_check CHECK (status IN ('live', 'superseded', 'rolled_back'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_config_versions_unique ON widget_config_versions (tenant_id, widget_id, version)');
        DB::statement('ALTER TABLE widgets ADD CONSTRAINT widgets_live_version_fk FOREIGN KEY (live_version_id) REFERENCES widget_config_versions (id) DEFERRABLE INITIALLY DEFERRED');

        // ── 3. widget_visitor_contexts ────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_visitor_contexts (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                conversation_id   uuid           NOT NULL REFERENCES conversations (id),
                visitor_id        text           NOT NULL,
                url               text,
                referrer          text,
                title             text,
                browser           text,
                os                text,
                device            text,
                viewport_w        integer,
                viewport_h        integer,
                language          text,
                tz_offset_minutes integer,
                custom            jsonb          NOT NULL DEFAULT '{}'::jsonb,
                captured_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_visitor_contexts_conversation ON widget_visitor_contexts (tenant_id, conversation_id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION widget_visitor_contexts_stamp_captured() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                NEW.captured_at := clock_timestamp();
                RETURN NEW;
            END
            $fn$;
        SQL);
        DB::statement('CREATE TRIGGER widget_visitor_contexts_captured BEFORE UPDATE ON widget_visitor_contexts FOR EACH ROW EXECUTE FUNCTION widget_visitor_contexts_stamp_captured()');

        // ── 4. widget_health_counters ─────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_health_counters (
                id                      uuid           NOT NULL PRIMARY KEY,
                tenant_id               uuid           NOT NULL REFERENCES tenants (id),
                widget_id               uuid           NOT NULL REFERENCES widgets (id),
                day                     date           NOT NULL,
                loads                   bigint         NOT NULL DEFAULT 0,
                opens                   bigint         NOT NULL DEFAULT 0,
                messages                bigint         NOT NULL DEFAULT 0,
                errors                  bigint         NOT NULL DEFAULT 0,
                invalid_signatures      bigint         NOT NULL DEFAULT 0,
                blocked_origin_attempts bigint         NOT NULL DEFAULT 0,
                mount_fail_csp          bigint         NOT NULL DEFAULT 0,
                double_install          bigint         NOT NULL DEFAULT 0,
                blocked_origins         jsonb          NOT NULL DEFAULT '[]'::jsonb,
                channel_status          text,
                channel_status_at       timestamptz(6),
                created_at              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_health_counters_status_check CHECK (channel_status IS NULL OR channel_status IN ('healthy', 'degraded', 'down'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_health_counters_day ON widget_health_counters (tenant_id, widget_id, day)');

        // ── 5. widget_beacon_samples ──────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_beacon_samples (
                id          uuid           NOT NULL PRIMARY KEY,
                tenant_id   uuid           NOT NULL REFERENCES tenants (id),
                widget_id   uuid           NOT NULL REFERENCES widgets (id),
                kind        text           NOT NULL,
                reason      text,
                sample      text,
                bundle_hash text,
                occurred_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_beacon_samples_kind_check CHECK (kind IN ('load', 'open', 'blocked_origin', 'mount_fail', 'panel_error', 'double_install'))
            )
        SQL);
        DB::statement('CREATE INDEX widget_beacon_samples_recent ON widget_beacon_samples (tenant_id, widget_id, occurred_at DESC)');

        // ── 6. ref_widget_bundles (platform reference data) ───────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE ref_widget_bundles (
                id           uuid           NOT NULL PRIMARY KEY,
                channel      text           NOT NULL,
                bundle_hash  text           NOT NULL,
                loader_hash  text           NOT NULL,
                rollout_pct  smallint       NOT NULL DEFAULT 5,
                killed_at    timestamptz(6),
                published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT ref_widget_bundles_channel_check CHECK (channel IN ('stable', 'early')),
                CONSTRAINT ref_widget_bundles_rollout_check CHECK (rollout_pct BETWEEN 0 AND 100)
            )
        SQL);
        DB::statement('CREATE INDEX ref_widget_bundles_live ON ref_widget_bundles (channel, published_at DESC)');

        // ── RLS on every tenant table (item 6 is ref_* — none) ────────────────
        foreach (['widget_config_versions', 'widget_visitor_contexts', 'widget_health_counters', 'widget_beacon_samples'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid)");
        }

        // ── GRANTS (the APP_WRITABLE_COLUMNS splits filed in the amendment) ──
        // The default privileges hand every new table full writes; each table is
        // revoked first so the column grants below are the WHOLE truth.
        foreach (['widget_config_versions', 'widget_visitor_contexts', 'widget_health_counters', 'widget_beacon_samples', 'ref_widget_bundles'] as $table) {
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
        }
        // widgets: table-level → column-level (C1). Doc 02's columns stay writable.
        DB::statement('REVOKE INSERT, UPDATE ON widgets FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, key_id, allowed_origins, created_at, updated_at, name, live_version_id, bundle_channel, test_mode, identity_secret_ref, identity_secret_previous_ref, deleted_at) ON widgets TO app');
        DB::statement('GRANT UPDATE (key_id, allowed_origins, updated_at, name, live_version_id, bundle_channel, test_mode, identity_secret_ref, identity_secret_previous_ref, deleted_at) ON widgets TO app');

        DB::statement('GRANT SELECT ON widget_config_versions TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, version, config, published_by, status, note) ON widget_config_versions TO app');
        // widget_config_versions: NO UPDATE, NO DELETE — immutable by grant (FR-20 as physics).

        DB::statement('GRANT SELECT ON widget_visitor_contexts TO app');
        DB::statement('GRANT INSERT (id, tenant_id, conversation_id, visitor_id, url, referrer, title, browser, os, device, viewport_w, viewport_h, language, tz_offset_minutes, custom) ON widget_visitor_contexts TO app');
        DB::statement('GRANT UPDATE (url, referrer, title, browser, os, device, viewport_w, viewport_h, language, tz_offset_minutes, custom, updated_at) ON widget_visitor_contexts TO app');

        DB::statement('GRANT SELECT ON widget_health_counters TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, day, loads, opens, messages, errors, invalid_signatures, blocked_origin_attempts, mount_fail_csp, double_install, blocked_origins, channel_status, channel_status_at) ON widget_health_counters TO app');
        DB::statement('GRANT UPDATE (loads, opens, messages, errors, invalid_signatures, blocked_origin_attempts, mount_fail_csp, double_install, blocked_origins, channel_status, channel_status_at, updated_at) ON widget_health_counters TO app');

        DB::statement('GRANT SELECT ON widget_beacon_samples TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, kind, reason, sample, bundle_hash) ON widget_beacon_samples TO app');

        DB::statement('GRANT SELECT ON ref_widget_bundles TO app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON ref_widget_bundles TO platform_admin'); // C4: written on the platform connection, actor logged
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS widgets DROP CONSTRAINT IF EXISTS widgets_live_version_fk');
        DB::statement('DROP TABLE IF EXISTS ref_widget_bundles');
        DB::statement('DROP TABLE IF EXISTS widget_beacon_samples');
        DB::statement('DROP TABLE IF EXISTS widget_health_counters');
        DB::statement('DROP TRIGGER IF EXISTS widgets_secret_rotation ON widgets');
        DB::statement('DROP FUNCTION IF EXISTS widgets_stamp_secret_rotation');
        DB::statement('DROP TRIGGER IF EXISTS widget_visitor_contexts_captured ON widget_visitor_contexts');
        DB::statement('DROP FUNCTION IF EXISTS widget_visitor_contexts_stamp_captured');
        DB::statement('DROP TABLE IF EXISTS widget_visitor_contexts');
        DB::statement('DROP TABLE IF EXISTS widget_config_versions');
        DB::statement(<<<'SQL'
            ALTER TABLE widgets
                DROP COLUMN IF EXISTS name, DROP COLUMN IF EXISTS live_version_id, DROP COLUMN IF EXISTS bundle_channel,
                DROP COLUMN IF EXISTS test_mode, DROP COLUMN IF EXISTS identity_secret_ref, DROP COLUMN IF EXISTS identity_secret_previous_ref,
                DROP COLUMN IF EXISTS identity_secret_rotated_at, DROP COLUMN IF EXISTS deleted_at
        SQL);
    }
};
