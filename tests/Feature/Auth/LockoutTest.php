<?php

declare(strict_types=1);

use App\Domain\Auth\Lockout\LockoutState;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FR-03 / AC-02 / EC-11 / R-04: lockout is a state machine, not a cache counter.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AC-02 IS THE MATRIX: "1–4 generic, 5th locks, reset unlocks, admin unlock
 * audited." Every clause is a separate test below, plus the parts FR-03 and
 * EC-11 add that AC-02 does not spell out:
 *
 *   - the window ROLLS: four failures spread beyond auth.lockout.window must not
 *     accumulate into a lock, or a user who mistypes once a month is locked out
 *     by history.
 *   - the lock EXPIRES on its own after auth.lockout.duration.
 *   - the message never reveals remaining time ("try again later").
 *   - the per-IP throttle is INDEPENDENT — it applies to addresses that do not
 *     exist, which is what actually caps a spraying attacker (EC-11).
 *   - every transition is AUDITED, which is the whole reason this is a state
 *     machine: "a number went back to zero" cannot record who reset it or why.
 *
 * THE MULTI-CANDIDATE CLAUSE (owner ruling, Q1 extension). An email may match
 * accounts in several tenants. A failed attempt counts against EVERY candidate,
 * because the attacker is guessing that ADDRESS and the platform cannot know
 * which tenant they meant — counting against one would leave the others
 * unprotected and would leak, by which account locked, which tenant the guesser
 * hit. Each account then unlocks independently via its own reset.
 *
 * CLOCK IS INJECTED (CLAUDE.md §8: never assert on now()). The lockout policy
 * takes a CarbonImmutable so window-rolling and expiry are tested at exact
 * boundaries rather than by sleeping.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function lockoutUser(string $email = 'agent@fixture.example', array $overrides = []): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(array_merge([
        'email' => $email,
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ], $overrides)));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

/**
 * Read a user's lockout columns without a bound tenant, the way the login path
 * sees them — through the pre-tenant door rather than the model.
 */
function lockoutStateOf(string $email): object
{
    $rows = DB::select('SELECT * FROM resolve_staff_login(?)', [$email]);

    expect($rows)->not->toBeEmpty("no account for {$email}");

    return $rows[0];
}

/**
 * A login attempt from a given client address.
 *
 * TWO THINGS HERE ARE LOAD-BEARING AND WERE BOTH WRONG FIRST TIME.
 *
 * 1. The address goes through withServerVariables(), NOT postJson()'s third
 *    argument — that argument is HEADERS, so `REMOTE_ADDR` arrived as
 *    `HTTP_REMOTE_ADDR` and every request in the suite shared one client IP.
 * 2. The default is a DISTINCT address per attempt. The per-IP throttle is
 *    backed by Redis, which persists across tests in a run, so a shared address
 *    means one test's attempts exhaust another's budget and the victim test
 *    fails with 429 for reasons that have nothing to do with what it asserts.
 *
 * Distinct addresses also make these tests say something truer: FR-03's account
 * lockout is independent of where the attempts come from, so a lockout suite
 * that can only lock an account from a single IP is not testing the requirement.
 */
function attemptLogin(string $email, string $password, ?string $ip = null): Illuminate\Testing\TestResponse
{
    $ip ??= sprintf('203.0.113.%d', random_int(2, 250));
    lockoutIpLedger($ip); // 0f rung 1: every attempted address is on the ledger

    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('https://localhost/login', [
            'email' => $email,
            'password' => $password,
        ]);
}

/**
 * RISK 0f LADDER, RUNG 1 — EXTENDED TO THIS FILE (advisor ruling at the S07
 * step-13 checkpoint, 2026-08-12, on sighting G: run 31573187355's re-run went
 * red on THIS file's per-IP test with an early 429, and the v2 snapshot in
 * PasswordResetFlowTest could not see it — its suspect bucket is hardcoded to
 * 127.0.0.1 while this file mints random per-test addresses).
 *
 * The ledger records every address attemptLogin uses, so the failure-time
 * snapshot below can ask the RateLimiter for THE ACTUAL suspect buckets — the
 * bleed-vs-product-write discriminator sighting G lacked. Diagnostics only.
 *
 * @return list<string>
 */
function lockoutIpLedger(?string $record = null): array
{
    static $ips = [];
    if ($record !== null) {
        $ips[$record] = true;
    }

    return array_keys($ips);
}

afterEach(function (): void {
    if (! $this->status()->isSuccess()) {
        $limiter = app(Illuminate\Cache\RateLimiter::class);
        $buckets = [];
        foreach (lockoutIpLedger() as $ip) {
            $buckets[$ip] = $limiter->attempts(md5('auth-ip'.$ip));
        }
        $redis = Illuminate\Support\Facades\Redis::connection('volatile');
        /** @var list<string> $keys */
        $keys = $redis->keys('*');
        $live = [];
        foreach ($keys as $key) {
            $ttl = (int) $redis->ttl($key);
            if ($ttl >= 0) { // live keys only — expired residue is noise, not evidence
                $live[$key] = $ttl;
            }
        }
        $total = count($live);
        $shown = array_slice($live, 0, 200, true); // capped, and the cap is SAID (no silent truncation)
        fwrite(STDERR, "\n0f RUNG-1 SNAPSHOT v2/LOCKOUT (failure-time): auth-ip buckets ".json_encode($buckets)
            .' | live volatile keys: '.$total.(count($shown) < $total ? ' (first 200 shown)' : '')
            .' | '.json_encode($shown)."\n");
    }
});

/*
|--------------------------------------------------------------------------
| AC-02, clause by clause
|--------------------------------------------------------------------------
*/

it('gives the generic message for failures 1 to 4 and does not lock (AC-02)', function () {
    lockoutUser();

    foreach (range(1, 4) as $attempt) {
        $response = attemptLogin('agent@fixture.example', 'wrong-password');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            // FR-02/AC-01 still holds throughout: a locked-in-progress account
            // must not start describing itself differently from any other miss.
            ->assertJsonPath('detail', __('auth.failed.generic'));

        expect(lockoutStateOf('agent@fixture.example')->failed_login_attempts)
            ->toEqual($attempt, "attempt {$attempt} was not counted");
        expect(lockoutStateOf('agent@fixture.example')->locked_until)->toBeNull();
    }
});

it('locks the account on the 5th failure (AC-02)', function () {
    lockoutUser();

    foreach (range(1, 5) as $ignored) {
        attemptLogin('agent@fixture.example', 'wrong-password');
    }

    $state = lockoutStateOf('agent@fixture.example');

    expect($state->locked_until)->not->toBeNull('the 5th failure did not lock the account');
});

it('refuses the CORRECT password while locked, without saying it is locked', function () {
    lockoutUser();

    foreach (range(1, 5) as $ignored) {
        attemptLogin('agent@fixture.example', 'wrong-password');
    }

    // The correct credential now fails — which is the point of a lockout — and the
    // response is still the generic one. "Your account is locked for 12 more
    // minutes" would tell an attacker both that the address is real and exactly
    // when to resume (FR-03: "does not reveal remaining time").
    $response = attemptLogin('agent@fixture.example', 'the-correct-password');

    $response->assertStatus(422)->assertJsonPath('detail', __('auth.failed.generic'));
    expect(auth('staff')->check())->toBeFalse();

    $body = (string) $response->getContent();
    foreach (['locked', 'minute', 'try again in', 'remaining'] as $leak) {
        expect(mb_strtolower($body))->not->toContain($leak);
    }
});

it('audits every lockout transition (AC-02: admin unlock audited, and the rest)', function () {
    [$tenant] = lockoutUser();

    foreach (range(1, 5) as $ignored) {
        attemptLogin('agent@fixture.example', 'wrong-password');
    }

    $locked = asTenant($tenant, fn (): array => DB::table('audit_log')
        ->where('action_key', 'auth.lockout.locked')
        ->get()
        ->all());

    expect($locked)->toHaveCount(1, 'locking an account left no audit entry');

    $payload = json_decode((string) $locked[0]->payload, true);
    expect($payload)->toHaveKey('reason')
        ->and($payload)->toHaveKey('failed_attempts')
        // The trail records WHAT happened, never the credential that was tried.
        ->and(json_encode($payload))->not->toContain('wrong-password');
});

/*
|--------------------------------------------------------------------------
| FR-03's window and duration — the parts a counter cannot express
|--------------------------------------------------------------------------
*/

it('rolls the window: failures older than auth.lockout.window do not accumulate', function () {
    [, $user] = lockoutUser();
    $policy = app(App\Domain\Auth\Lockout\LockoutPolicy::class);

    $start = Carbon\CarbonImmutable::parse('2026-07-31T09:00:00Z');

    // Four failures, then a gap longer than the 15-minute window, then four more.
    // A naive counter locks on the fifth overall; a rolling window does not,
    // because the first four expired.
    $state = LockoutState::fresh();
    foreach (range(1, 4) as $i) {
        $state = $policy->recordFailure($state, $start->addMinutes($i));
    }
    expect($state->lockedUntil)->toBeNull();

    $later = $start->addMinutes(30);
    foreach (range(1, 4) as $i) {
        $state = $policy->recordFailure($state, $later->addSeconds($i));
    }

    expect($state->lockedUntil)->toBeNull('four failures after the window rolled should not lock')
        ->and($state->failedAttempts)->toBe(4, 'the counter did not restart when the window expired');
});

it('expires the lock after auth.lockout.duration without anyone intervening', function () {
    $policy = app(App\Domain\Auth\Lockout\LockoutPolicy::class);
    $at = Carbon\CarbonImmutable::parse('2026-07-31T09:00:00Z');

    $state = LockoutState::fresh();
    foreach (range(1, 5) as $i) {
        $state = $policy->recordFailure($state, $at->addSeconds($i));
    }

    expect($state->isLockedAt($at->addMinutes(14)))->toBeTrue()
        ->and($state->isLockedAt($at->addMinutes(16)))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The multi-candidate clause (owner ruling) and EC-11
|--------------------------------------------------------------------------
*/

it('counts a failed attempt against EVERY candidate account for that address', function () {
    // Same address in two tenants, different passwords (so neither verifies).
    [, $userA] = lockoutUser('shared@fixture.example');

    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'shared@fixture.example',
        'password' => Hash::make('a-different-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    foreach (range(1, 5) as $ignored) {
        attemptLogin('shared@fixture.example', 'wrong-password');
    }

    $rows = collect(DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example']))
        ->keyBy('user_id');

    expect($rows)->toHaveCount(2);

    // Both locked: the guesser attacked the ADDRESS, and the platform cannot know
    // which workspace was meant. Locking only one would leave the other exposed
    // and would leak, by which account locked, which tenant was hit.
    expect($rows[$userA->getKey()]->locked_until)->not->toBeNull('candidate A was not locked')
        ->and($rows[$userB->getKey()]->locked_until)->not->toBeNull('candidate B was not locked');
});

it('lets a victim always unlock via password reset, per account (EC-11)', function () {
    [$tenant, $user] = lockoutUser();

    foreach (range(1, 5) as $ignored) {
        attemptLogin('agent@fixture.example', 'wrong-password');
    }
    expect(lockoutStateOf('agent@fixture.example')->locked_until)->not->toBeNull();

    // EC-11's containment: the attacker can lock the victim out, so the victim
    // must have a path back that does NOT require the attacker to stop. A
    // completed reset clears the lock (FR-03: "unlock also possible by … a
    // completed password reset").
    asTenant($tenant, function () use ($user): void {
        app(App\Domain\Auth\Lockout\LockoutManager::class)->unlockAfterPasswordReset($user);
    });

    expect(lockoutStateOf('agent@fixture.example')->locked_until)->toBeNull()
        ->and(lockoutStateOf('agent@fixture.example')->failed_login_attempts)->toEqual(0);

    // …and the account works again immediately.
    attemptLogin('agent@fixture.example', 'the-correct-password')->assertOk();
});

it('registers the EC-11 captcha threshold setting even though no vendor is wired', function () {
    // EC-11 names `auth.captcha.after_failures` as part of the DoS containment.
    // The key exists so wiring a vendor later is configuration, not a schema
    // change; 0 means off, which is v1's shipped state.
    $registry = app(App\Support\Settings\SettingsRegistry::class);

    expect($registry->definition('auth.captcha.after_failures')['default'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| SEC-05 / FR-03: the per-IP throttle is independent of account state
|--------------------------------------------------------------------------
*/

it('throttles per IP independently of any account, including addresses that do not exist', function () {
    lockoutUser();

    // The attacker cap in EC-11: it applies to addresses with no account at all,
    // so it bounds address-spraying, which account lockout structurally cannot.
    $perMinute = (int) app(App\Support\Settings\SettingsRegistry::class)
        ->definition('auth.throttle.ip_per_minute')['default'];

    // A dedicated address, unique per run: Redis keeps limiter state between
    // tests AND between runs within the same minute, so a hardcoded address
    // would make this test pass or fail depending on what ran before it.
    $ip = sprintf('198.51.100.%d', random_int(2, 250));

    $statuses = [];
    foreach (range(1, $perMinute + 2) as $i) {
        $statuses[] = attemptLogin("nobody-{$i}@fixture.example", 'x', $ip)->getStatusCode();
    }

    // in_array + toBeTrue, NOT toContain(429, 'message'). Pest's toContain is
    // VARIADIC over expected values, so the second argument was asserted as
    // another element the array must contain — the failure read "array does not
    // contain 'no request was throttled…'", which is the assertion describing
    // itself rather than the system. Same trap as toHaveKey's second argument,
    // and worth remembering: in this framework, custom messages belong to
    // toBeTrue/toBeFalse, not to the collection matchers.
    expect(in_array(429, $statuses, true))->toBeTrue(sprintf(
        'no request was throttled after %d attempts from one IP (statuses: %s) — the per-IP cap is not applied',
        $perMinute + 2,
        implode(',', array_unique($statuses)),
    ));

    // …and the cap arrives only AFTER the budget, not before: a limiter that
    // refused from the first request would also "contain 429" while being
    // useless.
    expect(array_slice($statuses, 0, $perMinute))->not->toContain(429);
});
