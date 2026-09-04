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
            $issued = app(TenantKeyIssuer::class)->issue(
                kind: 'widget',
                label: '[S13-HARNESS] customer origin widget',
                actor: AuditActor::PlatformAdmin,
            );

            $existing = Widget::query()->find(self::WIDGET_ID);
            if ($existing === null) {
                DB::table('widgets')->insert([
                    'id' => self::WIDGET_ID,
                    'tenant_id' => $tenantId,
                    'key_id' => $issued['key']->getKey(),
                    'allowed_origins' => json_encode([self::CUSTOMER_ORIGIN], JSON_THROW_ON_ERROR),
                    'created_at' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
            } else {
                // Heal to the canonical shape every run (the 0l heal actor).
                $existing->forceFill([
                    'key_id' => $issued['key']->getKey(),
                    'allowed_origins' => [self::CUSTOMER_ORIGIN],
                ])->save();
            }

            $this->command->line('HARNESS_WIDGET_KEY='.$issued['plaintext']);
        } finally {
            $context->clear();
            $rls->clear();
        }
    }
}
