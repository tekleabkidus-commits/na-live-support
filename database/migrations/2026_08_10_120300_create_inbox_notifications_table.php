<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The notification bell's rows (Doc 07 FR-26): one row per user per event in
 * the enumerated bell set (assignment, mention, follower events, escalation-
 * to-me, SLA warning on mine, window-closing on mine, bulk-completed). Every
 * category is registered in registries/notifications.php BEFORE any producer
 * ships (the C3 precedent); each row deep-links; mark-all-read writes the
 * read_at latch. Payload carries IDENTIFIERS ONLY (Q7's posture) — SEC-04's
 * shoulder-surfing rule is enforced at the desktop-notification renderer, but
 * the row itself never stores message content either.
 *
 * R-19b (approved plan §6): `created_at` FULL treatment — the bell list is
 * ORDERED by it, so it is db-time-only from birth: clock_timestamp() default,
 * INSERT and UPDATE both column-revoked for app. `read_at` is a latch.
 *
 * THIS IS SABOTAGE #11's TABLE: dropping this migration's revoke must turn
 * InboxGrantPhysicsTest's schema-derived guard red at the migration level.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('inbox_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01 (UUIDv7)
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');

            $table->text('category');           // a registered notify.inbox.* key
            $table->uuid('ticket_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->jsonb('payload')->default('{}');   // identifiers only (Q7)

            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        DB::statement('CREATE INDEX inbox_notifications_bell_index ON inbox_notifications (tenant_id, user_id, created_at DESC)');

        DB::statement('ALTER TABLE inbox_notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbox_notifications FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON inbox_notifications
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        // R-19b + append-only posture: INSERT column-granted (created_at
        // excluded — defaults fill), UPDATE granted on the read latch only,
        // DELETE revoked.
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON inbox_notifications FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, user_id, category, ticket_id, conversation_id, payload) ON inbox_notifications TO app');
        DB::statement('GRANT UPDATE (read_at) ON inbox_notifications TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');
    }
};
