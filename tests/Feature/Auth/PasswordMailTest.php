<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * FS-02 (ADR 0007): auth email is QUEUED on the dedicated `mail` family with
 * retry ×6 backoff — never sent inline in the request — and renders in the
 * recipient's own language, resolved at dispatch through C-08's chain
 * (user → tenant default → en) because the queue worker has no tenant.
 *
 * The other halves of FS-02 live elsewhere: the supervisor exists in
 * config/horizon.php and StackSmokeTest asserts all twelve families are
 * supervised; the "queue depth visible to admins" clause is DEFERRED with the
 * admin console (Horizon shows it meanwhile) — recorded in BUILD-STATUS.
 */
function mailUser(array $overrides = []): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(array_merge([
        'email' => 'mail@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ], $overrides)));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function postForgotFor(string $email): void
{
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(2, 250)])
        ->postJson('https://localhost/forgot-password', ['email' => $email])
        ->assertOk();
}

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['*' => Http::response('', 200)]);
});

it('queues the reset mail on the `mail` family with tries=6 and the widening backoff — never inline', function (): void {
    mailUser();

    postForgotFor('mail@fixture.example');

    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
        expect($mail->queue)->toBe('mail')                                  // ADR 0007
            ->and($mail->tries)->toBe(6)                                    // FS-02 retry ×6
            ->and($mail->backoff())->toBe([60, 300, 900, 3600, 10800]);     // widening, ~9 h of patience

        return true;
    });

    // Queued means queued: nothing went out on the request thread. With the
    // provider down, FS-02's behaviour is "wait on redis-durable", and an
    // inline send() here would instead have failed the HTTP request.
    Mail::assertNothingSent();
});

it('pins the recipient locale at dispatch: user preference first', function (): void {
    mailUser(['locale' => 'am']);

    postForgotFor('mail@fixture.example');

    Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->locale === 'am');
});

it('falls back to the tenant default language when the user has no preference (C-08\'s chain)', function (): void {
    [$tenant] = mailUser(['locale' => null]);

    asTenant($tenant, fn () => app(SettingsRepository::class)->set('tenant.default_language', 'am'));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    postForgotFor('mail@fixture.example');

    Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->locale === 'am');
});

it('renders complete localized content in BOTH launch languages, with the workspace name as data', function (): void {
    // Rendered directly (the array transport would do the same work): the
    // point is that every i18n key resolves in en AND am — a missing am key
    // would fall back to English silently in production and this is where
    // that shows first. The I18nFallbackTest full-key sweep covers key
    // parity; this covers the TEMPLATES actually using them.
    foreach (['en', 'am'] as $locale) {
        $reset = new PasswordResetMail(
            resetUrl: 'https://localhost/reset-password?token='.str_repeat('a', 64),
            workspaceName: 'Fixture Betting',
            validMinutes: 60,
        );
        $rendered = $reset->withLocale($locale, fn (): string => $reset->render());

        expect($rendered)->toContain('Fixture Betting')                      // N-03: brand as data
            ->and($rendered)->toContain(str_repeat('a', 64))                 // the link is present
            ->and($rendered)->not->toContain('auth.mail.');                  // no leaked key names

        $invite = (new InvitationMail(
            inviteUrl: 'https://localhost/invitation?token='.str_repeat('b', 64),
            workspaceName: 'Fixture Betting',
            validDays: 7,
        ));
        $renderedInvite = $invite->withLocale($locale, fn (): string => $invite->render());

        expect($renderedInvite)->toContain('Fixture Betting')
            ->and($renderedInvite)->toContain(str_repeat('b', 64))
            ->and($renderedInvite)->not->toContain('auth.mail.');
    }
});

it('queues the invitation mail with the same FS-02 posture', function (): void {
    $mail = new InvitationMail(
        inviteUrl: 'https://localhost/invitation?token='.str_repeat('c', 64),
        workspaceName: 'Fixture Betting',
        validDays: 7,
    );

    expect($mail->queue)->toBe('mail')
        ->and($mail->tries)->toBe(6)
        ->and($mail->backoff())->toBe([60, 300, 900, 3600, 10800])
        ->and($mail)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});
