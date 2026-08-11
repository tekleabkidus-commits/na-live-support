<?php

declare(strict_types=1);

use App\Jobs\Middleware\BindTenantContextToJob;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/*
|--------------------------------------------------------------------------
| A-35 (the FIFTH ruled core edit, 2026-08-10) — BindTenantContextToJob is
| restore-what-you-found. Two sightings forced the ruling: the S07 step-6
| indexer (docs/evidence/S07-step6/) and the S07 step-7 inline bulk
| (docs/evidence/S07-step7/) — both times a job running INLINE under the
| sync driver stripped the DISPATCHER's ambient binding, because the
| middleware's finally CLEARED instead of restoring. Sabotage #22 reverts
| the middleware to clear-not-restore and these proofs go red.
|--------------------------------------------------------------------------
*/

final class A35FixtureJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAware;

    public static ?string $observedTenant = null;

    public function __construct()
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        self::$observedTenant = app(TenantContext::class)->tenantIdOrNull();
    }
}

it('A-35 diff assertion: the ruled edit is IN the middleware — capture-then-restore, citing the ruling and both sighting evidence paths (sabotage #22 reverts exactly this and the proofs below go red)', function (): void {
    $source = (string) file_get_contents(app_path('Jobs/Middleware/BindTenantContextToJob.php'));

    // str_contains + toBeTrue, never variadic toContain (the S22 7b trap).
    expect(str_contains($source, 'A-35'))->toBeTrue('the middleware no longer cites ruling A-35');
    expect(str_contains($source, 'docs/evidence/S07-step6/'))->toBeTrue('the first sighting evidence path is gone from the middleware');
    expect(str_contains($source, 'docs/evidence/S07-step7/'))->toBeTrue('the second sighting evidence path is gone from the middleware');
    expect(str_contains($source, '$priorTenantId = $context->tenantIdOrNull();'))->toBeTrue('the prior-binding CAPTURE is gone (clear-not-restore is back)');
    expect(str_contains($source, 'if ($priorTenantId !== null && $priorSource !== null) {'))->toBeTrue('the finally\'s RESTORE branch is gone (clear-not-restore is back)');
});

it('A-35 restore semantics under the SYNC driver: an inline job binds its own tenant for the handler, and the DISPATCHER\'s binding — context AND RLS — is exactly what it was before, even across tenants', function (): void {
    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    $jobTenant = activateTenant(createTenant());
    $callerTenant = activateTenant(createTenant());

    // The dispatcher's world: bound to caller, with its own correlation id.
    bindTenant($callerTenant);
    $callerCorrelation = $context->correlationId();

    // The cross-tenant shape (the step-7 sighting was same-tenant; this is
    // strictly harder): the job carries ANOTHER tenant's id, runs inline.
    A35FixtureJob::$observedTenant = null;
    $job = new A35FixtureJob;
    $job->tenantId = $jobTenant->identifier();
    app(Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync($job);

    // The handler really ran inside the JOB's tenant (FR-03f binding held) …
    expect(A35FixtureJob::$observedTenant)->toBe($jobTenant->identifier(), 'the middleware no longer binds the job tenant for the handler');

    // … and the caller got its OWN world back: tenant, RLS session variable,
    // and correlation id (C-14 — the caller's trace must not fork).
    expect($context->tenantIdOrNull())->toBe($callerTenant->identifier(), 'the dispatcher\'s context binding was not restored (the A-35 defect is back)');
    expect($rls->current())->toBe($callerTenant->identifier(), 'the dispatcher\'s RLS session variable was not restored (the A-35 defect is back)');
    expect($context->correlationId())->toBe($callerCorrelation, 'the dispatcher\'s correlation id was not restored (C-14 trace forked)');
});

it('A-35 worker shape unchanged: with NO prior binding the finally still ends CLEAN — a failing job cannot hand its tenant to the next one on the connection (the R-01 discipline the ruling explicitly keeps)', function (): void {
    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    $jobTenant = activateTenant(createTenant());

    // A worker's world: nothing bound when the job is picked up.
    $context->clear();
    $rls->clear();

    A35FixtureJob::$observedTenant = null;
    $job = new A35FixtureJob;
    $job->tenantId = $jobTenant->identifier();
    app(Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync($job);

    expect(A35FixtureJob::$observedTenant)->toBe($jobTenant->identifier());
    expect($context->tenantIdOrNull())->toBeNull('a job with no prior binding must leave the connection CLEAN, not bound');
    // A cleared app.tenant_id reads back as '' from set_config, not NULL.
    expect((string) $rls->current())->toBe('', 'the RLS session variable leaked past the job (the R-01 hazard)');
});
