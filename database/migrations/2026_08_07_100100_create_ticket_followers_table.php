<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket followers (Doc 06 FR-18) — a pure membership marker: staff with view scope
 * follow/unfollow themselves (`tickets.follow`); assigners may add others
 * (`tickets.assign`, filed mapping A-9). Notification fan-out is Doc 16's; this table
 * is the set it reads.
 *
 * DELETE IS DELIBERATELY KEPT for the app role. ADR 0003 revokes DELETE on business
 * tables because nothing hard-deletes at tenant scope — but unfollowing genuinely IS
 * deleting the membership row, exactly the `tenant_settings` precedent ("reset to
 * default genuinely is deleting the override row"). There is no history requirement on
 * follower membership anywhere in Doc 06; the follower_added/removed EVENTS are the
 * history. TicketGrantPhysicsTest asserts this exception explicitly so it reads as a
 * decision, not an omission.
 *
 * `added_by` records FR-18's assigner-adds case (null = self-follow).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_followers', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('ticket_id');
            $table->uuid('user_id');
            $table->uuid('added_by')->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('ticket_id')->references('id')->on('tickets');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('added_by')->references('id')->on('users');

            $table->unique(['ticket_id', 'user_id']);
        });

        DB::statement('ALTER TABLE ticket_followers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ticket_followers FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ticket_followers
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_followers');
    }
};
