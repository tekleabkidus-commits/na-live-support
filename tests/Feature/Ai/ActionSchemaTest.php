<?php

declare(strict_types=1);

use App\Domain\Ai\Chatbot\ActionSchema;
use App\Domain\Ai\Chatbot\DiscardedAction;
use App\Domain\Ai\Chatbot\ValidatedAction;

/**
 * FR-08/EC-05/T-03: the closed action enum's validator — the injection
 * blast-radius bound (sabotage #2's subject), landed BEFORE any chatbot
 * path exists. AC-02's schema-enforced-citation clause lives here too.
 */
function schema(): ActionSchema
{
    return new ActionSchema;
}

it('EC-05: an action outside the closed enum is DISCARDED, never executed (injection-shaped #1)', function (): void {
    $result = schema()->validate(['action' => 'transfer_funds', 'amount' => 5000]);

    expect($result)->toBeInstanceOf(DiscardedAction::class)
        ->and($result->category)->toBe('out_of_enum');
});

it('EC-05: malformed output (non-object, or missing required fields) is discarded with its category', function (): void {
    expect(schema()->validate('just prose'))->toBeInstanceOf(DiscardedAction::class)
        ->and(schema()->validate('just prose')->category)->toBe('malformed')
        ->and(schema()->validate(['action' => 'run_form'])->category)->toBe('missing_field')
        ->and(schema()->validate(['action' => 'resolve', 'closing_text' => '  '])->category)->toBe('missing_field');
});

it('BR-02/AC-02: an answer WITHOUT a citation is refused by the schema itself — never sent', function (): void {
    $uncited = schema()->validate(['action' => 'answer', 'text' => 'Deposits settle within 30 minutes.']);
    expect($uncited)->toBeInstanceOf(DiscardedAction::class)
        ->and($uncited->detail)->toContain('citation');

    $emptyCitations = schema()->validate(['action' => 'answer', 'text' => 'x', 'citations' => []]);
    expect($emptyCitations)->toBeInstanceOf(DiscardedAction::class);

    $cited = schema()->validate(['action' => 'answer', 'text' => 'Deposits settle within 30 minutes.', 'citations' => ['kb-42']]);
    expect($cited)->toBeInstanceOf(ValidatedAction::class);
});

it('FR-12/Q7: handoff reasons come from the CLOSED category list — free prose is refused (the STEP-0 observation, mechanized)', function (): void {
    $prose = schema()->validate(['action' => 'handoff', 'reason' => 'The user is expressing a gambling addiction and needs specialized help']);
    expect($prose)->toBeInstanceOf(DiscardedAction::class);

    $category = schema()->validate(['action' => 'handoff', 'reason' => 'rg_risk']);
    expect($category)->toBeInstanceOf(ValidatedAction::class);
});

it('the five legal actions all validate with their required fields', function (): void {
    $legal = [
        ['action' => 'answer', 'text' => 'x', 'citations' => ['kb-1']],
        ['action' => 'run_form', 'form_id' => 'deposit_issue'],
        ['action' => 'continue_flow', 'state' => 'menu:2'],
        ['action' => 'handoff', 'reason' => 'no_grounding'],
        ['action' => 'resolve', 'closing_text' => 'Glad I could help!'],
    ];

    foreach ($legal as $payload) {
        expect(schema()->validate($payload))->toBeInstanceOf(ValidatedAction::class, $payload['action'].' failed to validate');
    }
});
