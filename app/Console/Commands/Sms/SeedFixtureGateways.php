<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Doc 14 §21 / R-D14-1 — `sms:seed-fixture-gateways {tenant}`: the two FIXTURE
 * gateways + the `+251` route for one tenant on the local stack; refuses in
 * production. Never a real token (incident #16).
 */
final class SeedFixtureGateways extends Command
{
    protected $signature = 'sms:seed-fixture-gateways {tenant : the tenant id} {--mock=http://mock-sms:8082 : the mock-sms origin}';

    protected $description = 'Seed the Doc 14 FIXTURE gateways (GeezSMS profile + reference profile) and the +251 route for one tenant — local/testing/ci only';

    public function handle(TenantContext $context, RlsSession $rls, FixtureGatewaySeeder $seeder): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('fixture gateways never exist in production (R-D14-1)');

            return self::FAILURE;
        }
        $tenantId = (string) $this->argument('tenant');
        $mock = (string) $this->option('mock');
        $context->bind($tenantId, ResolutionSource::StaffSession, (string) \Illuminate\Support\Str::uuid7());
        $rls->set($tenantId);
        try {
            $seeded = $seeder->seed('00000000-0000-7000-8000-00000000c0de', $mock);
        } finally {
            $rls->clear();
            $context->clear();
        }
        $this->info(sprintf('fixture gateways: geezsms=%s reference=%s route=%s', $seeded['geezsms']->identifier(), $seeded['reference']->identifier(), $seeded['route']->identifier()));

        return self::SUCCESS;
    }
}
