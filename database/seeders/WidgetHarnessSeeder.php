<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Widget;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantKeyIssuer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Doc 13 SESSION-13 step 1 — THE HARNESS widget fixture (R-D13-1.1; risk 0l:
 * DEDICATED rows by FIXED ids, never a shared canon row; synthetic only).
 *
 * Seeds, on the browser fixture tenant, ONE widget whose allowlist admits the
 * HARNESS customer origin (`https://customer.harness.localhost:8443`) and
 * nothing else, and mints its widget key. The key plaintext is hashed at rest
 * (Doc 01 SEC-03) and exists only in this process — the seeder PRINTS it as
 * its last line so a Playwright spec reads it from the artisan output; a
 * re-run REVOKES nothing and mints a fresh key (old keys stay valid until
 * revoked through Doc 01's door; the widget row is healed to the new key).
 *
 * Runs only in local/testing/ci (the BrowserFixtureSeeder guard). Never the
 * origin: a HARNESS result is never proof of an [OA] row (R-D13-1.3).
 */
final class WidgetHarnessSeeder extends Seeder
{
    public const WIDGET_ID = '018f6b2e-0000-7000-8000-00000000d131';

    public const CUSTOMER_ORIGIN = 'https://customer.harness.localhost:8443';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('WidgetHarnessSeeder is a fixture seeder — local/testing/ci only.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }
        $tenantId = is_string($row->id) ? $row->id : throw new RuntimeException('the fixture tenant id is not a string.');

        $context = app(TenantContext::class);
        $rls = app(RlsSession::class);
        $context->clear();
        $context->bind($tenantId, ResolutionSource::Console);
        $rls->set($tenantId);

        try {
            // RIDER 2: the harness widget keeps ONE public key across seeds (it is the snippet's key; the specs read it
            // back from `key_public`) — a pre-rider row is healed once through the migrator connection (UPDATE is
            // app-denied by the rider's physics; a fixture seeder in local/testing/ci only).
            $existing = Widget::query()->find(self::WIDGET_ID);
            if ($existing !== null && is_string($existing->key_public) && $existing->key_public !== '') {
                $plaintext = $existing->key_public;
                $existing->forceFill(['allowed_origins' => [self::CUSTOMER_ORIGIN]])->save(); // heal to the canonical shape (the 0l heal actor)
            } else {
                $issued = app(TenantKeyIssuer::class)->issue(
                    kind: 'widget',
                    label: '[S13-HARNESS] customer origin widget',
                    actor: AuditActor::PlatformAdmin,
                );
                $plaintext = (string) $issued['plaintext'];
                if ($existing === null) {
                    DB::table('widgets')->insert([
                        'id' => self::WIDGET_ID,
                        'tenant_id' => $tenantId,
                        'key_id' => $issued['key']->getKey(),
                        'allowed_origins' => json_encode([self::CUSTOMER_ORIGIN], JSON_THROW_ON_ERROR),
                        'key_public' => $plaintext,
                        'created_at' => DB::raw('clock_timestamp()'),
                        'updated_at' => DB::raw('clock_timestamp()'),
                    ]);
                } else {
                    $migrator = DB::connection('pgsql_migrator');
                    $migrator->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]); // the RLS policy reads the GUC on every session, the migrator's too
                    $migrator->table('widgets')->where('id', self::WIDGET_ID)
                        ->update(['key_id' => $issued['key']->getKey(), 'key_public' => $plaintext, 'allowed_origins' => json_encode([self::CUSTOMER_ORIGIN], JSON_THROW_ON_ERROR)]);
                }
            }
            $this->command->line('HARNESS_WIDGET_KEY='.$plaintext);
            // The harness is ALWAYS OPEN by fixture (24×7 on the tenant default calendar) — the browser fixtures'
            // seeded calendar closes on weekends, which made every open-hours spec weekday-sensitive (found on a
            // Saturday at S13 step 10). The hours specs close/open explicitly through `widget:harness hours-*`.
            $writer = app(\App\Domain\Hours\CalendarWriter::class);
            $calendarId = DB::table('business_calendars')->where('is_tenant_default', true)->whereNull('department_id')->value('id');
            $weekdays = [];
            for ($d = 1; $d <= 7; $d++) {
                $weekdays[(string) $d] = [['open' => '00:00', 'close' => '24:00']];
            }
            $actor = (string) \Illuminate\Support\Str::uuid7();
            if (is_string($calendarId)) {
                $writer->update($calendarId, ['weekdays' => $weekdays], $actor);
            } else {
                $writer->create('[FIXTURE] Harness hours', 'Africa/Addis_Ababa', $weekdays, $actor, true, null);
            }
        } finally {
            $context->clear();
            $rls->clear();
        }
    }
}
