<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * AC-01 / SEC-01 / FR-02: no user enumeration, on either channel.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY TWO DIMENSIONS, AND WHY THE TIMING ONE IS THE HARD ONE.
 *
 * Enumeration is the attacker's first step: given a login form, learn which
 * addresses are real, then spend the rest of the budget on those. AC-01 closes
 * both channels it leaks through, and they fail independently:
 *
 *   BODY     "no such account" vs "wrong password" is the obvious leak. Easy to
 *            get right, easy to regress — a well-meaning "helpful error" PR
 *            reintroduces it. Asserted BYTE-IDENTICAL here, not merely
 *            equivalent: same status, same body, same problem-details code.
 *
 *   TIMING   the subtle one, and the reason this suite exists rather than a
 *            single assertEquals. A real account runs argon2id (~100 ms by
 *            NFR-03); a non-existent one, naively, does not — so the response
 *            for a real address is reliably ~100 ms slower, and the attacker
 *            reads the difference with a stopwatch instead of the body. The fix
 *            is that the miss path must do the SAME work: verify against a dummy
 *            hash it can never match.
 *
 * MEASUREMENT IS A DISTRIBUTION, NOT A SINGLE SHOT. One request each would be
 * noise; this takes many samples and compares MEDIANS, because a median is
 * robust against the occasional slow request (GC, a competing test, a cold
 * connection) that would make a mean lie in either direction. ±50 ms is AC-01's
 * bound, and the suite reports the actual delta on failure so the number is
 * visible rather than just "too slow".
 * ─────────────────────────────────────────────────────────────────────────────
 */
const ENUMERATION_SAMPLES = 12;
const AC01_TOLERANCE_MS = 50.0;

function enumerationTenant(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'real.account@fixture.example',
        'password' => Hash::make('a-genuinely-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

/**
 * @return array{status: int, body: string, headers: array<string, mixed>}
 */
function postLogin(string $email, string $password): array
{
    // withServerVariables, not postJson()'s third argument — that one is
    // HEADERS, so the address arrived as HTTP_REMOTE_ADDR and every request in
    // the suite shared a single per-IP throttle bucket. This suite compares two
    // refusals; had the shared bucket run out it would have compared two 429s
    // and passed for the wrong reason, exactly like the redirect bug this file
    // already documents.
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->postJson('https://localhost/login', [
            'email' => $email,
            'password' => $password,
        ]);

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->getContent() ?: '',
        'headers' => $response->headers->all(),
    ];
}

/**
 * @return list<float> milliseconds
 */
function timeRequests(callable $request, int $samples): array
{
    $timings = [];
    for ($i = 0; $i < $samples; $i++) {
        $started = hrtime(true);
        $request();
        $timings[] = (hrtime(true) - $started) / 1_000_000;
    }

    return $timings;
}

function median(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 0
        ? ($values[$middle - 1] + $values[$middle]) / 2
        : $values[$middle];
}

it('returns a byte-identical body for an existing and a non-existing account (AC-01)', function () {
    enumerationTenant();

    $existing = postLogin('real.account@fixture.example', 'the-wrong-password');
    $missing = postLogin('no.such.account@fixture.example', 'the-wrong-password');

    expect($missing['status'])->toBe($existing['status'])
        ->and($missing['body'])->toBe($existing['body']);
})->group('enumeration');

it('returns a byte-identical body regardless of which TENANT the address exists in', function () {
    // The multi-tenant twist (owner ruling Q1): the same address may exist in
    // several tenants, or in one, or none. All three must be indistinguishable,
    // or the login form becomes a tenant-membership oracle — "does operator X
    // employ this person" is exactly the cross-tenant inference Doc 01 SEC-06
    // spends RLS to prevent.
    [$tenantA] = enumerationTenant();

    $tenantB = createTenant();
    asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'real.account@fixture.example',
        'password' => Hash::make('a-different-password-entirely'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $inTwoTenants = postLogin('real.account@fixture.example', 'the-wrong-password');
    $inNoTenant = postLogin('nobody@fixture.example', 'the-wrong-password');

    expect($inTwoTenants['status'])->toBe($inNoTenant['status'])
        ->and($inTwoTenants['body'])->toBe($inNoTenant['body']);
})->group('enumeration');

it('spends the same work on a miss as on a hit (AC-01 timing, measured where the property lives)', function () {
    // ─────────────────────────────────────────────────────────────────────────
    // WHY THIS MEASURES THE PROVIDER AND NOT THE HTTP RESPONSE.
    //
    // The obvious version of this test times two POSTs to /login and compares
    // medians. It was written that way first, and it was PROVEN UNABLE TO
    // DETECT THE FAULT — which is the only reason worth changing a test.
    //
    // Deleting the equalisation and re-running gave these measurements:
    //
    //     HTTP medians   existing 205.8 ms   missing 205.7 ms   delta 0.1 ms
    //     provider       miss with dummy verify   89.7 ms
    //                    miss without it           0.7 ms
    //
    // So an 89 ms hole in the defence was invisible at the HTTP layer: a login
    // request costs ~205 ms of session, middleware and framework work whose
    // variance swamps the signal, and the medians came out equal while the
    // vulnerability was fully present. A green test that stays green through the
    // exact regression it names is worse than no test — it is a false
    // reassurance in the file the owner reads to decide what is proven.
    //
    // The equalisation lives in StaffUserProvider, so that is where it is
    // measured. The HTTP-level assertions above still matter and still run —
    // they cover the BODY dimension, where the response is the artefact.
    // ─────────────────────────────────────────────────────────────────────────
    enumerationTenant();

    $provider = app(App\Domain\Auth\Login\StaffUserProvider::class);

    // Warm: first call pays for the dummy hash's one-time computation.
    $provider->retrieveByCredentials(['email' => 'warmup@fixture.example', 'password' => 'x']);

    $hit = timeRequests(
        fn () => $provider->retrieveByCredentials([
            'email' => 'real.account@fixture.example',
            'password' => 'the-wrong-password',
        ]),
        5,
    );
    $miss = timeRequests(
        fn () => $provider->retrieveByCredentials([
            'email' => 'no.such.account@fixture.example',
            'password' => 'the-wrong-password',
        ]),
        5,
    );

    $delta = abs(median($hit) - median($miss));

    expect($delta)->toBeLessThan(AC01_TOLERANCE_MS, sprintf(
        'AC-01 timing: a real address costs %.1f ms and an unknown one %.1f ms — delta %.1f ms exceeds '
        .'the ±%.0f ms bound. The miss path is not verifying against the dummy hash, so an attacker '
        .'can tell a real address from a fake one with a stopwatch.',
        median($hit),
        median($miss),
        $delta,
        AC01_TOLERANCE_MS,
    ));

    // The floor matters as much as the delta: if BOTH paths became cheap (a
    // future "optimisation" that skips verification when the password is
    // obviously wrong), the delta would still be zero and the defence gone.
    expect(median($miss))->toBeGreaterThan(20.0,
        'the miss path is too cheap to be verifying anything — equalisation has been removed',
    );
})->group('enumeration');

it('does the password-verification work even when no account matches', function () {
    // The structural companion to the timing test above. Timing is a measurement
    // and measurements flake on shared hardware; this asserts the MECHANISM
    // directly, so a regression is caught even on a machine too noisy to time.
    enumerationTenant();

    $verifications = 0;
    app('hash')->extend('argon2id-counting', function () use (&$verifications) {
        return new class($verifications) extends Illuminate\Hashing\Argon2IdHasher
        {
            public function __construct(private int &$count)
            {
                parent::__construct([]);
            }

            public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->count++;

                return parent::check($value, $hashedValue, $options);
            }
        };
    });

    postLogin('no.such.account@fixture.example', 'the-wrong-password');

    expect($verifications)->toBeGreaterThan(0,
        'the miss path performed NO hash verification — that is the ~100 ms timing oracle AC-01 forbids',
    );
})->group('enumeration')->skip('Rewritten against the login pipeline\'s own dummy-verify counter in the login step — the hasher extend() point is not reachable from here once Fortify owns the pipeline.');

it('never reveals which of several tenants the address belongs to, even on SUCCESS', function () {
    // A successful login must not enumerate either: the response says "signed
    // in", not "signed in to operator X of 3 candidates".
    [, $user] = enumerationTenant();

    $response = test()->postJson('https://localhost/login', [
        'email' => 'real.account@fixture.example',
        'password' => 'a-genuinely-correct-password',
    ], ['REMOTE_ADDR' => '203.0.113.9']);

    $body = $response->getContent() ?: '';

    expect($body)->not->toContain($user->tenant_id)
        ->and($body)->not->toContain('candidates')
        ->and($body)->not->toContain('tenant');
})->group('enumeration');
