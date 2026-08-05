<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FS-05's dead-letter store (Doc 04): a poison inbound that crashed its normalizer
 * three times parks HERE with its FULL raw payload — never lost, never blocking the
 * conversation, waiting for the ops re-drive view. `claimant` is the parked job's
 * queue uuid: the dedupe slot (EC-03) may already be held under it, so the re-drive
 * job must present the SAME claimant or the re-driven message reads as a duplicate
 * and silently drops (the trap recorded in InboundDedupeStore's docblock).
 *
 * Rows are never deleted (ADR 0003 grant physics): a successful re-drive flips
 * `status` to `redriven` with actor + DB time, so the poison history survives as an
 * operational record. A re-driven job that poisons again parks a NEW row carrying
 * the same claimant — the chain is visible, nothing is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('message_dead_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->string('channel', 64);
            $table->string('channel_ref_id', 128);
            // The queue the poison arrived on, captured at park time: re-drive
            // returns the message to ITS OWN family without needing the channel
            // to still be registered in the serving process.
            $table->string('inbound_family', 64)->nullable();
            $table->jsonb('payload');           // FS-05: the FULL raw payload
            $table->uuid('claimant')->nullable();

            $table->string('error_class');
            $table->text('error_message');
            $table->smallInteger('attempts');   // FS-05's ×3, recorded as observed

            $table->string('status', 16)->default('parked');
            $table->uuid('redriven_by')->nullable();
            $table->timestampTz('redriven_at')->nullable();

            $table->timestampsTz();             // C-03; created_at = parked time (DB order via id)

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('redriven_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE message_dead_letters ADD CONSTRAINT message_dead_letters_status_check CHECK (status IN ('parked', 'redriven'))");

        // The ops list reads "parked, newest first" per tenant.
        DB::statement('CREATE INDEX message_dead_letters_list ON message_dead_letters (tenant_id, status, id)');

        DB::statement('REVOKE DELETE ON message_dead_letters FROM app');

        DB::statement('ALTER TABLE message_dead_letters ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE message_dead_letters FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON message_dead_letters
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dead_letters');
    }
};
