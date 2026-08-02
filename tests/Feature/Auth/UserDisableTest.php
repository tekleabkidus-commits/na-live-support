<?php

declare(strict_types=1);

use App\Domain\Auth\Disable\UserDisabler;
use App\Domain\Auth\Tokens\MintedToken;
use App\Domain\Auth\Tokens\TokenMinter;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * BR-04: "disabling a user revokes all their sessions and tokens atomically"
 * — with the meaning fixed by the step-9 owner ruling and restated here:
 *
 *   1. DB atomicity: disabled_at + every live token's revoked_at, one
 *      transaction, proven ALL-OR-NOTHING by an injected mid-transaction
 *      failure (not by reading the code).
 *   2. Immediate Redis session revocation after commit (T-04's revoker).
 *   3. Per-request backstops on BOTH surfaces, each independently proven:
 *      staff rehydration re-reads disabled state (StaffUserProvider), and the
 *      bearer door re-reads it (resolve_api_token's user_disabled) — so a
 *      session key or token row that survives a partial failure still
 *      authenticates nothing.
 */
function disableFixture(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'doomed@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function mintFor(mixed $tenant, User $user, string $name = 'live token'): MintedToken
{
    $minted = asTenant($tenant, fn (): MintedToken => app(TokenMinter::class)->mint(
        owner: $user,
        name: $name,
        abilities: ['settings.view'],
        expiryDays: 90,
        now: CarbonImmutable::now(),
    ));

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return $minted;
}

function plantSessionRow(string $tenantId, User $user, string $sessionId): void
{
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');

    $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId), 3600, 'planted-session');
    app(SessionRegistry::class)->register($tenantId, $user->identifier(), $sessionId, CarbonImmutable::now());
}

it('disables a user: tokens revoked, sessions gone, everything audited (BR-04)', function (): void {
    [$tenant, $user] = disableFixture();

    $tokenA = mintFor($tenant, $user, 'token A');
    $tokenB = mintFor($tenant, $user, 'token B');
    $alreadyRevoked = mintFor($tenant, $user, 'already revoked');
    asTenant($tenant, fn () => app(App\Domain\Auth\Tokens\TokenRevoker::class)
        ->revoke($alreadyRevoked->token, 'self_revoked', CarbonImmutable::now()->subHour(), $user->identifier()));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    plantSessionRow($tenant->id, $user, 'doomed-session');

    // POSITIVE CONTROLS: before the disable, the token authenticates and the
    // session key exists — the deaths below are caused, not preexisting.
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', ['Authorization' => 'Bearer '.$tokenA->plaintext])->assertOk();

    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenant->id, 'doomed-session')))->toBeTrue();

    $revokedCount = asTenant($tenant, fn (): int => app(UserDisabler::class)
        ->disable($user, CarbonImmutable::now()));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // The two LIVE tokens died; the already-revoked one kept its original
    // timestamp (revocation history is not rewritten).
    expect($revokedCount)->toBe(2);

    $rows = asTenant($tenant, fn () => PersonalAccessToken::query()
        ->whereIn('id', [$tokenA->token->identifier(), $tokenB->token->identifier(), $alreadyRevoked->token->identifier()])
        ->get()
        ->keyBy('id'));

    expect($rows[$tokenA->token->identifier()]->revoked_at)->not->toBeNull()
        ->and($rows[$tokenB->token->identifier()]->revoked_at)->not->toBeNull()
        // The already-revoked token keeps its ORIGINAL hour-old timestamp —
        // the disable does not rewrite revocation history.
        ->and($rows[$alreadyRevoked->token->identifier()]->revoked_at->toIso8601String())
        ->toBe($alreadyRevoked->token->revoked_at->toIso8601String());

    // Sessions: the Redis key is deleted NOW, not at next request.
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenant->id, 'doomed-session')))->toBeFalse();

    // The bearer surface refuses with the ruling's mute invalid_token.
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', ['Authorization' => 'Bearer '.$tokenA->plaintext])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_token');

    // The trail: one auth.user.disabled with the count, plus one
    // auth.token.revoked PER live token, reason user_disabled (BR-04 as a bulk
    // application of the ordinary revocation rule).
    $disabledAudit = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.user.disabled')->where('entity_id', $user->identifier())->first());
    $revokedAudits = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.token.revoked')
        ->get()
        ->filter(fn (object $row): bool => json_decode((string) $row->payload, true)['reason'] === 'user_disabled'));

    expect($disabledAudit)->not->toBeNull()
        ->and(json_decode((string) $disabledAudit->payload, true)['revoked_token_count'])->toBe(2)
        ->and($revokedAudits)->toHaveCount(2);
});

it('is ALL-OR-NOTHING: an injected failure between the user write and the token writes rolls both back', function (): void {
    [$tenant, $user] = disableFixture();
    $minted = mintFor($tenant, $user);
    plantSessionRow($tenant->id, $user, 'survivor-session');

    // The injection: the first UPDATE touching the token table throws — i.e.
    // the transaction dies AFTER disabled_at was written, BEFORE revocation
    // lands. If BR-04's "one transaction" is real, NEITHER write survives.
    $armed = true;
    DB::listen(function (object $query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'update "personal_access_tokens"')) {
            $armed = false;

            throw new RuntimeException('injected mid-transaction failure (BR-04 atomicity proof)');
        }
    });

    $threw = false;

    try {
        asTenant($tenant, fn (): int => app(UserDisabler::class)->disable($user, CarbonImmutable::now()));
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'injected');
    } finally {
        $armed = false;
        app(TenantContext::class)->clear();
        app(RlsSession::class)->clear();
    }

    expect($threw)->toBeTrue('the injection must actually fire — otherwise this proves nothing');

    // NOTHING persisted: not the disable, not the revocation, not the audit.
    $freshUser = asTenant($tenant, fn (): ?User => User::query()->find($user->identifier()));
    $freshToken = asTenant($tenant, fn (): ?PersonalAccessToken => PersonalAccessToken::query()->find($minted->token->identifier()));
    $audits = asTenant($tenant, fn (): int => DB::table('audit_log')
        ->whereIn('action_key', ['auth.user.disabled', 'auth.token.revoked'])->count());

    expect($freshUser->isDisabled())->toBeFalse('disabled_at must roll back with the failed token write')
        ->and($freshToken->revoked_at)->toBeNull()
        ->and($audits)->toBe(0);

    // And the sessions were NOT revoked — session revocation runs after
    // commit, never inside the transaction, so a rolled-back disable costs
    // nobody their session.
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenant->id, 'survivor-session')))->toBeTrue();

    // The token still WORKS — the user is not disabled, nothing was revoked.
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', ['Authorization' => 'Bearer '.$minted->plaintext])->assertOk();
});

it('backstop 1 — the bearer door refuses a disabled owner\'s UNREVOKED token (the missed-cascade case)', function (): void {
    [$tenant, $user] = disableFixture();
    $minted = mintFor($tenant, $user);

    // POSITIVE CONTROL.
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', ['Authorization' => 'Bearer '.$minted->plaintext])->assertOk();

    // Disable WITHOUT the disabler — simulating a revocation cascade that
    // never ran. The token row is still live; only the owner is dead.
    asTenant($tenant, fn () => $user->forceFill(['disabled_at' => CarbonImmutable::now()])->save());
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    expect(asTenant($tenant, fn () => $minted->token->fresh()->revoked_at))->toBeNull();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // The door's user_disabled flag, alone, refuses it — as invalid_token,
    // indistinguishable from garbage (step-9 ruling).
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', ['Authorization' => 'Bearer '.$minted->plaintext])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_token');
});

it('backstop 2 — staff rehydration refuses a disabled user whose session key survived', function (): void {
    [$tenant, $user] = disableFixture();

    $provider = auth('staff')->getProvider();

    // The rehydration read needs the tenant hint, exactly as a real request
    // provides it (StaffUserProvider's docblock).
    $request = Illuminate\Http\Request::create('/api/staff/context', 'GET');
    app()->instance('request', $request);
    app(App\Support\Auth\Sessions\TenantHint::class)->useForThisRequest($tenant->id);

    // POSITIVE CONTROL: enabled → rehydrates.
    $rehydrated = $provider->retrieveById($user->identifier());
    expect($rehydrated)->not->toBeNull('an enabled user must rehydrate — otherwise the refusal below is vacuous');

    // Disabled — nothing else changed, the "session" (this resolver call) is
    // still notionally alive — and rehydration now returns nobody.
    asTenant($tenant, fn () => $user->forceFill(['disabled_at' => CarbonImmutable::now()])->save());
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    expect($provider->retrieveById($user->identifier()))->toBeNull(
        'BR-04 backstop: a surviving session key must rehydrate NOTHING for a disabled user',
    );

    // Same boundary, same guarantee for service accounts (FR-19's non-login
    // belt): a planted session can never produce a service-account principal.
    $account = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'bot@service-accounts.invalid',
        'service_account_at' => CarbonImmutable::now(),
    ]));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    expect($provider->retrieveById($account->identifier()))->toBeNull();
});
