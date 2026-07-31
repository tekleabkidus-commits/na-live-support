<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NFR-08 / C-03: ALL timestamps are timestamptz, stored UTC — including the framework's.
 *
 * Laravel's failed_jobs scaffold ships `failed_at` as timestamp WITHOUT time zone, the
 * single offender GrantPhysicsTest's schema sweep found. A failed job's timestamp is
 * operational forensics ("did the burst and the failures line up?"), which is exactly
 * where a naive local time silently lies. USING … AT TIME ZONE 'UTC' declares what the
 * stored naive values already meant — the server runs UTC — rather than shifting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamp USING failed_at AT TIME ZONE 'UTC'");
    }
};
