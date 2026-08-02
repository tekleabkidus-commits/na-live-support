<?php

declare(strict_types=1);

use App\Domain\Auth\Password\BreachChecker;
use App\Domain\Auth\Password\PasswordPolicy;
use App\Domain\Auth\Password\PasswordPolicyViolation;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * T-09/FS-03: k-anonymity, 500 ms, fail-open with metric — with the two
 * assertions the owner named at plan approval: the outbound request is
 * inspected ITSELF (only the 5-char prefix ever leaves), and the sabotage
 * clause is the fail-open INVERSION (an outage must never start refusing
 * passwords).
 */
const BREACHED_PASSWORD = 'this-password-is-breached';

function breachRangeResponseFor(string $password, int $count = 1234): string
{
    $digest = strtoupper(sha1($password));
    $suffix = substr($digest, 5);

    // A realistic bucket: unrelated suffixes around the real one, plus a
    // padded zero-count decoy that must NOT read as a breach.
    return implode("\r\n", [
        str_repeat('0', 35).':17',
        $suffix.':'.$count,
        str_repeat('F', 35).':0',
    ]);
}

function breachUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'breach@fixture.example',
        'password' => Hash::make('Initial-Correct-Horse-01'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    return [$tenant, $user];
}

it('sends exactly the 5-char SHA-1 prefix — never the password, its digest, or the suffix', function (): void {
    Http::fake(['*' => Http::response(breachRangeResponseFor(BREACHED_PASSWORD), 200)]);

    app(BreachChecker::class)->isBreached(BREACHED_PASSWORD);

    $digest = strtoupper(sha1(BREACHED_PASSWORD));
    $prefix = substr($digest, 0, 5);
    $suffix = substr($digest, 5);

    // The owner's named assertion: on the faked REQUEST OBJECT itself.
    Http::assertSent(function (ClientRequest $request) use ($prefix, $suffix, $digest): bool {
        $url = $request->url();

        return str_ends_with($url, '/range/'.$prefix)
            && ! str_contains($url, $suffix)
            && ! str_contains($url, $digest)
            && ! str_contains($url, BREACHED_PASSWORD)
            && $request->body() === '';
    });
    Http::assertSentCount(1);
});

it('flags a breached password on a suffix hit and lets the policy refuse it', function (): void {
    Http::fake(['*' => Http::response(breachRangeResponseFor(BREACHED_PASSWORD), 200)]);

    expect(app(BreachChecker::class)->isBreached(BREACHED_PASSWORD))->toBeTrue();

    // Through the policy: the rule is named, per FR-16's live validation shape.
    [$tenant, $user] = breachUser();
    try {
        asTenant($tenant, fn () => app(PasswordPolicy::class)->enforce($user, BREACHED_PASSWORD));
        $this->fail('a breached password passed the policy');
    } catch (PasswordPolicyViolation $violation) {
        expect($violation->errors)->toHaveKey('breached');
    }
});

it('does not flag a clean password, and treats a padded zero-count decoy as clean', function (): void {
    Http::fake(['*' => Http::response(breachRangeResponseFor(BREACHED_PASSWORD), 200)]);

    // Same bucket, different password: its suffix is not in the response.
    expect(app(BreachChecker::class)->isBreached('a-completely-different-password'))->toBeFalse();

    // The zero-count entry IS present for this one — padding decoys mean
    // "not breached", and counting them as hits would refuse random users.
    $decoyed = str_repeat('F', 35);
    Http::fake(['*' => Http::response(substr(strtoupper(sha1('padded-password')), 5).':0', 200)]);
    expect(app(BreachChecker::class)->isBreached('padded-password'))->toBeFalse();
});

it('fails OPEN on timeout, with the named metric — an outage never blocks a password (FS-03)', function (): void {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('timed out after 0.5s'));

    // The sabotage clause guards this exact line: inverted fail-open would
    // return true here and the assertion goes red.
    expect(app(BreachChecker::class)->isBreached(BREACHED_PASSWORD))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === BreachChecker::METRIC
            && ($context['failure'] ?? '') === 'unreachable')
        ->once();
});

it('fails OPEN on a non-2xx, with the failure class in the metric', function (): void {
    Log::spy();
    Http::fake(['*' => Http::response('upstream sad', 503)]);

    expect(app(BreachChecker::class)->isBreached(BREACHED_PASSWORD))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === BreachChecker::METRIC
            && ($context['failure'] ?? '') === 'http_503')
        ->once();
});

it('fails OPEN when no endpoint is configured, without dialling anything', function (): void {
    Log::spy();
    Http::fake();
    config(['services.breach_check.base_url' => '']);

    expect(app(BreachChecker::class)->isBreached(BREACHED_PASSWORD))->toBeFalse();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === BreachChecker::METRIC
            && ($context['failure'] ?? '') === 'unconfigured')
        ->once();
});

it('makes zero calls when the tenant has turned the check off (FR-15\'s toggle)', function (): void {
    Http::fake(['*' => Http::response(breachRangeResponseFor(BREACHED_PASSWORD), 200)]);

    [$tenant, $user] = breachUser();
    asTenant($tenant, fn () => app(SettingsRepository::class)->set('auth.password.breach_check', false));

    // With the toggle off even a KNOWN-breached password passes this rule
    // (that is what the toggle means), and no request leaves at all.
    asTenant($tenant, fn () => app(PasswordPolicy::class)->enforce($user, BREACHED_PASSWORD));

    Http::assertNothingSent();
});

it('never logs anything password-derived on any path (SEC-08)', function (): void {
    $lines = [];
    Log::listen(function ($event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    Http::fake(fn () => throw new ConnectionException('timed out'));
    app(BreachChecker::class)->isBreached(BREACHED_PASSWORD);

    $digest = strtoupper(sha1(BREACHED_PASSWORD));
    $joined = implode("\n", $lines);

    expect($joined)->not->toContain(BREACHED_PASSWORD)
        ->and($joined)->not->toContain($digest)
        ->and($joined)->not->toContain(substr($digest, 0, 5));
});
