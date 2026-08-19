<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Guards\NonFixturePayloadRefused;

/**
 * The fixtures-only egress guard's own proofs (S09 step 1; SESSION-09 subtle
 * part #1 — the Doc 05 PII law, mechanized; sabotage #11's subject).
 *
 * THE PLANT CONTROL IS THE POINT: a non-fixture payload attempting egress in
 * a non-production environment must be REFUSED — and the refusal must be
 * leak-proof (the exception names the slot, never the content, because the
 * content is exactly what must not reach a log). Removing the guard
 * (sabotage #11) turns the first test red.
 */
const REAL_TEXT_PLANT = 'my withdrawal of 2,000 ETB to 0900001234 has not arrived';

function egressGuard(): FixturesOnlyEgress
{
    return new FixturesOnlyEgress;
}

function chatPayload(string $userContent): array
{
    return [
        'model' => 'fixture-model',
        'messages' => [
            ['role' => 'system', 'content' => 'You are Fixture Bet Support.'],
            ['role' => 'user', 'content' => $userContent],
        ],
    ];
}

it('refuses a non-fixture user payload outside production — the plant control (sabotage #11 subject)', function (): void {
    config(['app.env' => 'testing']);

    expect(fn () => egressGuard()->assertAllowed(chatPayload(REAL_TEXT_PLANT)))
        ->toThrow(NonFixturePayloadRefused::class);
});

it('the refusal is leak-proof: the exception message never carries the payload text', function (): void {
    config(['app.env' => 'testing']);

    try {
        egressGuard()->assertAllowed(chatPayload(REAL_TEXT_PLANT));
        $this->fail('the guard did not refuse — the leak-proof assertion below would be vacuous');
    } catch (NonFixturePayloadRefused $e) {
        expect($e->getMessage())->not->toContain('0900001234')
            ->and($e->getMessage())->not->toContain('withdrawal')
            ->and($e->getMessage())->toContain('messages[1]');
    }
});

it('allows a fixture-tagged user payload outside production', function (): void {
    config(['app.env' => 'testing']);

    egressGuard()->assertAllowed(chatPayload(FixturesOnlyEgress::TAG.' how do I deposit with telebirr?'));

    expect(true)->toBeTrue(); // reaching here IS the assertion: no refusal thrown
});

it('system-role content is exempt (operator/platform material, never customer text)', function (): void {
    config(['app.env' => 'testing']);

    $payload = chatPayload(FixturesOnlyEgress::TAG.' ping');
    $payload['messages'][0]['content'] = 'Persona wording with no tag — operator content.';

    egressGuard()->assertAllowed($payload);

    expect(true)->toBeTrue();
});

it('refuses an untagged embeddings input and allows a tagged one', function (): void {
    config(['app.env' => 'testing']);

    expect(fn () => egressGuard()->assertAllowed(['model' => 'embed', 'input' => REAL_TEXT_PLANT]))
        ->toThrow(NonFixturePayloadRefused::class);

    egressGuard()->assertAllowed(['model' => 'embed', 'input' => [FixturesOnlyEgress::TAG.' how to deposit']]);

    expect(true)->toBeTrue();
});

it('refuses non-string user content without a tag anywhere in it (over-refusing is the safe direction)', function (): void {
    config(['app.env' => 'testing']);

    $payload = chatPayload('replaced below');
    $payload['messages'][1]['content'] = [['type' => 'text', 'text' => REAL_TEXT_PLANT]];

    expect(fn () => egressGuard()->assertAllowed($payload))
        ->toThrow(NonFixturePayloadRefused::class);
});

it('production is the carve-out: untagged payloads flow (the only environment where real text is lawful)', function (): void {
    config(['app.env' => 'production']);

    try {
        egressGuard()->assertAllowed(chatPayload(REAL_TEXT_PLANT));
        expect(true)->toBeTrue();
    } finally {
        config(['app.env' => 'testing']);
    }
});
