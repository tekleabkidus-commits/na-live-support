<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 8 (Doc 14, filed at S14 step 2a, COUNTERSIGNED as filed on a8bf165 with
 * three conditions folded here): the SMS module's seven tables (T-01). Expand only; nothing
 * on any existing table; `vault_secrets` gains rows under the `sms` namespace only.
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   sms_templates           INSERT + UPDATE business columns; event/language immutable on
 *                           UPDATE (a template IS its event); soft delete; NO DELETE.
 *                           Condition 1: `optout_confirmation` is a TEMPLATE event only —
 *                           the CHECK admits it here, SendableEvent has no case, no
 *                           `sms.events.*` setting names it, and the announcement-only
 *                           trigger on sms_broadcasts refuses everything but
 *                           service_announcement.
 *   sms_template_versions   INSERT-only — NO UPDATE, NO DELETE (immutable by grant; a
 *                           rollback is a NEW row + the pointer flip); published_at
 *                           app-DENIED (the history's ordering key, DB time).
 *   sms_gateways            INSERT + UPDATE business columns; driver and is_fixture
 *                           immutable; circuit_opened_at is the trigger's (NFR-05 is
 *                           measured from it — never the app's clock); Condition 3: the
 *                           `credential_ref ~ '^vault://sms/'` CHECK (and its two DLR
 *                           siblings) plus the no-secret-key-in-config CHECK; NO DELETE.
 *   sms_routes              INSERT + UPDATE business columns; NO DELETE (a removed route is
 *                           active = false; history stays).
 *   sms_messages            INSERT business columns; UPDATE the lifecycle columns; the
 *                           identity (phone, event, lane, is_test, customer/ticket/broadcast
 *                           ids, language) and the four clock facts UPDATE-DENIED;
 *                           queued_at app-DENIED; sent_at/delivered_at/terminal_at stamped
 *                           by a trigger on the state change (C1 shape; FR-12's 48 h and
 *                           NFR-03 are DB-time facts). Condition 2: phone and
 *                           rendered_body are [D17]-classed PII read only through the
 *                           masked path except the two permissioned UIs (SEC-02) — the
 *                           readers enforce it; the columns carry the class in this note.
 *   sms_optouts             APPEND-ONLY SOURCING (R-05): INSERT business columns; UPDATE
 *                           the restoration triple + updated_at ONLY; phone/source/reason/
 *                           added_by/stop_word_matched immutable; NO DELETE.
 *   sms_broadcasts          INSERT + UPDATE business columns; template_id immutable; the
 *                           three stamps + monthly_bucket are the trigger's (computed IN SQL
 *                           in the tenant's timezone at launch — the C2 shape); the
 *                           announcement-only trigger is BR-02 as physics; NO DELETE.
 *
 * FILING CORRECTION, stated (evidence over filing): the amendment wrote `monthly_bucket
 * text NOT NULL` AND "computed IN SQL at launch" — a draft row exists before its launch, so
 * the column is NULL until the launch stamp and a CHECK binds it: NOT NULL from `sending`
 * onward. The countersign's intent (the cap counts launches in the tenant month) holds.
 */
return new class extends Migration
{
    private const TEMPLATE_EVENTS = "('ticket_created','ticket_assigned','ticket_updated','ticket_resolved','ticket_closed','security_notification','verification_code','service_announcement','window_reengagement','offline_form_confirmation','optout_confirmation')";

    private const STATES = "('queued','held','sending','sent','delivered','undelivered','expired','skipped','failed')";

    private const SKIP_REASONS = "('feature_disabled','hold_all_sends','no_gateway','no_phone','opted_out','blocked','rg_flagged','marketing_suppressed','duplicate','quiet_hours','unknown_template_ref','render_missing_variable','no_route')";

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        // ── 1. sms_templates ──────────────────────────────────────────────────
        DB::statement(<<<SQL
            CREATE TABLE sms_templates (
                id                  uuid           NOT NULL PRIMARY KEY,
                tenant_id           uuid           NOT NULL REFERENCES tenants (id),
                event               text           NOT NULL,
                language            text           NOT NULL,
                name                text           NOT NULL,
                active              boolean        NOT NULL DEFAULT true,
                quiet_hours_policy  text           NOT NULL DEFAULT 'hold',
                on_missing          text           NOT NULL DEFAULT 'skip_send',
                fallback_text       text,
                live_version_id     uuid,
                deleted_at          timestamptz(6),
                created_at          timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at          timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_templates_event_check CHECK (event IN {$this->templateEvents()}),
                CONSTRAINT sms_templates_quiet_hours_policy_check CHECK (quiet_hours_policy IN ('exempt', 'hold', 'skip')),
                CONSTRAINT sms_templates_on_missing_check CHECK (on_missing IN ('skip_send', 'fallback_text'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sms_templates_event_language_unique ON sms_templates (tenant_id, event, language) WHERE deleted_at IS NULL');

        // ── 2. sms_template_versions ──────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE sms_template_versions (
                id                 uuid           NOT NULL PRIMARY KEY,
                tenant_id          uuid           NOT NULL REFERENCES tenants (id),
                template_id        uuid           NOT NULL REFERENCES sms_templates (id),
                version            integer        NOT NULL,
                body               text           NOT NULL,
                variables_used     jsonb          NOT NULL DEFAULT '[]'::jsonb,
                encoding           text           NOT NULL,
                segments_estimate  integer        NOT NULL,
                published_by       uuid           NOT NULL,
                published_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                status_at_birth    text           NOT NULL DEFAULT 'live',
                note               text,
                created_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_template_versions_encoding_check CHECK (encoding IN ('gsm7', 'ucs2')),
                CONSTRAINT sms_template_versions_status_check CHECK (status_at_birth IN ('live', 'rolled_back'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sms_template_versions_unique ON sms_template_versions (tenant_id, template_id, version)');
        DB::statement('ALTER TABLE sms_templates ADD CONSTRAINT sms_templates_live_version_fk FOREIGN KEY (live_version_id) REFERENCES sms_template_versions (id) DEFERRABLE INITIALLY DEFERRED');

        // ── 3. sms_gateways ───────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE sms_gateways (
                id                     uuid           NOT NULL PRIMARY KEY,
                tenant_id              uuid           NOT NULL REFERENCES tenants (id),
                name                   text           NOT NULL,
                driver                 text           NOT NULL,
                config                 jsonb          NOT NULL DEFAULT '{}'::jsonb,
                credential_ref         text,
                dlr_key_ref            text,
                dlr_secret_ref         text,
                webhook_credential_id  uuid           REFERENCES webhook_credentials (id),
                sender_id              text,
                health                 text           NOT NULL DEFAULT 'healthy',
                circuit_state          text           NOT NULL DEFAULT 'closed',
                circuit_opened_at      timestamptz(6),
                active                 boolean        NOT NULL DEFAULT true,
                is_fixture             boolean        NOT NULL DEFAULT false,
                deleted_at             timestamptz(6),
                created_at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_gateways_driver_check CHECK (driver IN ('http_generic', 'smpp_sidecar')),
                CONSTRAINT sms_gateways_health_check CHECK (health IN ('healthy', 'degraded', 'down')),
                CONSTRAINT sms_gateways_circuit_check CHECK (circuit_state IN ('closed', 'open', 'half_open')),
                CONSTRAINT sms_gateways_credential_ref_check CHECK (credential_ref IS NULL OR credential_ref ~ '^vault://sms/'),
                CONSTRAINT sms_gateways_dlr_key_ref_check CHECK (dlr_key_ref IS NULL OR dlr_key_ref ~ '^vault://sms/'),
                CONSTRAINT sms_gateways_dlr_secret_ref_check CHECK (dlr_secret_ref IS NULL OR dlr_secret_ref ~ '^vault://sms/'),
                CONSTRAINT sms_gateways_config_no_secret_check CHECK (NOT (jsonb_exists(config, 'token') OR jsonb_exists(config, 'secret') OR jsonb_exists(config, 'password') OR jsonb_exists(config, 'api_key')))
            )
        SQL);
        // NFR-05: "failover engages ≤ 30 s from circuit-trip" is measured from THIS stamp — the DB clock, never the app's.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sms_gateways_stamp_circuit() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF NEW.circuit_state = 'open' AND OLD.circuit_state IS DISTINCT FROM 'open' THEN
                    NEW.circuit_opened_at := clock_timestamp();
                ELSIF NEW.circuit_state = 'closed' THEN
                    NEW.circuit_opened_at := NULL;
                ELSE
                    NEW.circuit_opened_at := OLD.circuit_opened_at;
                END IF;
                RETURN NEW;
            END
            $fn$
        SQL);
        DB::statement('CREATE TRIGGER sms_gateways_circuit BEFORE UPDATE ON sms_gateways FOR EACH ROW EXECUTE FUNCTION sms_gateways_stamp_circuit()');

        // ── 4. sms_routes ─────────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE sms_routes (
                id                    uuid           NOT NULL PRIMARY KEY,
                tenant_id             uuid           NOT NULL REFERENCES tenants (id),
                country_prefix        text           NOT NULL,
                position              integer        NOT NULL,
                primary_gateway_id    uuid           NOT NULL REFERENCES sms_gateways (id),
                fallback_gateway_ids  jsonb          NOT NULL DEFAULT '[]'::jsonb,
                sender_id_override    text,
                active                boolean        NOT NULL DEFAULT true,
                created_at            timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at            timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_routes_prefix_check CHECK (country_prefix ~ '^\+[1-9][0-9]{0,14}$')
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sms_routes_prefix_unique ON sms_routes (tenant_id, country_prefix)');

        // ── 5. sms_messages ───────────────────────────────────────────────────
        DB::statement(<<<SQL
            CREATE TABLE sms_messages (
                id                     uuid           NOT NULL PRIMARY KEY,
                tenant_id              uuid           NOT NULL REFERENCES tenants (id),
                customer_id            uuid           REFERENCES customers (id),
                ticket_id              uuid,
                conversation_id        uuid,
                broadcast_id           uuid,
                event                  text           NOT NULL,
                template_id            uuid           REFERENCES sms_templates (id),
                template_version_id    uuid           REFERENCES sms_template_versions (id),
                language               text           NOT NULL,
                phone                  text           NOT NULL,
                rendered_body          text,
                encoding               text,
                segments               integer,
                state                  text           NOT NULL DEFAULT 'queued',
                skip_reason            text,
                fail_reason            text,
                guard_log              jsonb          NOT NULL DEFAULT '[]'::jsonb,
                attempts               jsonb          NOT NULL DEFAULT '[]'::jsonb,
                gateway_id             uuid           REFERENCES sms_gateways (id),
                provider_id            text,
                raw_dlrs               jsonb          NOT NULL DEFAULT '[]'::jsonb,
                lane                   text           NOT NULL,
                is_test                boolean        NOT NULL DEFAULT false,
                cost_amount            numeric(12,4),
                cost_currency          char(3),
                cost_corrected_amount  numeric(12,4),
                cost_correction_note   text,
                queued_at              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                sent_at                timestamptz(6),
                delivered_at           timestamptz(6),
                terminal_at            timestamptz(6),
                created_at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_messages_event_check CHECK (event IN {$this->templateEvents()}),
                CONSTRAINT sms_messages_state_check CHECK (state IN {$this->states()}),
                CONSTRAINT sms_messages_skip_reason_check CHECK (skip_reason IS NULL OR skip_reason IN {$this->skipReasons()}),
                CONSTRAINT sms_messages_encoding_check CHECK (encoding IS NULL OR encoding IN ('gsm7', 'ucs2')),
                CONSTRAINT sms_messages_lane_check CHECK (lane IN ('sms-transactional', 'sms-reengagement', 'sms-broadcast'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sms_messages_provider_unique ON sms_messages (tenant_id, gateway_id, provider_id) WHERE provider_id IS NOT NULL');
        DB::statement('CREATE INDEX sms_messages_duplicate_window ON sms_messages (tenant_id, event, ticket_id, phone, queued_at DESC)');
        DB::statement('CREATE INDEX sms_messages_state_queued ON sms_messages (tenant_id, state, queued_at)');
        DB::statement("CREATE INDEX sms_messages_sent_pending ON sms_messages (tenant_id, state, sent_at) WHERE state = 'sent'");
        DB::statement('CREATE INDEX sms_messages_broadcast ON sms_messages (tenant_id, broadcast_id)');
        DB::statement('CREATE INDEX sms_messages_customer_history ON sms_messages (tenant_id, customer_id, queued_at DESC)');
        // The clock facts are the DATABASE's: stamped when the state changes, never written by the app (C1 shape, ADR 0010).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sms_messages_stamp_states() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                old_state text := CASE WHEN TG_OP = 'UPDATE' THEN OLD.state ELSE NULL END;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    NEW.sent_at := OLD.sent_at;
                    NEW.delivered_at := OLD.delivered_at;
                    NEW.terminal_at := OLD.terminal_at;
                    NEW.queued_at := OLD.queued_at;
                ELSE
                    NEW.sent_at := NULL;
                    NEW.delivered_at := NULL;
                    NEW.terminal_at := NULL;
                END IF;
                IF NEW.state = 'sent' AND old_state IS DISTINCT FROM 'sent' AND NEW.sent_at IS NULL THEN
                    NEW.sent_at := clock_timestamp();
                END IF;
                IF NEW.state = 'delivered' AND old_state IS DISTINCT FROM 'delivered' THEN
                    NEW.delivered_at := clock_timestamp();
                    IF NEW.sent_at IS NULL THEN NEW.sent_at := clock_timestamp(); END IF;
                END IF;
                IF NEW.state IN ('delivered', 'undelivered', 'expired', 'skipped', 'failed')
                   AND (old_state IS NULL OR old_state NOT IN ('delivered', 'undelivered', 'expired', 'skipped', 'failed')) THEN
                    NEW.terminal_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $fn$
        SQL);
        DB::statement('CREATE TRIGGER sms_messages_states BEFORE INSERT OR UPDATE ON sms_messages FOR EACH ROW EXECUTE FUNCTION sms_messages_stamp_states()');

        // ── 6. sms_optouts ────────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE sms_optouts (
                id                 uuid           NOT NULL PRIMARY KEY,
                tenant_id          uuid           NOT NULL REFERENCES tenants (id),
                phone              text           NOT NULL,
                source             text           NOT NULL,
                reason             text,
                added_by           uuid,
                stop_word_matched  text,
                restored_at        timestamptz(6),
                restored_by        uuid,
                restore_reason     text,
                created_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_optouts_source_check CHECK (source IN ('inbound_stop', 'staff', 'api')),
                CONSTRAINT sms_optouts_staff_reason_check CHECK (source <> 'staff' OR (reason IS NOT NULL AND reason <> '')),
                CONSTRAINT sms_optouts_restore_reason_check CHECK ((restored_at IS NULL) = (restore_reason IS NULL)),
                CONSTRAINT sms_optouts_restore_by_check CHECK ((restored_at IS NULL) = (restored_by IS NULL))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sms_optouts_live_unique ON sms_optouts (tenant_id, phone) WHERE restored_at IS NULL');

        // ── 7. sms_broadcasts ─────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE sms_broadcasts (
                id                         uuid           NOT NULL PRIMARY KEY,
                tenant_id                  uuid           NOT NULL REFERENCES tenants (id),
                name                       text           NOT NULL,
                template_id                uuid           NOT NULL REFERENCES sms_templates (id),
                template_version_id        uuid           NOT NULL REFERENCES sms_template_versions (id),
                audience_filter            jsonb          NOT NULL DEFAULT '{}'::jsonb,
                audience_count_preview     integer        NOT NULL DEFAULT 0,
                scheduled_at               timestamptz(6),
                state                      text           NOT NULL DEFAULT 'draft',
                throughput_cap_per_minute  integer,
                total                      bigint         NOT NULL DEFAULT 0,
                sent                       bigint         NOT NULL DEFAULT 0,
                delivered                  bigint         NOT NULL DEFAULT 0,
                skipped                    bigint         NOT NULL DEFAULT 0,
                failed                     bigint         NOT NULL DEFAULT 0,
                cost_accrued               numeric(12,4)  NOT NULL DEFAULT 0,
                cost_currency              char(3),
                launched_by                uuid,
                launched_at                timestamptz(6),
                canceled_at                timestamptz(6),
                completed_at               timestamptz(6),
                monthly_bucket             text,
                cancel_requested_at        timestamptz(6),
                created_at                 timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at                 timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sms_broadcasts_state_check CHECK (state IN ('draft', 'scheduled', 'sending', 'paused_quota', 'done', 'canceled')),
                CONSTRAINT sms_broadcasts_month_check CHECK (state IN ('draft', 'scheduled') OR monthly_bucket IS NOT NULL)
            )
        SQL);
        DB::statement('CREATE INDEX sms_broadcasts_month ON sms_broadcasts (tenant_id, monthly_bucket)');
        // BR-02 as physics: only the service_announcement family is broadcast-capable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sms_broadcasts_announcement_only() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                template_event text;
            BEGIN
                SELECT event INTO template_event FROM sms_templates WHERE id = NEW.template_id;
                IF template_event IS DISTINCT FROM 'service_announcement' THEN
                    RAISE EXCEPTION 'sms_broadcasts: only a service_announcement template is broadcast-capable (Doc 14 BR-02), got %', COALESCE(template_event, 'no template')
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $fn$
        SQL);
        DB::statement('CREATE TRIGGER sms_broadcasts_announcement BEFORE INSERT OR UPDATE OF template_id, template_version_id ON sms_broadcasts FOR EACH ROW EXECUTE FUNCTION sms_broadcasts_announcement_only()');
        // The launch/cancel/completion instants and the tenant-month bucket are the DATABASE's (C1 + C2 shapes).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sms_broadcasts_stamp_states() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                tz text;
            BEGIN
                NEW.launched_at := OLD.launched_at;
                NEW.canceled_at := OLD.canceled_at;
                NEW.completed_at := OLD.completed_at;
                NEW.monthly_bucket := OLD.monthly_bucket;
                IF NEW.state = 'sending' AND OLD.state IN ('draft', 'scheduled') THEN
                    SELECT timezone_name INTO tz FROM tenants WHERE id = NEW.tenant_id;
                    NEW.launched_at := clock_timestamp();
                    NEW.monthly_bucket := to_char(clock_timestamp() AT TIME ZONE COALESCE(tz, 'UTC'), 'YYYY-MM');
                END IF;
                IF NEW.state = 'canceled' AND OLD.state <> 'canceled' THEN
                    NEW.canceled_at := clock_timestamp();
                END IF;
                IF NEW.state = 'done' AND OLD.state <> 'done' THEN
                    NEW.completed_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $fn$
        SQL);
        DB::statement('CREATE TRIGGER sms_broadcasts_states BEFORE UPDATE ON sms_broadcasts FOR EACH ROW EXECUTE FUNCTION sms_broadcasts_stamp_states()');

        // ── RLS on every table ────────────────────────────────────────────────
        foreach ($this->tables() as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid)");
        }

        // ── GRANTS (the APP_WRITABLE_COLUMNS splits filed in the amendment) ──
        foreach ($this->tables() as $table) {
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
            DB::statement("GRANT SELECT ON {$table} TO app");
        }
        DB::statement('GRANT INSERT (id, tenant_id, event, language, name, active, quiet_hours_policy, on_missing, fallback_text, live_version_id, updated_at) ON sms_templates TO app');
        DB::statement('GRANT UPDATE (name, active, quiet_hours_policy, on_missing, fallback_text, live_version_id, deleted_at, updated_at) ON sms_templates TO app');

        DB::statement('GRANT INSERT (id, tenant_id, template_id, version, body, variables_used, encoding, segments_estimate, published_by, status_at_birth, note) ON sms_template_versions TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, driver, config, credential_ref, dlr_key_ref, dlr_secret_ref, webhook_credential_id, sender_id, health, circuit_state, active, is_fixture, updated_at) ON sms_gateways TO app');
        DB::statement('GRANT UPDATE (name, config, credential_ref, dlr_key_ref, dlr_secret_ref, webhook_credential_id, sender_id, health, circuit_state, active, deleted_at, updated_at) ON sms_gateways TO app');

        DB::statement('GRANT INSERT (id, tenant_id, country_prefix, position, primary_gateway_id, fallback_gateway_ids, sender_id_override, active, updated_at) ON sms_routes TO app');
        DB::statement('GRANT UPDATE (country_prefix, position, primary_gateway_id, fallback_gateway_ids, sender_id_override, active, updated_at) ON sms_routes TO app');

        DB::statement('GRANT INSERT (id, tenant_id, customer_id, ticket_id, conversation_id, broadcast_id, event, template_id, template_version_id, language, phone, rendered_body, encoding, segments, state, skip_reason, guard_log, lane, is_test, updated_at) ON sms_messages TO app');
        DB::statement('GRANT UPDATE (state, skip_reason, fail_reason, guard_log, attempts, gateway_id, provider_id, raw_dlrs, rendered_body, encoding, segments, template_id, template_version_id, cost_amount, cost_currency, cost_corrected_amount, cost_correction_note, updated_at) ON sms_messages TO app');

        DB::statement('GRANT INSERT (id, tenant_id, phone, source, reason, added_by, stop_word_matched, updated_at) ON sms_optouts TO app');
        DB::statement('GRANT UPDATE (restored_at, restored_by, restore_reason, updated_at) ON sms_optouts TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, template_id, template_version_id, audience_filter, audience_count_preview, scheduled_at, state, throughput_cap_per_minute, total, sent, delivered, skipped, failed, cost_accrued, cost_currency, launched_by, cancel_requested_at, updated_at) ON sms_broadcasts TO app');
        DB::statement('GRANT UPDATE (name, template_version_id, audience_filter, audience_count_preview, scheduled_at, state, throughput_cap_per_minute, total, sent, delivered, skipped, failed, cost_accrued, cost_currency, launched_by, cancel_requested_at, updated_at) ON sms_broadcasts TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sms_broadcasts');
        DB::statement('DROP FUNCTION IF EXISTS sms_broadcasts_stamp_states');
        DB::statement('DROP FUNCTION IF EXISTS sms_broadcasts_announcement_only');
        DB::statement('DROP TABLE IF EXISTS sms_optouts');
        DB::statement('DROP TABLE IF EXISTS sms_messages');
        DB::statement('DROP FUNCTION IF EXISTS sms_messages_stamp_states');
        DB::statement('DROP TABLE IF EXISTS sms_routes');
        DB::statement('DROP TABLE IF EXISTS sms_gateways');
        DB::statement('DROP FUNCTION IF EXISTS sms_gateways_stamp_circuit');
        DB::statement('ALTER TABLE IF EXISTS sms_templates DROP CONSTRAINT IF EXISTS sms_templates_live_version_fk');
        DB::statement('DROP TABLE IF EXISTS sms_template_versions');
        DB::statement('DROP TABLE IF EXISTS sms_templates');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['sms_templates', 'sms_template_versions', 'sms_gateways', 'sms_routes', 'sms_messages', 'sms_optouts', 'sms_broadcasts'];
    }

    private function templateEvents(): string
    {
        return self::TEMPLATE_EVENTS;
    }

    private function states(): string
    {
        return self::STATES;
    }

    private function skipReasons(): string
    {
        return self::SKIP_REASONS;
    }
};
