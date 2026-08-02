<?php

declare(strict_types=1);

use App\Domain\Auth\Password\PasswordChangeOrigin;
use App\Domain\Auth\Password\PasswordChanger;
use App\Domain\Auth\Password\PasswordPolicy;
use App\Domain\Auth\Password\PasswordPolicyViolation;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * FR-15, rule by rule, against the real settings engine (T-10) — plus the
 * max-age middleware over HTTP, because S10's lesson is that a policy proven
 * at a unit boundary while its middleware hangs unattached is a paper control.
 *
 * The breach check is faked EMPTY throughout (no candidate is "breached"
 * unless a test says so) — BreachCheckTest owns that rule's own behaviour.
 */
function policyUser(array $overrides = []): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(array_merge([
        'email' => 'policy@fixture.example',
        'password' => Hash::make('Initial-Correct-Horse-01'),
        'password_set_at' => now(),
        'password_changed_at' => now(),
    ], $overrides)));
    activateTenant($tenant);

    return [$tenant, $user];
}

function enforceFor(object $tenant, User $user, string $candidate): void
{
    asTenant($tenant, fn () => app(PasswordPolicy::class)->enforce($user, $candidate));
}

/**
 * @return array<string, list<string>> rule key => messages, or [] if accepted
 */
function policyErrors(object $tenant, User $user, string $candidate): array
{
    try {
        enforceFor($tenant, $user, $candidate);
    } catch (PasswordPolicyViolation $violation) {
        return $violation->errors;
    }

    return [];
}

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 200)]);
});

it('refuses below the default 12 minimum and accepts at it, naming the rule', function (): void {
    [$tenant, $user] = policyUser();

    expect(policyErrors($tenant, $user, 'elevenchars'))->toHaveKey('length')   // 11
        ->and(policyErrors($tenant, $user, 'twelve-chars'))->toBe([]);         // 12, positive control
});

it('follows the tenant\'s own min_length setting (T-10)', function (): void {
    [$tenant, $user] = policyUser();

    asTenant($tenant, fn () => app(SettingsRepository::class)->set('auth.password.min_length', 14));

    expect(policyErrors($tenant, $user, 'thirteen-char'))->toHaveKey('length')  // 13
        ->and(policyErrors($tenant, $user, 'fourteen-chars'))->toBe([]);        // 14
});

it('cannot be weakened below BR-05\'s platform floor of 10 — refused at the registry, not by discipline', function (): void {
    [$tenant, $user] = policyUser();

    asTenant($tenant, function (): void {
        // The floor lives in the registry's validation, where no operator
        // surface can route around it.
        expect(fn () => app(SettingsRepository::class)->set('auth.password.min_length', 9))
            ->toThrow(Illuminate\Validation\ValidationException::class);

        // Positive control: the floor itself is writable.
        app(SettingsRepository::class)->set('auth.password.min_length', 10);
    });

    expect(policyErrors($tenant, $user, 'ten-chars-'))->toBe([]);
});

it('ignores composition by default (length over composition) and enforces 3-of-4 classes when toggled on', function (): void {
    [$tenant, $user] = policyUser();

    // Default OFF: a long all-lowercase passphrase is exactly what FR-15's
    // "length over composition" stance wants to allow.
    expect(policyErrors($tenant, $user, 'correcthorsebatterystaple'))->toBe([]);

    asTenant($tenant, fn () => app(SettingsRepository::class)->set('auth.password.require_classes', true));

    expect(policyErrors($tenant, $user, 'correcthorsebatterystaple'))->toHaveKey('classes')  // 1 class
        ->and(policyErrors($tenant, $user, 'correcthorse1234567890'))->toHaveKey('classes')  // 2 classes
        ->and(policyErrors($tenant, $user, 'Correcthorse1234567890'))->toBe([]);             // 3 classes
});

it('refuses reuse of the current password and each of the last N, and forgets beyond the depth', function (): void {
    [$tenant, $user] = policyUser();

    // Shrink the history depth so this test costs 3 argon2id chains, not 6.
    asTenant($tenant, fn () => app(SettingsRepository::class)->set('auth.password.history', 2));

    $request = Request::create('https://localhost/test', 'POST');
    $passwords = ['Rotation-One-Password', 'Rotation-Two-Password', 'Rotation-Three-Password'];

    asTenant($tenant, function () use ($user, $passwords, $request): void {
        foreach ($passwords as $password) {
            app(PasswordChanger::class)->apply($user, $password, PasswordChangeOrigin::SelfChange, CarbonImmutable::now(), $request);
        }
    });

    // Current password: always refused, whatever the depth.
    expect(policyErrors($tenant, $user, 'Rotation-Three-Password'))->toHaveKey('reuse')
        // The last 2 previous: remembered.
        ->and(policyErrors($tenant, $user, 'Rotation-Two-Password'))->toHaveKey('reuse')
        ->and(policyErrors($tenant, $user, 'Rotation-One-Password'))->toHaveKey('reuse')
        // Beyond the depth: the ORIGINAL password fell off the window of
        // current + last 2 — accepted, which is what proves N is N rather
        // than "everything forever".
        ->and(policyErrors($tenant, $user, 'Initial-Correct-Horse-01'))->toBe([]);
});

it('writes a history row per change with the PRE-change hash (the migration\'s contract)', function (): void {
    [$tenant, $user] = policyUser();

    $request = Request::create('https://localhost/test', 'POST');

    asTenant($tenant, function () use ($user, $request): void {
        app(PasswordChanger::class)->apply($user, 'Rotation-One-Password', PasswordChangeOrigin::SelfChange, CarbonImmutable::now(), $request);

        $rows = DB::select('SELECT password_hash FROM user_password_history WHERE user_id = ?', [$user->identifier()]);

        expect($rows)->toHaveCount(1)
            // The archived hash verifies the OLD password, not the new one —
            // archiving the new hash would make "reuse of the previous" pass.
            ->and(Hash::check('Initial-Correct-Horse-01', $rows[0]->password_hash))->toBeTrue()
            ->and(Hash::check('Rotation-One-Password', $rows[0]->password_hash))->toBeFalse();
    });
});

it('mutates nothing when policy refuses — no hash change, no history row, no stamp', function (): void {
    [$tenant, $user] = policyUser();

    $request = Request::create('https://localhost/test', 'POST');
    $hashBefore = $user->getRawOriginal('password');
    $changedBefore = $user->getAttribute('password_changed_at');

    asTenant($tenant, function () use ($user, $request): void {
        expect(fn () => app(PasswordChanger::class)->apply($user, 'short', PasswordChangeOrigin::SelfChange, CarbonImmutable::now(), $request))
            ->toThrow(PasswordPolicyViolation::class);

        expect(DB::select('SELECT count(*) AS n FROM user_password_history WHERE user_id = ?', [$user->identifier()])[0]->n)->toEqual(0);
    });

    $fresh = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier()));
    expect($fresh->getRawOriginal('password'))->toBe($hashBefore)
        ->and($fresh->getAttribute('password_changed_at'))->toEqual($changedBefore);
});

/*
|--------------------------------------------------------------------------
| FR-15 max age — through the real middleware on both staff surfaces
|--------------------------------------------------------------------------
*/

function agedUser(int $daysAgo, int $maxAgeSetting): array
{
    [$tenant, $user] = policyUser([
        'password_changed_at' => CarbonImmutable::now()->subDays($daysAgo),
    ]);

    if ($maxAgeSetting > 0) {
        asTenant($tenant, fn () => app(SettingsRepository::class)->set('auth.password.max_age_days', $maxAgeSetting));
    }

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [$tenant, $user];
}

it('never fires at the spec default of 0, however old the password', function (): void {
    [, $user] = agedUser(daysAgo: 3650, maxAgeSetting: 0);

    test()->actingAs($user)->getJson('/api/staff/context')->assertOk();
});

it('confines an expired password to the change surface, two-sided at the boundary', function (): void {
    // Day 29 of 30: free (the near side).
    [, $fresh] = agedUser(daysAgo: 29, maxAgeSetting: 30);
    test()->actingAs($fresh)->getJson('/api/staff/context')->assertOk();

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    // Day 31 of 30: confined — one problem-details code the client routes on.
    [, $stale] = agedUser(daysAgo: 31, maxAgeSetting: 30);
    $refused = test()->actingAs($stale)->getJson('/api/staff/context');
    $refused->assertStatus(403);
    expect($refused->json('code'))->toBe('password_expired');

    // The three allowed doors stay open — here, the one that FIXES the state:
    // changing the password restores full access in the same session.
    test()->actingAs($stale)
        ->putJson('/api/staff/security/password', [
            'current_password' => 'Initial-Correct-Horse-01',
            'password' => 'A-Fresh-Replacement-Password',
            'password_confirmation' => 'A-Fresh-Replacement-Password',
        ])
        ->assertOk();

    test()->actingAs($stale)->getJson('/api/staff/context')->assertOk();
});
