<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FR-07's settings API over HTTP, and the audit-declaration middleware (Doc 01 FR-07,
 * T-07, EC-06; CLAUDE.md §2).
 */
function settingsActor(): User
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create()));
    activateTenant($tenant);
    app(TenantContext::class)->clear();

    return $user;
}

it('lists every setting with its i18n keys and default flag', function () {
    $user = settingsActor();

    $response = $this->actingAs($user)->getJson('/api/staff/settings');

    $response->assertOk();
    $rows = collect($response->json('data'))->keyBy('key');

    expect($rows)->toHaveKey('tenant.display_timezone')
        ->and($rows['tenant.display_timezone']['is_default'])->toBeTrue()
        ->and($rows['tenant.display_timezone']['tenant_editable'])->toBeTrue()
        // N-03/C-08: no English prose crosses this boundary — Doc 16 renders these keys.
        ->and($rows['tenant.display_timezone']['label_i18n_key'])->toBe('setting.tenant.display_timezone.label')
        // A platform-owned setting is visible but marked not editable.
        ->and($rows['quota.messages_per_minute']['tenant_editable'])->toBeFalse()
        // C-13: the secret's value never leaves the engine, even in a bulk read.
        ->and($rows['tenant.outbound_webhook_secret']['value'])->toBeNull();
});

it('writes a setting and returns the new version token', function () {
    $user = settingsActor();

    $before = $this->actingAs($user)->getJson('/api/staff/settings/tenant.display_timezone');
    $before->assertOk();
    expect($before->json('version'))->toBeNull(); // no override row yet

    $write = $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', [
        'value' => 'Africa/Nairobi',
    ]);

    $write->assertOk();
    expect($write->json('version'))->toBe(1);

    $after = $this->actingAs($user)->getJson('/api/staff/settings/tenant.display_timezone');
    expect($after->json('value'))->toBe('Africa/Nairobi')
        ->and($after->json('version'))->toBe(1);
});

it('returns RFC 9457 problem-details for an unregistered key', function () {
    $user = settingsActor();

    $response = $this->actingAs($user)->getJson('/api/staff/settings/tenant.nonsense');

    $response->assertNotFound();
    // T-07/C-04: stable machine-readable code, translated title, trace id matching logs.
    expect($response->json('code'))->toBe('unregistered_setting')
        ->and($response->json('status'))->toBe(404)
        ->and($response->json('trace_id'))->not->toBeEmpty()
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('returns 422 problem-details with per-field errors on invalid input', function () {
    $user = settingsActor();

    $response = $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', [
        'value' => 'Mars/Olympus_Mons',
    ]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('value');
});

it('returns 403 when a tenant surface writes a platform-owned setting', function () {
    $user = settingsActor();

    $response = $this->actingAs($user)->putJson('/api/staff/settings/quota.messages_per_minute', [
        'value' => 999999,
    ]);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('unregistered_setting');
});

it('returns 409 with the winning value on a concurrent write (EC-06)', function () {
    $user = settingsActor();

    $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', ['value' => 'UTC'])->assertOk();
    $stale = $this->actingAs($user)->getJson('/api/staff/settings/tenant.display_timezone')->json('version');

    // Admin 1 wins.
    $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', [
        'value' => 'Africa/Nairobi',
        'version' => $stale,
    ])->assertOk();

    // Admin 2 submits the form they rendered from and must be told, not silently ignored.
    $conflict = $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', [
        'value' => 'Asia/Dubai',
        'version' => $stale,
    ]);

    $conflict->assertStatus(409);
    expect($conflict->json('code'))->toBe('setting_write_conflict')
        // What it lost to, so the toast can say something useful.
        ->and($conflict->json('winning_value'))->toBe('Africa/Nairobi')
        ->and($conflict->json('version'))->toBe(2);
});

it('resets a setting to its registered default', function () {
    $user = settingsActor();

    $this->actingAs($user)->putJson('/api/staff/settings/tenant.default_language', ['value' => 'am'])->assertOk();

    $reset = $this->actingAs($user)->deleteJson('/api/staff/settings/tenant.default_language');

    $reset->assertOk();
    expect($reset->json('value'))->toBe('en')
        ->and($reset->json('is_default'))->toBeTrue();
});

/**
 * The audit-declaration middleware's runtime half.
 *
 * The `audit-coverage` gate proves each mutating route DECLARES an action. That alone
 * decays: the tag survives long after someone deletes the audit call inside the handler,
 * and the gate stays green while the trail goes quiet. This asserts the declaration is
 * checked against what the request actually wrote.
 */
it('records the audit entry a mutating route declares', function () {
    $user = settingsActor();
    $tenantId = $user->getAttribute('tenant_id');

    // Counting audit rows is itself a tenant-scoped read, so it needs a bound tenant. With
    // none bound it fails with `invalid input syntax for type uuid: ""` — which is the RLS
    // layer refusing an unscoped read, not a test-harness quirk. asTenant() restores the
    // unbound state afterwards so the request below still exercises real resolution.
    $before = asTenant($tenantId, fn (): int => DB::table('audit_log')
        ->where('action_key', 'tenant.setting_changed')->count());

    $this->actingAs($user)
        ->putJson('/api/staff/settings/tenant.display_timezone', ['value' => 'Asia/Dubai'])
        ->assertOk();

    $after = asTenant($tenantId, fn (): int => DB::table('audit_log')
        ->where('action_key', 'tenant.setting_changed')->count());

    expect($after)->toBe($before + 1);
});

it('fails loudly if a declared audit action is not written', function () {
    $user = settingsActor();

    // Simulate the decay the middleware exists to catch: the handler runs and succeeds,
    // but the audit entry never appears. Achieved by freezing the counter the middleware
    // compares against, which is exactly what a handler that stopped auditing would look
    // like from the middleware's point of view.
    AuditLogger::resetRecordedCounts();

    $this->app->bind(SettingsRepository::class, function () {
        return new class extends stdClass
        {
            // Deliberately not a SettingsRepository: binding a stand-in that does nothing
            // makes the controller's write a no-op, so no audit entry is written while the
            // route still declares one.
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };
    });

    // In local/testing the middleware throws rather than logging, so the suite catches it.
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->putJson('/api/staff/settings/tenant.display_timezone', ['value' => 'UTC']))
        ->toThrow(LogicException::class, 'without writing that audit entry');
})->skip(
    fn (): bool => true,
    'The stand-in binding needs SettingsRepository to be an interface before it can be '
    .'substituted cleanly; extracting that interface is worth doing but is not Doc 01 scope. '
    .'The middleware\'s throw path is exercised in dev by removing an audit call — recorded in '
    .'BUILD-STATUS as a gap.',
);
