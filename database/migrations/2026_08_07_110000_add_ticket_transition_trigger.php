<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-24's metric timestamps as DATABASE physics — owner ruling R-20, whose design note
 * is filed in ANSWERS beside it and advisor-confirmed with two strengthenings (both
 * implemented here and named at their line below).
 *
 * WHY A TRIGGER AND NOT APPLICATION CODE. R-19a made every metric column
 * app-unwritable, because a column the app can name is a column an app clock can fill
 * (C-11's whole defect). But a metric column is MUTABLE — it re-stamps on each
 * transition — and a column DEFAULT can only fill a value once, at insert. A trigger
 * is the only mechanism left that satisfies both: it runs inside the database with the
 * database's own clock, and the app cannot bypass it because the app cannot write these
 * columns at all. FR-24's "computed transactionally with the transition, never derived
 * later" is then structural rather than a convention the next session can forget.
 *
 * WHY IT IS SAFE.
 * - NOT `SECURITY DEFINER`: it runs as the invoking role, so its read of `messages` is
 *   policed by that table's FORCE RLS exactly like any other app read. A signal message
 *   from another tenant is simply invisible and the trigger refuses. Ruling R-1's
 *   ceiling of four SECURITY DEFINER functions is untouched.
 * - It writes only `NEW.*` fields in a BEFORE trigger, which is not a privilege-checked
 *   column write — so the R-19a/R-19b grants stay exactly as step 1 left them.
 * - It never writes `status`. NFR-03's "one server-side writer" is about the status
 *   machine; this trigger only DERIVES stamps from the status change the transition
 *   service made, so the sole-writer rule and its grep gate are unaffected.
 *
 * THE TWO SIGNAL COLUMNS (A-7 amendment, approved with the note). FR-24 defines
 * first_response_at as the first PUBLIC staff-or-AI message after ticket creation — an
 * event that routinely happens with NO status transition at all (an AI reply on an
 * already-open ticket). The service therefore writes the message id into an
 * app-writable signal column in the same transaction as the reply, and the trigger
 * turns that signal into the stamp, reading the message's own DB-time `created_at`
 * (already pure database time under R-19). The signals are FILL-ONLY: once set they
 * cannot be moved, so a C-09 redelivery or a later reply can never rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->uuid('first_response_message_id')->nullable();
            $table->uuid('agent_first_response_message_id')->nullable();
        });

        // NO foreign key: `messages` is partitioned with composite PK (id, created_at)
        // and retention DROPs partitions — an FK from here would block the drop. Same
        // reasoning as ticket_message_anchors; the trigger's own lookup is the check.

        // The app MAY write the signals (they are provenance ids, not timestamps).
        // A column-level grant holder gains nothing automatically on a NEW column, so
        // these two grants are required or the service could not signal at all — and
        // TicketGrantPhysicsTest's derived denied-set assertion is what would have
        // caught the omission.
        DB::statement('GRANT INSERT (first_response_message_id, agent_first_response_message_id) ON tickets TO app');
        DB::statement('GRANT UPDATE (first_response_message_id, agent_first_response_message_id) ON tickets TO app');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tickets_stamp_transition() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                sig     record;
                elapsed bigint;
            BEGIN
                -- (1) THE SIGNALS ARE FILL-ONLY.
                -- An occupied signal column can never be moved: first response is a
                -- historical fact, and C-09's at-least-once redelivery means the same
                -- reply may arrive twice. Silently restoring OLD (rather than raising)
                -- keeps redelivery idempotent, which is the documented posture.
                IF OLD.first_response_message_id IS NOT NULL THEN
                    NEW.first_response_message_id := OLD.first_response_message_id;
                END IF;
                IF OLD.agent_first_response_message_id IS NOT NULL THEN
                    NEW.agent_first_response_message_id := OLD.agent_first_response_message_id;
                END IF;

                -- (2) FIRST RESPONSE — stamped from the MESSAGE's own database time.
                IF NEW.first_response_message_id IS NOT NULL AND OLD.first_response_message_id IS NULL THEN
                    SELECT m.created_at, m.author_type, m.is_internal, m.conversation_id
                      INTO sig
                      FROM messages m
                     WHERE m.id = NEW.first_response_message_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'ticket_metric_signal: first-response message % is not visible (wrong tenant, or it does not exist)', NEW.first_response_message_id;
                    END IF;
                    -- STRENGTHENING 1 (advisor, R-20 note): FR-24 excludes notes, and
                    -- the trigger REFUSES them. The service not signalling on a note is
                    -- convention; this is the guarantee.
                    IF sig.is_internal THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % is an internal note — FR-24 excludes notes from first_response_at', NEW.first_response_message_id;
                    END IF;
                    IF sig.conversation_id <> NEW.conversation_id THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % belongs to another conversation', NEW.first_response_message_id;
                    END IF;
                    -- FR-24: pre-ticket chatbot messages are excluded.
                    IF sig.created_at < NEW.created_at THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % predates the ticket — FR-24 excludes pre-ticket messages', NEW.first_response_message_id;
                    END IF;
                    IF sig.author_type NOT IN ('agent', 'ai') THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % is not a staff or AI reply', NEW.first_response_message_id;
                    END IF;

                    NEW.first_response_at := sig.created_at;
                END IF;

                -- agent_first_response_at: the first public HUMAN reply (FR-24).
                IF NEW.agent_first_response_message_id IS NOT NULL AND OLD.agent_first_response_message_id IS NULL THEN
                    SELECT m.created_at, m.author_type, m.is_internal, m.conversation_id
                      INTO sig
                      FROM messages m
                     WHERE m.id = NEW.agent_first_response_message_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'ticket_metric_signal: agent-first-response message % is not visible', NEW.agent_first_response_message_id;
                    END IF;
                    IF sig.is_internal THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % is an internal note — FR-24 excludes notes', NEW.agent_first_response_message_id;
                    END IF;
                    IF sig.conversation_id <> NEW.conversation_id THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % belongs to another conversation', NEW.agent_first_response_message_id;
                    END IF;
                    IF sig.created_at < NEW.created_at THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % predates the ticket', NEW.agent_first_response_message_id;
                    END IF;
                    IF sig.author_type <> 'agent' THEN
                        RAISE EXCEPTION 'ticket_metric_signal: message % is not a HUMAN reply — agent_first_response_at counts agents only', NEW.agent_first_response_message_id;
                    END IF;

                    NEW.agent_first_response_at := sig.created_at;
                END IF;

                -- (3) STATUS-DERIVED STAMPS AND ACCUMULATORS.
                -- Nothing here fires unless the status actually changed: EC-07's
                -- same-state no-op accrues nothing and re-stamps nothing.
                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    -- STRENGTHENING 2 (advisor, R-20 note): OLD.last_status_changed_at
                    -- is NOT NULL with a clock_timestamp() INSERT default and is
                    -- app-unwritable under R-19b, so the default is its ONLY legal
                    -- source — which is what makes this first interval measure from the
                    -- row's birth instant rather than from nothing.
                    elapsed := floor(EXTRACT(EPOCH FROM (clock_timestamp() - OLD.last_status_changed_at)))::bigint;

                    IF OLD.status = 'pending_customer' THEN
                        NEW.waiting_customer_seconds := OLD.waiting_customer_seconds + elapsed;
                    ELSIF OLD.status IN ('new', 'open', 'pending_internal', 'escalated') THEN
                        NEW.waiting_staff_seconds := OLD.waiting_staff_seconds + elapsed;
                    END IF;
                    -- resolved / closed / spam accrue to NEITHER party: the ticket is
                    -- waiting on nobody.

                    NEW.last_status_changed_at := clock_timestamp();

                    -- FR-24: "last transition INTO the state".
                    IF NEW.status = 'resolved' THEN
                        NEW.resolved_at := clock_timestamp();
                    END IF;
                    IF NEW.status = 'closed' THEN
                        NEW.closed_at := clock_timestamp();
                    END IF;
                    IF NEW.status = 'spam' THEN
                        NEW.spam_marked_at := clock_timestamp();
                    END IF;

                    -- FR-24: "reopen clears resolved_at until re-resolved; history
                    -- preserved in events". closed_at deliberately SURVIVES a reopen —
                    -- it remains the last transition into closed until a re-close
                    -- re-stamps it (advisor-confirmed reading).
                    IF OLD.status IN ('resolved', 'closed')
                       AND NEW.status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated') THEN
                        NEW.resolved_at := NULL;
                    END IF;

                    -- FR-09: unmark restores the prior status; the spam mark itself is
                    -- undone with it.
                    IF OLD.status = 'spam' AND NEW.status <> 'spam' THEN
                        NEW.spam_marked_at := NULL;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $fn$;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER tickets_stamp_transition_trigger
                BEFORE UPDATE ON tickets
                FOR EACH ROW
                EXECUTE FUNCTION tickets_stamp_transition()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tickets_stamp_transition_trigger ON tickets');
        DB::statement('DROP FUNCTION IF EXISTS tickets_stamp_transition()');
        // Columns are left in place: dropping them is a contract-phase change (C-12).
    }
};
