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

/**
 * The FLOOR of a set of timings, used instead of the median for the AC-01
 * measurement.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY MINIMUM, AND NOT MEDIAN OR MEAN.
 *
 * Scheduler noise is strictly ADDITIVE: a run can be delayed by a competing
 * process, never accelerated below the work it actually performs. So across
 * repeated samples the minimum converges on the true cost of the work, while the
 * median drifts upward with load — and drifts by DIFFERENT amounts for paths
 * that allocate differently, which is exactly what happened here.
 *
 * CI caught it: medians of 207.5 / 201.7 / 256.3 ms gave a 54.6 ms spread and
 * failed the ±50 ms bound, on a shared runner where argon2id's 64 MiB working
 * set makes timings memory-bandwidth-sensitive. The same code measured tight
 * locally. Loosening the tolerance would have been the wrong fix — it would have
 * hidden a real regression later — so the estimator changed instead, to the one
 * that measures work rather than work-plus-contention.
 *
 * The floor still detects the fault this test exists for: removing a hash
 * removes ~90 ms of unavoidable work, which lowers the floor and no amount of
 * quiet can disguise it.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @param  list<float>  $values
 */
function floorOf(array $values): float
{
    return min($values);
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

it('spends the same work across 0, 1 and N candidate paths (AC-01 timing; owner ruling R-3)', function () {
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
    // variance swamps the signal. A green test that stays green through the
    // exact regression it names is worse than no test.
    //
    // THREE PATHS, NOT TWO (owner ruling R-3). AC-01's literal requirement is
    // existing-vs-non-existing, and S4 closed that while recording a residual:
    // an address in TWO tenants cost two verifications, making "exists in more
    // than one workspace" measurable. The owner ruled that residual closed, so
    // the provider now pads to a fixed verification budget and this test asserts
    // the bound across all three shapes.
    // ─────────────────────────────────────────────────────────────────────────
    [$tenantA] = enumerationTenant();

    // A second tenant holding the SAME address with a DIFFERENT password: the
    // N-candidate shape. (Same address AND same password is the ambiguous case,
    // which is refused for other reasons — see the ruling R-2 test.)
    $tenantB = createTenant();
    asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'two.tenants@fixture.example',
        'password' => Hash::make('a-different-password-entirely'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);
    asTenant($tenantA, fn (): User => User::factory()->create([
        'email' => 'two.tenants@fixture.example',
        'password' => Hash::make('yet-another-password'),
        'password_set_at' => now(),
    ]));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $provider = app(App\Domain\Auth\Login\StaffUserProvider::class);

    // Warm: the first call pays for the dummy hash's one-time computation.
    $provider->retrieveByCredentials(['email' => 'warmup@fixture.example', 'password' => 'x']);

    // Nine samples, and the FLOOR of each set — see floorOf(). Each path is also
    // warmed once so no path pays a first-call cost the others do not.
    $measure = function (string $email) use ($provider): float {
        $provider->retrieveByCredentials(['email' => $email, 'password' => 'warm']);

        return floorOf(timeRequests(
            fn () => $provider->retrieveByCredentials([
                'email' => $email,
                'password' => 'the-wrong-password',
            ]),
            9,
        ));
    };

    $none = $measure('no.such.account@fixture.example');   // 0 candidates
    $one = $measure('real.account@fixture.example');       // 1 candidate
    $many = $measure('two.tenants@fixture.example');       // 2 candidates

    $report = sprintf('0-candidate %.1f ms | 1-candidate %.1f ms | N-candidate %.1f ms', $none, $one, $many);

    expect(abs($none - $one))->toBeLessThan(AC01_TOLERANCE_MS, sprintf(
        'AC-01: existence is timing-distinguishable (%s). The miss path is not doing equivalent work.',
        $report,
    ));

    expect(abs($one - $many))->toBeLessThan(AC01_TOLERANCE_MS, sprintf(
        'Owner ruling R-3: multi-tenant membership is timing-distinguishable (%s). The verification '
        .'budget is not being padded — an attacker who knows an address exists can tell whether it '
        .'exists in more than one workspace.',
        $report,
    ));

    expect(abs($none - $many))->toBeLessThan(AC01_TOLERANCE_MS, sprintf(
        'AC-01/R-3: the 0-candidate and N-candidate paths differ (%s).',
        $report,
    ));

    // The floor matters as much as the deltas: if ALL paths became cheap (a
    // future "optimisation" that skips verification when the password is
    // obviously wrong), every delta would still be zero and the defence gone.
    expect($none)->toBeGreaterThan(20.0,
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

/*
|--------------------------------------------------------------------------
| AC-01's RESET half — unproven since S4, closed in S12 (Doc 02 step 8)
|--------------------------------------------------------------------------
|
| Same two dimensions as the login half, one difference in mechanism: the
| request path's work is token minting and mail queuing, not argon2id, so the
| equalizer is a fixed RESPONSE FLOOR (UniformResponseFloor) rather than
| dummy verifications. The floor assertion below is what keeps it alive — the
| deltas alone would stay green if someone deleted the floor today, because
| the raw per-candidate work is single-digit milliseconds. That is the S4
| both-paths-got-cheap lesson applied at design time.
*/

function postForgot(string $email): array
{
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(2, 250)])
        ->postJson('https://localhost/forgot-password', ['email' => $email]);

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->getContent() ?: '',
    ];
}

it('returns a byte-identical reset-request body across 0, 1 and N candidate addresses (AC-01 reset half)', function () {
    Illuminate\Support\Facades\Mail::fake();
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

    $none = postForgot('no.such.account@fixture.example');
    $many = postForgot('real.account@fixture.example');

    // An INVITED account is a third refusal shape hiding inside the same
    // response (no token minted, per the eligibility rules).
    $tenantC = createTenant();
    asTenant($tenantC, fn (): User => User::factory()->create([
        'email' => 'invited.reset@fixture.example',
        'password' => Hash::make(bin2hex(random_bytes(32))),
        'password_set_at' => null,
    ]));
    activateTenant($tenantC);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $invited = postForgot('invited.reset@fixture.example');

    expect($many['status'])->toBe($none['status'])
        ->and($many['body'])->toBe($none['body'])
        ->and($invited['body'])->toBe($none['body']);

    // R-7 belt-and-braces: no response hints at the multi-tenant escape
    // hatch, which lives in runbook copy only.
    foreach ([$none, $many, $invited] as $response) {
        expect(strtolower($response['body']))->not->toContain('workspace')
            ->and(strtolower($response['body']))->not->toContain('tenant');
    }
})->group('enumeration');

it('answers reset requests at the fixed floor whatever matched — and the floor is provably alive', function () {
    Illuminate\Support\Facades\Mail::fake();
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

    // Handler level, not HTTP, for the same reason the login half measures
    // the provider: request overhead swamps single-digit-ms signals.
    $handler = app(App\Domain\Auth\Password\ResetRequestHandler::class);
    $request = Illuminate\Http\Request::create('https://localhost/forgot-password', 'POST');

    $measure = function (string $email) use ($handler, $request): float {
        $handler->handle($email, $request, Carbon\CarbonImmutable::now()); // warm

        return floorOf(timeRequests(
            fn () => $handler->handle($email, $request, Carbon\CarbonImmutable::now()),
            5,
        ));
    };

    $none = $measure('no.such.account@fixture.example');  // 0 candidates
    $many = $measure('real.account@fixture.example');     // 2 candidates (tenants A and B) — the N shape

    $report = sprintf('0-candidate %.1f ms | N-candidate %.1f ms', $none, $many);

    expect(abs($none - $many))->toBeLessThan(AC01_TOLERANCE_MS, sprintf(
        'AC-01 reset half: existence is timing-distinguishable on /forgot-password (%s).',
        $report,
    ));

    // THE ASSERTION THAT KEEPS THE EQUALIZER ALIVE: every path takes at least
    // the declared floor. Delete UniformResponseFloor's sleep and this goes
    // red even though the deltas above would not.
    $floor = App\Domain\Auth\Password\UniformResponseFloor::FLOOR_MS;
    expect($none)->toBeGreaterThanOrEqual($floor, sprintf('0-candidate path answered below the %s ms floor (%s)', $floor, $report))
        ->and($many)->toBeGreaterThanOrEqual($floor, sprintf('N-candidate path answered below the %s ms floor (%s)', $floor, $report));
})->group('enumeration');
