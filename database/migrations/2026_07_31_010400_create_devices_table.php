<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device records behind the sessions/devices screen (Doc 02 FR-04, FR-12, T-03).
 *
 * FR-04 requires every login to record time, IP, user agent and a device
 * fingerprint hash; FR-12 renders that as the Security page's table; T-03 defines
 * the fingerprint and the "new device" notice.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A DEVICE IS NOT A SESSION, and conflating them breaks two requirements.
 *
 * A device is the recognisable machine (fingerprint hash); a session is one
 * authenticated visit from it. FR-10 caps CONCURRENT SESSIONS at 5 with oldest
 * eviction, while FR-13's remember-me binds to a DEVICE across sessions and T-03
 * fires its notice per new DEVICE, not per login. One table for both would either
 * evict a device on session cap (losing the "seen before" fact that suppresses the
 * notice) or keep sessions alive past revocation.
 *
 * So: this table is the durable device inventory, and live sessions live in Redis
 * (FR-10/T-04) keyed back to a device row. Session revocation deletes the Redis
 * key; the device row survives, which is what makes "you signed in from a new
 * device" answerable at all.
 *
 * WHAT IS DELIBERATELY NOT STORED. No raw fingerprint components beyond the UA
 * string the user already sent us, no canvas/font/hardware probing — T-03's
 * fingerprint is a hash of headers the browser volunteers. This is a staff-account
 * security feature, not a tracking system, and N-06's spirit (no covert identity
 * inference) applies to staff too.
 *
 * GeoIP city (T-08) is stored RESOLVED rather than as a live lookup, because FS-04
 * requires the screen to degrade to "location unavailable" when the MMDB is
 * corrupt — a stored null does that; a lookup at render time would fail the page.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');

            // T-03: hash(UA family + platform + accept-language + client hints).
            // Hashed, not raw: it identifies without being reversible into the
            // header set it came from.
            $table->char('fingerprint_hash', 64);

            // FR-12's "device label (parsed UA)". Stored parsed so the screen
            // needs no UA-parsing at render, and so a later parser change does
            // not retroactively relabel history.
            $table->string('label')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('last_ip', 45)->nullable();

            // T-08 resolved city; NULL means "location unavailable" (FS-04).
            $table->string('last_city')->nullable();
            $table->char('last_country_code', 2)->nullable();

            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            // T-03's notice fires once per device; this records that it did, so a
            // notification storm cannot follow one new laptop around.
            $table->timestampTz('notified_at')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        // One row per (user, fingerprint): logging in again from the same machine
        // UPDATES last_seen_at rather than growing the table, which is what makes
        // "is this a new device?" a lookup instead of a heuristic.
        DB::statement('CREATE UNIQUE INDEX devices_user_fingerprint_unique ON devices (user_id, fingerprint_hash)');
        DB::statement('CREATE INDEX devices_user_recent_index ON devices (user_id, last_seen_at DESC)');

        DB::statement('REVOKE DELETE ON devices FROM app');

        DB::statement('ALTER TABLE devices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE devices FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON devices
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
