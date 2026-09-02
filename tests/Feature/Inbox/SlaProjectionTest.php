<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Sla\Instances\StageLifecycle;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| PACKET P-CHIP LANDED (S12 batch D; countersigned at the batch-C acceptance)
| — Doc 07 FR-11's chip columns fed from Doc 12's TRUTH
|--------------------------------------------------------------------------
|
| ticket_list_items.sla_state / sla_due_at were the [D12] seam (hand-painted
| by the browser seeders, fed by nothing in production). Now: EVERY recompute
| of a ticket that HOLDS an sla_instance answers Chips::forTicket — amber →
| 'warned', red → 'breached', green/none → null, due_at = the live chip's
| target — VALUES FROM TRUTH, never from an event payload (the RollupConsumer
| posture). sla.warned / sla.breached joined the projector's registry so the
| flip re-projects promptly; the CLEAR rides the paths the projector already
| owns (staff reply, status change) because the row builder re-reads truth on
| every pass. A ticket with NO instance keeps whatever its row holds (the
| seeders' hand-paint stays until the fidelity session — stated in the packet).
|
| The fail-first control: BEFORE the landing this file's first assertion is
| red (the row stayed null under a warned stage) — captured as
| docs/evidence/S12-step13/pchip-prestate-01.txt.
*/

it('P-CHIP: warned/breached project from TRUTH — the row carries Chips\' state + target; a POISONED payload due_at never lands', function (): void {
    [$tenant, $ticket, $instance] = timerScene(600);
    $ticketId = $ticket->identifier();
    $projector = app(ListProjector::class);

    // A live green chip: state null (no risk), due_at = the first_response target (the countdown source).
    $projector->recomputeTicket($ticketId);
    $target = (string) DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->value('target_at');
    $row = listItemState($ticketId);
    expect($row['sla_state'])->toBeNull()
        ->and(new DateTimeImmutable((string) $row['sla_due_at']))->toEqual(new DateTimeImmutable($target));

    // The stage flips warned (RIDER 1's app-writable stamp); the event arrives with a POISONED due_at.
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['warned' => true]);
    $poisoned = '2099-01-01T00:00:00+00:00';
    $warned = inboxSeedEvent('sla.warned', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticketId, 'instance_id' => $instance->id,
        'stage' => 'first_response', 'due_at' => $poisoned, 'late_fired' => false,
    ]);
    runListProjector($warned);
    $row = listItemState($ticketId);
    expect($row['sla_state'])->toBe('warned', 'the warned stage did not reach the row — P-CHIP not landed')
        ->and(new DateTimeImmutable((string) $row['sla_due_at']))->toEqual(new DateTimeImmutable($target), 'the row took the PAYLOAD due_at — values must come from truth')
        ->and(new DateTimeImmutable((string) $row['sla_due_at']))->not->toEqual(new DateTimeImmutable($poisoned));

    // Breached → red → 'breached'; the sla_at_risk view membership follows the row (open-family + state).
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['breached' => true]);
    $breached = inboxSeedEvent('sla.breached', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticketId, 'instance_id' => $instance->id,
        'stage' => 'first_response', 'due_at' => $poisoned, 'late_fired' => true,
    ]);
    runListProjector($breached);
    expect(listItemState($ticketId)['sla_state'])->toBe('breached');
});

it('P-CHIP: the CLEAR — a staff reply clears first_response in truth and the projector\'s own message.sent path re-reads it (resolution stays live and green → state null)', function (): void {
    [$tenant, $ticket, $instance] = timerScene(600);
    $ticketId = $ticket->identifier();
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['warned' => true]);
    app(ListProjector::class)->recomputeTicket($ticketId);
    expect(listItemState($ticketId)['sla_state'])->toBe('warned');

    // The reply, through Doc 04's door (a real message row), then the SLA truth clears the stage.
    registerFakeChannel();
    $conversation = App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));
    $message = app(App\Domain\Conversations\Outbound\ComposeOutbound::class)->compose($conversation, 'system', null, 'text', 'first response lands', 'pchip-clear');
    app(StageLifecycle::class)->onStaffReply($ticketId);
    $resolutionTarget = (string) DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->value('target_at');

    $sent = inboxSeedEvent('message.sent', ['tenant_id' => $tenant->identifier(), 'message_id' => $message->identifier(), 'conversation_id' => $conversation->identifier()]);
    runListProjector($sent);
    $row = listItemState($ticketId);
    expect($row['sla_state'])->toBeNull('first_response cleared in truth but the row kept its warned state')
        ->and(new DateTimeImmutable((string) $row['sla_due_at']))->toEqual(new DateTimeImmutable($resolutionTarget), 'the countdown should now be the resolution stage\'s');
});

it('P-CHIP: a ticket WITHOUT an SLA instance keeps its row\'s sla columns untouched on recompute (the seeders\' hand-paint survives until the fidelity session)', function (): void {
    [, , $ticket] = ticketFixture('open');
    $ticketId = $ticket->identifier();
    $projector = app(ListProjector::class);
    $projector->recomputeTicket($ticketId);
    // The hand-paint (what the browser seeders do), as the migrator-free app write the grant allows.
    DB::table('ticket_list_items')->where('ticket_id', $ticketId)->update(['sla_state' => 'breached', 'sla_due_at' => DB::raw("clock_timestamp() - interval '12 minutes'")]);
    $projector->recomputeTicket($ticketId);
    expect(listItemState($ticketId)['sla_state'])->toBe('breached', 'a no-instance recompute must not repaint the seam columns');
});
