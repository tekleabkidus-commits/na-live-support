<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 — RIDER 2 (filed to ANSWERS before this file exists):
 * the queued action chain lands its outcomes AFTER the evaluation row's
 * INSERT, so `automation_rule_executions` gains UPDATE on EXACTLY
 * (action_outcomes). Everything else stays immutable by grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (action_outcomes) ON automation_rule_executions TO app');
    }

    public function down(): void
    {
        DB::statement('REVOKE UPDATE (action_outcomes) ON automation_rule_executions FROM app');
    }
};
