<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Sessions\SessionLifetimePolicy;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The deadline heartbeat behind §8's timeout toast (Doc 02 §8, FR-11; owner
 * ruling, step 13).
 *
 * THE LOAD-BEARING ASSERTION, named as such at plan approval: a call ROLLS THE
 * IDLE CLOCK (that is what makes "stay signed in" real) and NEVER MOVES THE
 * ABSOLUTE ONE (that is what keeps FR-11's cap a cap — a stolen session must
 * not be able to heartbeat itself immortal). Everything else here is contract
 * shape.
 */
function deadlineUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create());
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [(string) $tenant->getKey(), $user];
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('answers 404 with no session — C-10\'s indistinguishable refusal, which is exactly the A-2 probe contract', function (): void {
    test()->getJson('https://localhost/api/staff/session/deadline')->assertNotFound();
});

it('rolls the idle clock by being called, and NEVER touches the absolute cap (the load-bearing pair)', function (): void {
    [, $user] = deadlineUser();

    CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-08-03T12:00:00Z'));

    $established = $now->subHours(10);

    $response = test()->actingAs($user)->withSession([
        SessionLifetimePolicy::ESTABLISHED_AT => $established->toIso8601String(),
        // 100 idle minutes already burned when the call arrives…
        SessionLifetimePolicy::LAST_ACTIVITY_AT => $now->subMinutes(100)->toIso8601String(),
    ])->getJson('https://localhost/api/staff/session/deadline');

    $response->assertOk();

    // …and the response reports a FULL idle budget again: the request itself
    // rolled the clock (EnforceSessionLifetime's touch, wired ahead of the
    // controller). If this reads now+380min the middleware is not in front.
    expect($response->json('idle_deadline'))
        ->toBe($now->addMinutes(480)->toIso8601String(), 'the call did not roll the idle clock');

    // The absolute deadline is the ESTABLISHMENT's arithmetic, untouched by
    // this call — and demonstrably not now+72h.
    expect($response->json('absolute_deadline'))
        ->toBe($established->addHours(72)->toIso8601String(), 'the absolute cap moved — a heartbeat must never extend it')
        ->and($response->json('absolute_deadline'))
        ->not->toBe($now->addHours(72)->toIso8601String());

    // Effective = min(idle, absolute): 8h of idle vs 62h of absolute.
    expect($response->json('effective_seconds_remaining'))->toBe(480 * 60);

    // A second call, later: idle rolls again, the absolute answer is
    // byte-identical. This is "stay signed in" working and bounded at once.
    CarbonImmutable::setTestNow($now->addMinutes(30));

    $second = test()->getJson('https://localhost/api/staff/session/deadline');
    $second->assertOk();

    expect($second->json('idle_deadline'))->toBe($now->addMinutes(30 + 480)->toIso8601String())
        ->and($second->json('absolute_deadline'))->toBe($response->json('absolute_deadline'));
});

it('reports the ABSOLUTE clock as the effective deadline when it is the nearer one', function (): void {
    [, $user] = deadlineUser();

    CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-08-03T12:00:00Z'));

    // 71 h into a 72 h session: one hour of absolute life left, a full idle
    // budget of eight — the toast must argue from the hour.
    $response = test()->actingAs($user)->withSession([
        SessionLifetimePolicy::ESTABLISHED_AT => $now->subHours(71)->toIso8601String(),
        SessionLifetimePolicy::LAST_ACTIVITY_AT => $now->subMinute()->toIso8601String(),
    ])->getJson('https://localhost/api/staff/session/deadline');

    $response->assertOk();
    expect($response->json('effective_seconds_remaining'))->toBe(3600);
});
