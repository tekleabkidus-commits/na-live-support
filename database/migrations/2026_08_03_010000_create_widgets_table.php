<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widget records — the Doc 02-owned MINIMAL shape (Doc 02 FR-21, AC-06; owner
 * ruling, step-10 plan approval).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS TABLE EXISTS IN DOC 02 AND NOT DOC 13.
 *
 * FR-21 validates a session request "against the widget's domain allowlist",
 * so the allowlist must exist the day visitor tokens exist — which is this
 * step, several documents before Doc 13 builds the Widget Designer that edits
 * it. The tenant_keys migration already planned for this: "Doc 13 owns widget
 * records … those documents attach their own records to a row here." This is
 * that record, holding ONLY what Doc 02 needs: which key it belongs to and
 * which origins may open a session. Appearance, content, behavior, install and
 * health config are Doc 13 columns, added expand-contract when Doc 13 builds
 * (C-12).
 *
 * WHY THE ALLOWLIST CANNOT LIVE ON tenant_keys ITSELF. The app role holds NO
 * table privilege on tenant_keys — its only door is resolve_tenant_key(),
 * which answers one narrow question. Widening that door to also return config
 * would grow the SECURITY DEFINER surface for convenience, which is exactly
 * the temptation its docblock warns against. This table is ordinary
 * tenant-scoped data: by the time anyone reads it, the widget key has already
 * resolved the tenant, so plain RLS-governed reads are correct.
 *
 * ALLOWLIST ENTRY SEMANTICS (approved ruling, step 10 decision 5): entries are
 * exact https origins ("https://help.brand.com", "https://brand.com:8443") or
 * wildcard-subdomain hosts ("*.brand.com", optional ":port"). Matching rules
 * live in OriginAllowlist — the single matcher AC-06's fixtures pin.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            // The tenant_keys row (kind=widget) this widget's public key lives
            // in. One widget per key: the key IS the widget's identity in the
            // loader snippet (Doc 13 FR-01), so two widgets sharing a key would
            // be indistinguishable at resolution.
            $table->uuid('key_id');

            // FR-21's domain allowlist. JSONB list of entry strings; semantics
            // are pinned in OriginAllowlist and its fixtures. Default empty:
            // an unconfigured widget allows NOTHING (fail-closed), so a minted
            // key is inert until an allowlist is deliberately set.
            $table->jsonb('allowed_origins')->default('[]');

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('key_id')->references('id')->on('tenant_keys');
        });

        DB::statement('CREATE UNIQUE INDEX widgets_key_id_unique ON widgets (key_id)');
        DB::statement('CREATE INDEX widgets_tenant_id_index ON widgets (tenant_id)');

        // Same physics as every Doc 02 tenant table (Doc 01 inherited task #6):
        // deletes are refused at the grant layer; RLS enabled AND forced.
        DB::statement('REVOKE DELETE ON widgets FROM app');

        DB::statement('ALTER TABLE widgets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE widgets FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON widgets
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
