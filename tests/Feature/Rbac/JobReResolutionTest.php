<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * SEC-06 / FR-06's queued-work enforcement point: a job acting "as user"
 * re-resolves AT EXECUTION TIME — a demoted user's queued action runs under
 * POST-demotion rights, however privileged they were at dispatch.
 *
 * Driven through the REAL queue machinery (database queue + queue:work), not
 * dispatchSync: the whole point is the time gap between dispatch and
 * execution.
 */
final class RecordVerdictAtExecution implements ShouldQueue
{
    use Queueable, TenantAware;

    public static ?bool $verdictAtExecution = null;

    public function __construct(public string $userId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $user = User::query()->whereKey($this->userId)->firstOrFail();

        // The enforcement point under test: resolve NOW, not at dispatch.
        self::$verdictAtExecution = app(CapabilityMapProvider::class)
            ->verdictFor($user, 'customers.merge')->allowed;
    }
}

it('runs a demoted user\'s queued action under POST-demotion rights (SEC-06, FR-06)', function () {
    config(['queue.default' => 'database']);
    $tenant = createTenant();

    $user = asTenant($tenant, fn () => grantPermissions(User::factory()->create(), ['customers.merge', 'tickets.view'], 'Pre-Demotion Role'));
    commitFixtures();

    asTenant($tenant, function () use ($user): void {
        // Dispatch WHILE PRIVILEGED: at this moment the verdict is allow.
        expect(app(CapabilityMapProvider::class)->verdictFor($user, 'customers.merge')->allowed)->toBeTrue();
        RecordVerdictAtExecution::dispatch($user->identifier());

        // The demotion lands BETWEEN dispatch and execution.
        grantPermissions($user, ['tickets.view'], 'Post-Demotion Role');
    });

    try {
        RecordVerdictAtExecution::$verdictAtExecution = null;
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        expect(RecordVerdictAtExecution::$verdictAtExecution)->toBeFalse(
            'SEV-1: a queued action executed under PRE-demotion rights',
        );
    } finally {
        cleanupTenants([$tenant->identifier()]);
    }
});
