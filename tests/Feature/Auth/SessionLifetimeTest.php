<?php

declare(strict_types=1);

use App\Support\Auth\Sessions\SessionLifetimePolicy;
use Carbon\CarbonImmutable;

/**
 * FR-11's two timeouts, asserted at their exact boundaries (Doc 02 FR-11).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CLOCK IS A PARAMETER, so these run in microseconds instead of hours and
 * assert the boundary rather than somewhere near it. CLAUDE.md §8 forbids a test
 * asserting on now(); this is the other half of that rule — production code that
 * cannot be given a clock forces the test to sleep, and a suite that sleeps is a
 * suite people stop running.
 *
 * WHY BOTH TIMEOUTS EXIST, which is what the last test here pins down: the idle
 * clock protects the unattended desk, and it NEVER fires for a stolen session
 * that is being actively used, because the thief keeps refreshing it. The
 * absolute clock is the one that bounds that case, and it only does so if it is
 * never reset by activity. A future "fix" that refreshes established_at on
 * activity would make every session immortal in exactly the hands that matter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function lifetimePolicy(): SessionLifetimePolicy
{
    return app(SessionLifetimePolicy::class);
}

it('reads FR-11 defaults from the settings registry', function () {
    expect(lifetimePolicy()->idleMinutes())->toBe(480)
        ->and(lifetimePolicy()->absoluteHours())->toBe(72)
        ->and(lifetimePolicy()->maxConcurrent())->toBe(5);
});

it('expires an idle session at the boundary and not one minute before', function () {
    $policy = lifetimePolicy();
    $lastActivity = CarbonImmutable::parse('2026-08-01T09:00:00Z');

    // 479 minutes: still alive. 480: expired. The boundary is asserted from both
    // sides, because an off-by-one here either logs people out early (support
    // tickets) or never (a security hole nobody notices).
    expect($policy->exceededIdle($lastActivity, $lastActivity->addMinutes(479)))->toBeFalse()
        ->and($policy->exceededIdle($lastActivity, $lastActivity->addMinutes(480)))->toBeTrue()
        ->and($policy->exceededIdle($lastActivity, $lastActivity->addMinutes(481)))->toBeTrue();
});

it('expires a session at the absolute limit however recently it was used', function () {
    $policy = lifetimePolicy();
    $established = CarbonImmutable::parse('2026-08-01T09:00:00Z');

    expect($policy->exceededAbsolute($established, $established->addHours(71)))->toBeFalse()
        ->and($policy->exceededAbsolute($established, $established->addHours(72)))->toBeTrue();
});

it('kills a CONTINUOUSLY ACTIVE session at the absolute limit (the stolen-session case)', function () {
    $policy = lifetimePolicy();
    $established = CarbonImmutable::parse('2026-08-01T09:00:00Z');

    // The thief refreshes every minute, so the idle clock never fires…
    $now = $established->addHours(73);
    $lastActivity = $now->subMinute();

    expect($policy->exceededIdle($lastActivity, $now))->toBeFalse('idle should NOT fire on an active session');

    // …and the absolute clock is what ends it. This is the whole reason FR-11
    // specifies two timeouts rather than one.
    expect($policy->hasExpired($established, $lastActivity, $now))->toBeTrue(
        'SEV-1: a session past its absolute lifetime survived because it was being actively used — '
        .'which is precisely the stolen-session case the absolute limit exists for',
    );
});

it('treats a session with no recorded marks as live, not expired', function () {
    // The request immediately after login has an established_at but may not yet
    // have a recorded activity mark. Reading "unknown" as "expired" would log
    // every user out one request after signing in.
    $policy = lifetimePolicy();
    $now = CarbonImmutable::parse('2026-08-01T09:00:00Z');

    expect($policy->hasExpired(null, null, $now))->toBeFalse()
        ->and($policy->hasExpired($now, null, $now))->toBeFalse();
});
