<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle state machine, as database physics (Doc 01 FR-05).
 *
 * The prompt for this module asks for "one transition service with a
 * database-level invariant — no other code path may change the state". A
 * service alone is developer discipline: any `$tenant->update(['state' => …])`
 * anywhere in 19 modules' worth of future code would bypass it silently. So the
 * database enforces TWO things:
 *
 *   1. the transition is legal in the FR-05 graph, and
 *   2. it was performed by the transition service.
 *
 * (2) is the interesting half. TenantLifecycle sets a transaction-local flag
 * (`app.lifecycle_transition`) immediately before its UPDATE; the trigger
 * refuses any state change made without it. A stray mass-assignment cannot set
 * that flag by accident, because setting it is a deliberate `set_config` call
 * that exists in exactly one file. tests/Feature/Foundation/TenantLifecycleTest
 * proves a direct UPDATE fails.
 *
 * The legal graph (FR-05, EC-04):
 *
 *   provisioning ─→ active          normal completion
 *   provisioning ─→ suspended       EC-04: suspended mid-provisioning
 *   active       ─→ suspended       non-payment
 *   suspended    ─→ active          reactivation (nothing was lost — BR-05)
 *   active       ─→ archived        end of contract
 *   suspended    ─→ archived        end of contract from suspension
 *   archived     ─→ (nothing)       terminal; keys revoked, export only
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant_state_transition_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.state = NEW.state THEN
                    RETURN NEW;
                END IF;

                -- (2) Only the transition service may change state.
                IF coalesce(current_setting('app.lifecycle_transition', true), '') <> 'on' THEN
                    RAISE EXCEPTION
                        'tenant.state may only be changed by the lifecycle transition service (Doc 01 FR-05): % -> % was attempted without app.lifecycle_transition',
                        OLD.state, NEW.state
                        USING ERRCODE = 'check_violation';
                END IF;

                -- (1) The transition must exist in the FR-05 graph.
                IF NOT (
                    (OLD.state = 'provisioning' AND NEW.state IN ('active', 'suspended'))
                    OR (OLD.state = 'active'    AND NEW.state IN ('suspended', 'archived'))
                    OR (OLD.state = 'suspended' AND NEW.state IN ('active', 'archived'))
                ) THEN
                    RAISE EXCEPTION
                        'illegal tenant lifecycle transition % -> % (Doc 01 FR-05)',
                        OLD.state, NEW.state
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Provenance is stamped by the database, never by the caller:
                -- EC-08 forbids app-server wall clocks in ordering decisions,
                -- and this timestamp orders the tenant's lifecycle history.
                NEW.state_changed_at := now();

                IF NEW.state = 'active' AND NEW.provisioned_at IS NULL THEN
                    NEW.provisioned_at := now();
                END IF;

                IF NEW.state = 'suspended' THEN
                    NEW.suspended_at := now();
                END IF;

                IF NEW.state = 'archived' THEN
                    NEW.archived_at := now();
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER tenants_state_transition_guard
                BEFORE UPDATE ON tenants
                FOR EACH ROW
                EXECUTE FUNCTION tenant_state_transition_guard();
        SQL);

        // A tenant is born `provisioning` and never born any other way
        // (FS-04: a tenant is never partially "active").
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant_initial_state_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.state <> 'provisioning' THEN
                    RAISE EXCEPTION
                        'a tenant must be created in the provisioning state, got % (Doc 01 FR-05 / FS-04)',
                        NEW.state
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER tenants_initial_state_guard
                BEFORE INSERT ON tenants
                FOR EACH ROW
                EXECUTE FUNCTION tenant_initial_state_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('
            DROP TRIGGER IF EXISTS tenants_state_transition_guard ON tenants;
            DROP TRIGGER IF EXISTS tenants_initial_state_guard ON tenants;
            DROP FUNCTION IF EXISTS tenant_state_transition_guard();
            DROP FUNCTION IF EXISTS tenant_initial_state_guard();
        ');
    }
};
