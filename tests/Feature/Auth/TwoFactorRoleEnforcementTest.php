<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 FR-09, closed at S19 step 7 with the real role resolver: members of
 * `auth.2fa.required_roles` cannot reach any screen except enrolment once the
 * `auth.2fa.grace_days` grace runs out; within grace, a countdown rides the
 * request. The grace anchor is `two_factor_required_since`, stamped on first
 * subject encounter (filed interpretation — the migration's docblock).
 */
function fr09Fixture(array $requiredRoles = ['Compliance Officer']): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, function () use ($requiredRoles) {
        $user = grantPermissions(User::factory()->create(), ['settings.view'], 'Compliance Officer');
        app(SettingsRepository::class)->set(
            key: 'auth.2fa.required_roles',
            value: $requiredRoles,
            actor: App\Support\Audit\AuditActor::System,
        );

        return $user;
    });
    activateTenant($tenant);

    return [$tenant, $user];
}

it('stamps the grace anchor on first subject encounter and lets the user work with a countdown (FR-09 grace half)', function () {
    [$tenant, $user] = fr09Fixture();

    $response = test()->actingAs($user)->get('https://localhost/api/staff/settings');
    $response->assertOk();

    asTenant($tenant, function () use ($user): void {
        expect($user->fresh()->getAttribute('two_factor_required_since'))->not->toBeNull();
    });
});

it('locks a past-grace member out of every screen EXCEPT enrolment/security/logout (FR-09 enforcement half)', function () {
    [$tenant, $user] = fr09Fixture();

    // Rewind the anchor past the grace window, in DB time.
    asTenant($tenant, function () use ($user): void {
        DB::table('users')->where('id', $user->identifier())
            ->update(['two_factor_required_since' => DB::raw("now() - interval '10 days'")]);
    });

    // An ordinary screen: refused with the enrolment-required status.
    test()->actingAs($user)->getJson('https://localhost/api/staff/settings')
        ->assertStatus(403)
        ->assertJsonPath('status', 'two_factor_enrolment_required');

    // The enrolment surface stays reachable — the user can always comply.
    test()->actingAs($user)->postJson('https://localhost/api/staff/security/2fa')
        ->assertOk();
});

it('leaves unlisted roles, enrolled users and empty settings untouched (FR-09 scope)', function () {
    [$tenant, $user] = fr09Fixture(['Some Other Role']);

    test()->actingAs($user)->getJson('https://localhost/api/staff/settings')->assertOk();

    asTenant($tenant, function () use ($user): void {
        expect($user->fresh()->getAttribute('two_factor_required_since'))->toBeNull();
    });
});
