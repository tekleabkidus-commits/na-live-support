<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reserved owner-phone staff identity (Doc 05 T-03/BR-03, ruling A-27).
 *
 * One row per business connection, lazily created the first time the owner
 * replies from the phone. `messages.author_id` (FK-less uuid) points here for
 * an owner-phone reply; reports map it as agent type `owner_phone` (T-03).
 *
 * EC-05 (forward-rename): `display_name` updates from the connection's current
 * account name on each new owner-phone message — but HISTORICAL attribution
 * keeps the old display because each message snapshots the name it was
 * written under into channel_meta.owner_phone_display (the Doc 08 pattern).
 * So this row is "current identity"; the message carries "identity as of
 * write time".
 *
 * Timestamps are bookkeeping (no ordering key, cursor, or reported metric
 * reads them — plan R-19b analysis): DB-time defaults, grants untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_owner_phone_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('connection_id');      // FK to the business connection row
            $table->bigInteger('account_user_id'); // the owner's Telegram user id (the authority key)
            $table->text('display_name');       // "{account_name} (phone)", updated forward (EC-05)

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('connection_id')->references('id')->on('telegram_business_connections');

            $table->unique(['tenant_id', 'connection_id']);
        });

        DB::statement('ALTER TABLE telegram_owner_phone_identities ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_owner_phone_identities FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_owner_phone_identities
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_owner_phone_identities');
    }
};
