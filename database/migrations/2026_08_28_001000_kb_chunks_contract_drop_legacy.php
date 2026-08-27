<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_chunks — SCHEMA AMENDMENT 4 item 8, CONTRACT phase (FLAG C as RULED by the
 * countersign: the legacy table drops IN THIS SESSION — no production data exists and
 * a dead table invites drift). Conditions met before this file existed: the swapped
 * shape's full battery was green (docs/evidence/S10-step2b/full-battery-pre-contract-01.txt)
 * and KbGrantPhysicsTest's sweep proved nothing in app/ references kb_chunks_legacy.
 *
 * @expand-contract: contract-phase SCHEMA AMENDMENT 4 item 8 / FLAG C (S10 step 2b)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS kb_chunks_legacy');
    }

    public function down(): void
    {
        // The expand migration is the only way back; a dropped legacy table is not recreated here.
        Schema::dropIfExists('kb_chunks_legacy');
    }
};
