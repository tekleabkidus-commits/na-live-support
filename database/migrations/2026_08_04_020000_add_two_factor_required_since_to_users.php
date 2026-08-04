<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 02 FR-09's grace anchor (closed at S19 step 7 with the role resolver).
 *
 * "Grace period auth.2fa.grace_days with countdown banner" needs a per-user
 * starting point the document does not name. FILED INTERPRETATION (S19): the
 * anchor is when enforcement FIRST APPLIED to the user — stamped by the
 * middleware on first subject encounter — because it is the only moment that
 * exists for every path into scope (role assigned later, role added to the
 * setting later, user invited later) and it can never predate the user's
 * ability to comply. Nullable, expand-contract (C-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('two_factor_required_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_required_since');
        });
    }
};
