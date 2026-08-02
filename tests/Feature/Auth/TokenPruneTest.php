<?php

declare(strict_types=1);

use App\Domain\Auth\Tokens\MintedToken;
use App\Domain\Auth\Tokens\TokenMinter;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FS-05: nightly prune of EXPIRED tokens — and only expired tokens — across
 * every tenant, under the platform role (the app role cannot DELETE on this
 * table; GrantPhysicsTest sweeps that grant automatically, and a local
 * has_table_privilege control repeats it here so this file stands alone).
 *
 * Committed fixtures: the prune runs on the pgsql_platform connection, which
 * cannot see the harness transaction — same shape as the outbox-relay suites,
 * with cleanupTenants() in a finally.
 */
function pruneMint(mixed $tenant, User $user, string $name, ?CarbonImmutable $backdateExpiry = null): MintedToken
{
    $minted = asTenant($tenant, function () use ($user, $name, $backdateExpiry): MintedToken {
        $minted = app(TokenMinter::class)->mint(
            owner: $user,
            name: $name,
            abilities: ['settings.view'],
            expiryDays: $backdateExpiry === null ? null : 30,
            now: CarbonImmutable::now(),
        );

        if ($backdateExpiry !== null) {
            $minted->token->forceFill(['expires_at' => $backdateExpiry])->save();
        }

        return $minted;
    });

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return $minted;
}

it('prunes expired tokens across tenants and touches nothing else (FS-05)', function (): void {
    $tenantA = createTenant();
    $userA = asTenant($tenantA, fn (): User => User::factory()->create([
        'email' => 'prune.a@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantA);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'prune.b@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $past = CarbonImmutable::now()->subDay();

    $expiredA = pruneMint($tenantA, $userA, 'expired A', $past);
    $liveA = pruneMint($tenantA, $userA, 'live A');
    $revokedUnexpiredA = pruneMint($tenantA, $userA, 'revoked, no expiry');
    asTenant($tenantA, fn () => app(App\Domain\Auth\Tokens\TokenRevoker::class)
        ->revoke($revokedUnexpiredA->token, 'self_revoked', CarbonImmutable::now(), $userA->identifier()));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    $expiredB = pruneMint($tenantB, $userB, 'expired B', $past);

    commitFixtures();

    try {
        // The app role's missing DELETE is what forces the platform role here
        // — asserted as a catalog fact so a future GRANT fails this file too.
        $canDelete = DB::select("SELECT has_table_privilege('app', 'personal_access_tokens', 'DELETE') AS can");
        expect((bool) $canDelete[0]->can)->toBeFalse('the app role must never hold DELETE on the token table');

        asPlatformWorker(function (): void {
            test()->artisan('tokens:prune')->assertSuccessful();
        });

        $remaining = DB::connection('pgsql_platform')
            ->table('personal_access_tokens')
            ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])
            ->pluck('name')
            ->all();

        sort($remaining);

        // Both tenants' EXPIRED rows are gone in one pass; the live one and
        // the revoked-but-unexpired one (the audit-visible state FR-18's UI
        // shows) survive.
        expect($remaining)->toBe(['live A', 'revoked, no expiry'])
            ->and($remaining)->not->toContain('expired A')
            ->and($remaining)->not->toContain('expired B');

        // Unused but named so the intent reads: these ids are the pruned ones.
        expect($expiredA->token->identifier())->not->toBe($expiredB->token->identifier());
    } finally {
        cleanupTenants([$tenantA->id, $tenantB->id]);
    }
});

it('scans on the documented partial index and runs nightly (FS-05 "index plan documented")', function (): void {
    $index = DB::select(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'personal_access_tokens' AND indexname = 'personal_access_tokens_expires_at_index'",
    );

    expect($index)->toHaveCount(1)
        // Partial: never-expiring tokens cost the index nothing.
        ->and($index[0]->indexdef)->toContain('WHERE (expires_at IS NOT NULL)');

    $scheduled = collect(app(Schedule::class)->events())
        ->filter(fn (object $event): bool => str_contains((string) $event->command, 'tokens:prune'));

    expect($scheduled)->toHaveCount(1, 'FS-05 says NIGHTLY — the command must be on the schedule')
        ->and($scheduled->first()->expression)->toBe('0 3 * * *');
});
