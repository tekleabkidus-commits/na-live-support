<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Templates\TemplateSeeder;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Doc 14 §21 — `sms:seed-templates {tenant}`: the seeded templates for one
 * tenant (every template event, en + am, real Ethiopic), idempotent. Used by
 * the stack seeders and the browser fixtures; the setup checklist's "Create
 * default templates" action calls the same seeder (step 13). Runs inside the
 * tenant's binding (RLS) with the console's own system actor.
 */
final class SeedSmsTemplates extends Command
{
    protected $signature = 'sms:seed-templates {tenant : the tenant id} {--actor= : the actor id recorded on the audit rows (default: the system actor id)}';

    protected $description = 'Seed the Doc 14 SMS templates (every template event, en + am) for one tenant — idempotent';

    public function handle(TenantContext $context, RlsSession $rls, TemplateSeeder $seeder): int
    {
        $tenantId = (string) $this->argument('tenant');
        $actor = $this->option('actor');
        $actorId = is_string($actor) && $actor !== '' ? $actor : '00000000-0000-7000-8000-00000000c0de';

        $context->bind($tenantId, ResolutionSource::StaffSession, (string) \Illuminate\Support\Str::uuid7());
        $rls->set($tenantId);
        try {
            $result = $seeder->seed($actorId);
        } finally {
            $rls->clear();
            $context->clear();
        }

        $this->info(sprintf('sms templates: %d created, %d already present', $result['created'], $result['skipped']));

        return self::SUCCESS;
    }
}
