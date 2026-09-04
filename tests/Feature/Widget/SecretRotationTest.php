<?php

declare(strict_types=1);

use App\Domain\Webchat\Identity\SignedIdentityVerifier;
use App\Domain\Webchat\Identity\WidgetSecretMinter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 SEC-03 / FR-19 Install — the secret rotates with an OVERLAP window
 * (Doc 02 FR-19's pattern): both verify inside the window, only the new one
 * after; the plaintext is shown once and exists nowhere at rest (RIDER 1: the
 * vault interim holds ciphertext, the widget row holds refs); the rotation
 * stamp is the DATABASE's (C1); a ciphertext UPDATE dies 42501.
 */
it('rotates with an overlap window: old + new verify inside it, only new after; refs on the row, ciphertext in the vault interim, plaintext nowhere', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);
    $minter = app(WidgetSecretMinter::class);
    $verifier = app(SignedIdentityVerifier::class);
    $actor = (string) Illuminate\Support\Str::uuid7();

    $old = $minter->mint($widget, $actor);
    $widget = $widget->fresh();
    expect($widget->identity_secret_ref)->toBe('vault://widget/'.$widget->getKey().'/v1')
        ->and($widget->identity_secret_previous_ref)->toBeNull()
        ->and($widget->identity_secret_rotated_at)->not->toBeNull('C1: the stamp moves whenever the ref changes — the first mint included (the countersigned wording); the OVERLAP logic keys on previous_ref, which is null here');
    $firstStamp = (string) DB::table('widgets')->where('id', $widget->getKey())->value('identity_secret_rotated_at'); // raw microseconds — the model cast drops them
    DB::statement('SELECT pg_sleep(0.01)');

    $new = $minter->mint($widget, $actor);
    $widget = $widget->fresh();
    expect($widget->identity_secret_ref)->toBe('vault://widget/'.$widget->getKey().'/v2')
        ->and($widget->identity_secret_previous_ref)->toBe('vault://widget/'.$widget->getKey().'/v1')
        ->and($widget->identity_secret_rotated_at)->not->toBeNull('C1: the trigger stamps the rotation from the DB clock')
        ->and((string) DB::table('widgets')->where('id', $widget->getKey())->value('identity_secret_rotated_at'))->not->toBe($firstStamp, 'the rotation re-stamped from the DB clock');

    $now = CarbonImmutable::now('UTC');
    $payload = ['user_ref' => 'p', 'ts' => $now->getTimestamp()];
    $sign = fn (string $s): string => hash_hmac('sha256', SignedIdentityVerifier::canonical($payload), $s);
    expect($verifier->refusal($widget, $payload, $sign($old), $now))->toBe('')
        ->and($verifier->refusal($widget, $payload, $sign($new), $now))->toBe('');

    // After the window: the old secret no longer verifies (the clock is an injected instant).
    $later = CarbonImmutable::parse((string) $widget->identity_secret_rotated_at)->addHours(25);
    $payloadLater = ['user_ref' => 'p', 'ts' => $later->getTimestamp()];
    $signLater = fn (string $s): string => hash_hmac('sha256', SignedIdentityVerifier::canonical($payloadLater), $s);
    expect($verifier->refusal($widget, $payloadLater, $signLater($old), $later))->toBe('bad_signature')
        ->and($verifier->refusal($widget, $payloadLater, $signLater($new), $later))->toBe('');

    // Plaintext nowhere at rest: neither the widget row nor the vault row carries it.
    $vault = DB::table('vault_secrets')->get();
    expect($vault)->toHaveCount(2);
    foreach ($vault as $row) {
        expect($row->ciphertext)->not->toContain($old)->not->toContain($new);
    }
    expect(json_encode(DB::table('widgets')->where('id', $widget->getKey())->first()))->not->toContain($old)->not->toContain($new);
    // The audit rows never carry a value or a ref.
    foreach (DB::table('audit_log')->whereIn('action_key', ['widget.secret.created', 'widget.secret.rotated'])->get() as $audit) {
        expect((string) $audit->payload)->not->toContain('vault://')->not->toContain($old)->not->toContain($new);
    }

    // The sweep retires the predecessor after the window; the row stays.
    $minter->sweepOverlap($widget, $later);
    expect(DB::table('vault_secrets')->whereNotNull('retired_at')->count())->toBe(1)
        ->and($widget->fresh()->identity_secret_previous_ref)->toBeNull();

    // RIDER 1 physics: a ciphertext UPDATE dies 42501; a DELETE dies too.
    foreach (["UPDATE vault_secrets SET ciphertext = 'x'", 'DELETE FROM vault_secrets'] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }
});
