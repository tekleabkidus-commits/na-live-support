<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant human-facing counters (Doc 01 T-02).
 *
 * Ticket #1 must be tenant A's first ticket AND tenant B's first ticket. A
 * Postgres SEQUENCE cannot do that — it is global, and it also does not roll
 * back, so a failed transaction would burn ticket numbers and the operator
 * would ask why their tickets skip. So: a row per (tenant, name), incremented
 * with `UPDATE … RETURNING` inside the owning transaction.
 *
 * That statement takes a row lock for the remainder of the transaction, which is
 * exactly the serialisation we want: concurrent callers queue, each gets a
 * distinct value, and a rollback returns the number to the pool. The cost is
 * that a long transaction holding a counter blocks other allocations for the
 * same tenant and name — so allocate LATE, as close to commit as possible.
 * tests/Property/TenantSequenceTest.php hammers this concurrently and asserts
 * no duplicates and no gaps.
 *
 * Never use this for ordering decisions (EC-08) — it is a display number, not a
 * clock. Ordering is UUIDv7 or DB time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 64);              // e.g. "ticket_number"
            $table->bigInteger('next_value')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'name']);
        });

        DB::statement('CREATE INDEX tenant_sequences_tenant_id_index ON tenant_sequences (tenant_id)');

        // A counter never goes backwards, whatever the caller believes.
        DB::statement('ALTER TABLE tenant_sequences ADD CONSTRAINT tenant_sequences_next_value_check CHECK (next_value >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sequences');
    }
};
