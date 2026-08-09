<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot message templates (Doc 05 FR-28; frames E07–E08, E11).
 *
 * Per-bot per-language OPERATOR CONTENT (not settings, not i18n keys — the
 * §14 rule and the B6-X01 crosswalk both mark this "not a setting"): the six
 * §8/E07 message kinds, operator-editable with {{variable}} support. BR-06's
 * "redirect always carries a tappable account link" is the write-path
 * validator's job (step 9), tested there with a sabotage.
 *
 * - DELETE stays GRANTED as a DECISION: removing a language's templates is a
 *   legitimate operator act, and §14's completeness indicators (and the
 *   /language picker gate, FR-25) read presence/absence of rows. Asserted
 *   explicitly in TelegramGrantPhysicsTest.
 * - `created_at`/`updated_at` are bookkeeping — no ordering key, cursor, or
 *   reported metric reads them (plan's stated R-19b analysis): DB-time
 *   default for hygiene, grants untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bot_id');
            $table->text('language');
            $table->text('template_key');
            $table->text('body');
            $table->uuid('updated_by')->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bot_id')->references('id')->on('telegram_bots');
            $table->foreign('updated_by')->references('id')->on('users');

            $table->unique(['tenant_id', 'bot_id', 'language', 'template_key']);
        });

        // FR-28's fixed v1 kind set (§8's six composer-visible messages).
        DB::statement("ALTER TABLE telegram_templates ADD CONSTRAINT telegram_templates_key_check CHECK (template_key IN ('greeting', 'off_hours', 'handoff', 'resolved_csat', 'redirect', 'window_guidance'))");

        DB::statement('ALTER TABLE telegram_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_templates FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_templates
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_templates');
    }
};
