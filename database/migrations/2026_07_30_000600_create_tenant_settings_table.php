<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant setting OVERRIDES (Doc 01 FR-07, T-04).
 *
 * Defaults live in code (registries/settings.php) and are never written here. A
 * row exists only where a tenant has diverged from the platform default, which
 * is what makes "reset to default" (Doc 01 §8) a DELETE of an override rather
 * than a lookup of what the default used to be.
 *
 * `value` is jsonb for every type. An int setting stores `42`, a bool `true`, an
 * encrypted-string stores its ciphertext as a JSON string. One column, one
 * decode path, and the registry — not the row — decides how to interpret it.
 * Reading or writing a key the registry does not know is an error, not a silent
 * pass (FR-07).
 *
 * `updated_at` is the optimistic-concurrency token for EC-06: two admins racing
 * on one key both get audited, the loser gets a conflict signal, and neither
 * write is silently lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 128);
            $table->jsonb('value');

            // Nullable: a platform-initiated change (provisioning defaults,
            // a migration) has no staff actor. Audit records the actor type.
            $table->uuid('updated_by')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'key']);
        });

        DB::statement('CREATE INDEX tenant_settings_tenant_id_index ON tenant_settings (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
