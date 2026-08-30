<?php

declare(strict_types=1);

use App\Domain\Routing\Rg\Diversion;
use App\Domain\Routing\Rg\RgFinality;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-08 step 2 / BR-02 / SEC-06 / US-06 / AC-01 — RG ROUTING IS FINAL
|--------------------------------------------------------------------------
|
| Table-driven (T-09 "RG finality" fixtures), written BEFORE the pipeline exists
| (S11 step 1). Every way a ticket could leave a queue is a Diversion case; for an
| RG ticket exactly ONE is permitted and only for an RG-permitted actor. The
| pipeline (step 5) and the manual doors (step 7) consult this single function;
| sabotage #3 (a boost allowed to move an RG ticket) goes red on the table row
| named `boost`.
*/

$rgPermitted = ['supervision.reassign', 'customers.rg_flag.view'];

it('BR-02 table: no rule, boost, strategy, bulk action, AI suggestion, rule-transfer or automation diverts an RG ticket', function (Diversion $diversion) use ($rgPermitted) {
    $finality = new RgFinality;

    expect($finality->mayDivert(true, $diversion))->toBeFalse("{$diversion->value} diverted an RG ticket")
        // Even an RG-permitted actor cannot take these paths — the exception is INDIVIDUAL manual reassign only.
        ->and($finality->mayDivert(true, $diversion, $rgPermitted))->toBeFalse("{$diversion->value} diverted an RG ticket for an RG-permitted actor")
        // A non-RG ticket is free to move — the finality is RG's alone.
        ->and($finality->mayDivert(false, $diversion))->toBeTrue();
})->with([
    'rule' => Diversion::Rule,
    'boost' => Diversion::Boost,
    'strategy' => Diversion::Strategy,
    'bulk_manual' => Diversion::BulkManual,
    'ai_suggestion' => Diversion::AiSuggestion,
    'rule_transfer' => Diversion::RuleTransfer,
    'automation' => Diversion::Automation,
]);

it('BR-02 exception, exactly as narrow as drawn: INDIVIDUAL manual reassign by RG-permitted staff only', function () use ($rgPermitted) {
    $finality = new RgFinality;

    expect($finality->mayDivert(true, Diversion::IndividualManualReassign, $rgPermitted))->toBeTrue()
        ->and($finality->mayDivert(true, Diversion::IndividualManualReassign, ['supervision.reassign']))->toBeFalse('reassign without RG visibility diverted an RG ticket')
        ->and($finality->mayDivert(true, Diversion::IndividualManualReassign, ['customers.rg_flag.view']))->toBeFalse('RG visibility without reassign diverted an RG ticket')
        ->and($finality->mayDivert(true, Diversion::IndividualManualReassign, []))->toBeFalse();
});

it('FR-08 step 2: RG intake is FORCED to the is_rg queue when one exists; without one the resolved queue stands (the FS-04/EC-10 net is elsewhere)', function () {
    $finality = new RgFinality;

    expect($finality->forcedQueue(true, 'rg-queue', 'payments-queue'))->toBe('rg-queue')
        ->and($finality->forcedQueue(false, 'rg-queue', 'payments-queue'))->toBe('payments-queue')
        ->and($finality->forcedQueue(true, null, 'payments-queue'))->toBe('payments-queue');
});
