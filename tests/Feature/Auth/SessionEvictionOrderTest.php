<?php

declare(strict_types=1);

use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Doc 02 FR-10 — the concurrent-session cap evicts the OLDEST (owner ruling R-23)
|--------------------------------------------------------------------------
|
| RISK 0e: the index score was whole seconds, so six logins inside one second carried
| one identical score and Redis fell back to LEXICOGRAPHIC member order — random
| session ids. "Evict the oldest" silently meant "evict the lowest session id", and it
| reddened CI run 31216863586 after being latent since Doc 02 shipped.
|
| THE BROWSER TEST COULD NOT HAVE CAUGHT THIS RELIABLY, which is why this file exists
| alongside it: six real logins land in whatever microseconds they land in, so whether
| the genuine oldest also sorts first lexicographically is chance. Here the microseconds
| are CHOSEN, so the assertion is deterministic — the ordering property is tested
| directly rather than sampled.
*/

/** Write the session storage key a real login would have written. */
function sessionStorageKey(string $tenantId, string $sessionId): void
{
    $connection = config('session.connection');
    app('redis')->connection(is_string($connection) && $connection !== '' ? $connection : 'default')
        ->setex(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId), 3600, 'fixture-session-payload');
}

/** Six sessions inside ONE second, with controlled distinct microseconds. */
function registerSameSecondSessions(SessionRegistry $registry, string $tenantId, string $userId, array $micros): array
{
    $base = CarbonImmutable::create(2026, 8, 8, 12, 0, 0, 'UTC');
    $ids = [];

    foreach ($micros as $index => $micro) {
        // Deliberately named so that ID ORDER IS THE REVERSE of time order: if the
        // implementation ever falls back to member sort, the oldest is exactly the one
        // it will NOT pick, and this test goes red rather than passing by luck.
        $sessionId = sprintf('sess-%02d-%s', 99 - $index, str_pad((string) $index, 4, '0', STR_PAD_LEFT));

        // The INDEX IS BOOKKEEPING, NOT TRUTH (the registry's own words): a session
        // counts as live only while its storage key exists, and liveSessionsFor()
        // reconciles the index against that on every read. A fixture that wrote index
        // entries alone had every one of them discarded as dead and evicted nothing —
        // so the storage key is written too, which is what a real login does.
        sessionStorageKey($tenantId, $sessionId);

        $registry->register($tenantId, $userId, $sessionId, $base->setMicro($micro));
        $ids[$index] = $sessionId;
    }

    return $ids;
}

test('R-23/FR-10: six logins in ONE second evict the genuinely oldest, not the lowest session id', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $userId = (string) Illuminate\Support\Str::uuid7();
    $registry = app(SessionRegistry::class);

    // Ascending microseconds = ascending age. index 0 is the OLDEST.
    $ids = registerSameSecondSessions($registry, $tenantId, $userId, [1000, 2000, 3000, 4000, 5000, 6000]);

    // The 6th login is the one that just authenticated and is protected explicitly.
    $evicted = $registry->enforceCap($tenantId, $userId, max: 5, keepSessionId: $ids[5]);

    expect($evicted)->toBe([$ids[0]], sprintf(
        'SEV-1: the cap evicted %s but the oldest session was %s — ordering fell back to member sort '
        .'(RISK 0e), so "evict the oldest" is a coin flip.',
        implode(', ', $evicted), $ids[0],
    ));
});

test('R-23: the score is INTEGER microseconds — exact as an IEEE double, never fractional seconds', function () {
    $at = CarbonImmutable::create(2026, 8, 8, 12, 0, 0, 'UTC')->setMicro(123456);

    $score = SessionRegistry::scoreFor($at);

    expect($score)->toBeInt()
        ->and($score)->toBe($at->getTimestamp() * 1_000_000 + 123456)
        // The property that matters: it survives a double round-trip unchanged. A
        // `seconds.micros` float would not — that is why R-23 specified integers.
        ->and((int) (float) $score)->toBe($score)
        ->and($score)->toBeLessThan(2 ** 53);
});

test('R-23: a true same-microsecond tie still yields a TOTAL order — the cap evicts the right COUNT', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $userId = (string) Illuminate\Support\Str::uuid7();
    $registry = app(SessionRegistry::class);

    // The documented residual: identical microseconds fall back to member order. That
    // is arbitrary but TOTAL, which is what keeps the cap terminating and exact.
    $ids = registerSameSecondSessions($registry, $tenantId, $userId, [7000, 7000, 7000, 7000, 7000, 7000]);

    $evicted = $registry->enforceCap($tenantId, $userId, max: 5, keepSessionId: $ids[5]);

    expect($evicted)->toHaveCount(1, 'a true tie must still evict exactly one session, not zero and not several')
        ->and($evicted[0])->not->toBe($ids[5], 'the just-authenticated session was sacrificed — enforceCap\'s explicit protection failed');
});

test('R-23: legacy whole-second scores still sort as OLDER than post-cutover ones (no migration)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $userId = (string) Illuminate\Support\Str::uuid7();
    $registry = app(SessionRegistry::class);
    $now = CarbonImmutable::create(2026, 8, 8, 12, 0, 0, 'UTC');

    // A pre-R-23 row, written the old way, straight onto the index.
    $legacy = 'sess-legacy';
    sessionStorageKey($tenantId, $legacy);
    // Straight onto the index, on the SAME connection the registry uses, so this is
    // genuinely a pre-cutover row rather than a different store.
    $connection = config('session.connection');
    app('redis')->connection(is_string($connection) && $connection !== '' ? $connection : 'default')
        ->zadd(
            sprintf('t:%s:user-sessions:%s', $tenantId, $userId),
            $now->getTimestamp(),   // whole seconds, as before the ruling
            $legacy,
        );

    $fresh = registerSameSecondSessions($registry, $tenantId, $userId, [1000, 2000, 3000, 4000, 5000]);

    $evicted = $registry->enforceCap($tenantId, $userId, max: 5, keepSessionId: $fresh[4]);

    // ~1.7e9 vs ~1.7e15: the legacy row sorts oldest, which it genuinely is.
    expect($evicted)->toBe([$legacy],
        'a mixed index did not order correctly — the no-migration claim in R-23 does not hold');

    // And the display path reads both without multiplying a legacy score into the future.
    expect(SessionRegistry::secondsFromScore((float) $now->getTimestamp()))->toBe($now->getTimestamp())
        ->and(SessionRegistry::secondsFromScore((float) SessionRegistry::scoreFor($now)))->toBe($now->getTimestamp());
});
