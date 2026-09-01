<?php

declare(strict_types=1);

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Rules\RuleWriter;
use App\Domain\Automation\Rules\SystemActorFloor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 SEC-01 / AC-09 — THE ACTIVATOR-CAPABILITY FLOOR (before any arm runs)
|--------------------------------------------------------------------------
|
| Landed WITH the engine, BEFORE any action arm exists (the batch-B ruling's
| order). Four proofs:
|   1. CAPTURE — activation stores the activator's floor ON THE REVISION,
|      projected through FLOOR_MAP; a limited activator captures a limited
|      floor.
|   2. THE DEMOTED-AUTHOR FALSIFICATION (sabotage (e)'s standing red): an
|      execution under a floor WITHOUT tickets.priority.change answers
|      `floor_refused` for set_priority — the rule cannot out-privilege its
|      activator, even though the rule's DEFINITION names the action.
|   3. ORDERING — a floored-and-ALLOWED type reaches the arm layer (outcome
|      `unbuilt_action` in step 4 — no arm is bound yet, and no fake success
|      exists), so the refusal above is the FLOOR, not a dead registry.
|   4. DRIFT — revoking the activator's key after activation surfaces the type
|      in driftFor(); the captured floor itself is UNTOUCHED (re-activation is
|      a NEW revision).
*/

function floorScene(array $activatorPermissions): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $author = grantAllPermissions(ticketActor([]));
    $activator = ticketActor($activatorPermissions, 'Floor Activator Role');
    $writer = app(RuleWriter::class);
    $made = $writer->create([
        'name' => 'Priority bump', 'trigger' => 'ticket.created',
        'conditions' => ['all' => []],
        'actions' => [['type' => 'set_priority', 'priority' => 'high'], ['type' => 'add_note', 'body' => 'bumped']],
        'cooldown_seconds' => 0,
    ], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $activator);

    $executionId = (string) Str::uuid7();
    DB::table('automation_rule_executions')->insert([
        'id' => $executionId, 'tenant_id' => $tenant->identifier(),
        'rule_id' => $made['rule_id'], 'revision_id' => $made['revision_id'],
        'event_id' => (string) Str::uuid7(),
        'context_ref' => json_encode(['ticket_id' => $ticket->identifier()]),
        'matched' => true,
    ]);

    return [$made, $executionId, $activator];
}

it('SEC-01 (1): activation captures the activator\'s floor on the revision — a limited activator captures a limited floor', function (): void {
    [$made] = floorScene(['tickets.note']);
    /** @var array<string, bool> $floor */
    $floor = json_decode((string) DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('activator_capability_floor'), true, 4);
    expect($floor['add_note'])->toBeTrue()
        ->and($floor['set_priority'])->toBeFalse()
        ->and($floor['assign'])->toBeFalse()
        ->and(array_keys($floor))->toEqualCanonicalizing(array_keys(SystemActorFloor::FLOOR_MAP)); // jsonb reorders keys
});

it('SEC-01 (2+3): the demoted-author falsification — set_priority is floor_refused while the floored add_note reaches the arm layer', function (): void {
    [, $executionId] = floorScene(['tickets.note']);
    $registry = app(ActionRegistry::class);

    $refused = $registry->execute('set_priority', ['type' => 'set_priority', 'priority' => 'high'], $executionId, 1);
    expect($refused['outcome'])->toBe('failed')
        ->and($refused['reason'])->toBe('floor_refused', 'the floor did not refuse — a demoted author\'s rule can out-privilege the org (SEC-01 broken)');

    // Since step 5 the add_note arm is BOUND, so the ordering proof is stronger:
    // the floored-and-allowed type passes the gate and the REAL arm runs.
    $reached = $registry->execute('add_note', ['type' => 'add_note', 'body' => 'x'], $executionId, 1);
    expect($reached['outcome'])->toBe('ok', 'the allowed type never reached the arm — the gate is a dead registry, not a floor');
});

it('SEC-01 (4): revoking the activator\'s key after activation surfaces DRIFT; the captured floor is untouched', function (): void {
    [$made, , $activator] = floorScene(['tickets.note', 'tickets.priority.change']);
    $floorService = app(SystemActorFloor::class);
    expect($floorService->driftFor($made['rule_id']))->toBe([]);

    // The demotion: move the activator onto a NEW role without the priority key
    // (grantPermissions firstOrCreate's by role name, so a fresh name is the
    // reliable demotion path).
    grantPermissions($activator, ['tickets.note'], 'Floor Demoted Role');

    $drift = $floorService->driftFor($made['rule_id']);
    // toContain is VARIADIC needles (the S11-recorded trap) — no message arg.
    expect($drift)->toContain('set_priority');
    /** @var array<string, bool> $captured */
    $captured = json_decode((string) DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('activator_capability_floor'), true, 4);
    expect($captured['set_priority'])->toBeTrue('the CAPTURED floor moved — history rewritten; a refresh is a NEW revision');
});
