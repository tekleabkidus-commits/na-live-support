<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\CredentialRef;

/*
| Doc 14 FR-07 / SEC-01 / §7 "the vault interim" — THE VAULT-REF-ONLY ASSERTION,
| sequenced BEFORE the gateway table's first write (STOP-R 5).
|
| Gateway credentials are `vault://sms/…` references (ADR 0021's interim),
| never a value. This value object is the model-level half; the
| `sms_gateways.credential_ref ~ '^vault://sms/'` CHECK (SCHEMA AMENDMENT 8)
| is the physics half with its 23514 control at step 2b. A plaintext
| credential column, fixture or capture is a Sev-1 (§7).
*/

test('a vault ref under the sms namespace is accepted and round-trips unchanged', function () {
    $ref = CredentialRef::fromString('vault://sms/0192a3b4-0000-7000-8000-000000000001/token/v1');

    expect((string) $ref)->toBe('vault://sms/0192a3b4-0000-7000-8000-000000000001/token/v1')
        ->and($ref->namespace())->toBe('sms');
});

test('anything that is not a vault ref under sms is REFUSED — a token-shaped value, a bare word, another namespace, an empty string', function () {
    foreach ([
        'fixture-geezsms-token',
        'eyJpdiI6abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKL=',
        '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        'vault://widget/0192a3b4-0000-7000-8000-000000000001/v1',
        'vault://sms',
        'vault://sms/',
        'VAULT://sms/x/v1',
        '',
        ' vault://sms/x/v1',
    ] as $value) {
        expect(static fn () => CredentialRef::fromString($value))
            ->toThrow(InvalidArgumentException::class, null, "[{$value}] must be refused");
    }
});

test('the ref never serializes to anything but itself and carries no plaintext accessor', function () {
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(CredentialRef::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($methods);

    expect($methods)->toBe(['__toString', 'fromString', 'namespace'])
        ->and(json_encode(['ref' => (string) CredentialRef::fromString('vault://sms/g/token/v1')]))->toBe('{"ref":"vault:\/\/sms\/g\/token\/v1"}');
});
