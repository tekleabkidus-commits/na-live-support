<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\OptOut\OptOutRegistry;
use App\Domain\Sms\Templates\TemplateSeeder;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SMS admin-screen fixtures for the [B14-batch] browser proof (Doc 14 step
 * 13): the 22 seeded templates, the two FIXTURE gateways + the +251 route, a
 * few lifecycle rows in distinct states (masked on every screen), one opt-out
 * and a NON-COVERING quiet window so nothing holds by accident. Runs against
 * the BrowserFixtureSeeder tenant; idempotent. All content synthetic
 * (CLAUDE.md §8): +251-90000xxxx phones only.
 */
final class SmsBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('SmsBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }
        $tenantId = $row->id;

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenantId, ResolutionSource::Console);
        app(RlsSession::class)->set($tenantId);

        app(TemplateSeeder::class)->seed($tenantId);
        app(FixtureGatewaySeeder::class)->seed($tenantId);

        // a non-covering quiet window (the smsPipeScene discipline): screens must not
        // depend on the wall clock
        $nowAddis = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Addis_Ababa'));
        app(\App\Support\Settings\SettingsRepository::class)->set('sms.quiet_hours', [
            'start' => $nowAddis->modify('+3 hours')->format('H:i'),
            'end' => $nowAddis->modify('+4 hours')->format('H:i'),
        ]);

        // lifecycle rows in distinct states for the queue screen (idempotent by phone)
        $p = DB::connection('pgsql_platform');
        $states = [
            ['+251900007101', 'queued', 'ticket_resolved'],
            ['+251900007102', 'sent', 'ticket_created'],
            ['+251900007103', 'delivered', 'service_announcement'],
            ['+251900007104', 'failed', 'ticket_resolved'],
        ];
        foreach ($states as [$phone, $state, $event]) {
            if ($p->table('sms_messages')->where('tenant_id', $tenantId)->where('phone', $phone)->exists()) {
                continue;
            }
            $insert = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'event' => $event,
                'language' => 'en',
                'phone' => $phone,
                'rendered_body' => '[FIXTURE] browser queue row',
                'state' => $state === 'delivered' ? 'sent' : $state,
                'lane' => 'sms-transactional',
                'segments' => 1,
            ];
            if ($state === 'failed') {
                $insert['state'] = 'failed';
                $insert['fail_reason'] = 'no_route_available';
            }
            $p->table('sms_messages')->insert($insert);
            if ($state === 'delivered') {
                // a REAL delivered terminal: stamped through an update the trigger allows
                $p->table('sms_messages')->where('tenant_id', $tenantId)->where('phone', $phone)->update(['state' => 'delivered']);
            }
        }

        // one opt-out through the registry (idempotent by phone)
        if (! $p->table('sms_optouts')->where('tenant_id', $tenantId)->where('phone', '+251900007900')->exists()) {
            $agentId = $p->table('users')->where('tenant_id', $tenantId)->where('email', 'agent@fixture.example')->value('id');
            if (! is_string($agentId)) {
                throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture agent does not exist.');
            }
            app(OptOutRegistry::class)->addByStaff('+251900007900', '[FIXTURE] asked at the counter', $agentId);
        }

        app(TenantContext::class)->clear();
        app(RlsSession::class)->clear();
    }
}
