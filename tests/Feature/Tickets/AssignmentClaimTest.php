<?php

declare(strict_types=1);

use App\Domain\Tickets\Assignment\AlreadyClaimed;
use App\Domain\Tickets\Assignment\AssigneeDeactivated;
use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-15 / FR-16 / FR-05 / BR-03 / EC-06 — assignment and the claim race
|--------------------------------------------------------------------------
|
| US-01: "As an agent, I claim a ticket with one keystroke and know instantly if a
| colleague beat me to it." That second half is what FR-16's `already_claimed {by}`
| exists for, and it is why the loser's refusal must NAME the winner rather than being
| a bare failure — the UI swaps an avatar in place instead of raising a modal.
*/

/** @return array{0: Ticket, 1: User} a ticket and an agent who can see and claim it */
function assignmentFixture(array $permissions = ['tickets.view', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change']): array
{
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    $agent = ticketActor($permissions, 'Fixture Assign Role '.implode(',', $permissions));

    return [$ticket, $agent];
}

test('FR-16/US-01: the loser of a claim race is told WHO won, not just that they lost', function () {
    [$ticket, $first] = assignmentFixture();
    $second = ticketActor(['tickets.view', 'tickets.assign_self'], 'Fixture Second Claimer');

    app(AssignTicket::class)->claim($ticket, $first);

    // Same keystroke, a moment later. The guarded UPDATE matches nothing.
    try {
        app(AssignTicket::class)->claim($ticket->fresh(), $second);
        throw new RuntimeException('the second claim succeeded — first commit did not win (FR-16)');
    } catch (AlreadyClaimed $refusal) {
        expect($refusal->by)->toBe($first->identifier(),
            'the refusal does not name the winner, so the UI cannot swap an avatar and must apologise instead (US-01)')
            ->and(AlreadyClaimed::CODE)->toBe('already_claimed')
            ->and($refusal->ticketId)->toBe($ticket->identifier());
    }

    expect($ticket->fresh()->assignee_id)->toBe($first->identifier());
});

test('FR-16: the same person claiming twice is a double-click, not a race', function () {
    [$ticket, $agent] = assignmentFixture();

    app(AssignTicket::class)->claim($ticket, $agent);
    // EC-07's posture applied to assignment: idempotent, no error.
    $again = app(AssignTicket::class)->claim($ticket->fresh(), $agent);

    expect($again->assignee_id)->toBe($agent->identifier());
});

test('FR-05: the first assignment opens a `new` ticket, through the transition service', function () {
    [$ticket, $agent] = assignmentFixture();

    expect($ticket->status())->toBe(TicketStatus::New);

    $assigned = app(AssignTicket::class)->claim($ticket, $agent);

    expect($assigned->status())->toBe(TicketStatus::Open)
        // Through the service, so the transition's own event and audit entry exist —
        // an assignment that wrote status directly would leave neither.
        ->and(DB::table('outbox')->where('name', 'ticket.status_changed')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.assigned')->count())->toBe(1);
});

test('FR-15: an assignee who cannot see the ticket is refused with assignee_out_of_scope', function () {
    [$ticket, $assigner] = assignmentFixture();

    // A user whose role grants no ticket view at all.
    $blind = ticketActor(['conversations.view'], 'Fixture Blind Role');

    expect(fn () => app(AssignTicket::class)->assign($ticket, $blind, $assigner))
        ->toThrow(AssigneeOutOfScope::class, 'assignee_out_of_scope');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('FR-15: every change emits ticket.assigned {old, new, by} — including the unassign', function () {
    [$ticket, $agent] = assignmentFixture();

    app(AssignTicket::class)->claim($ticket, $agent);
    app(AssignTicket::class)->unassign($ticket->fresh(), $agent);

    $events = DB::table('outbox')->where('name', 'ticket.assigned')->orderBy('id')->get();
    expect($events)->toHaveCount(2);

    $claim = json_decode((string) $events[0]->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($claim['old_assignee_id'])->toBeNull()
        ->and($claim['new_assignee_id'])->toBe($agent->identifier())
        ->and($claim['by'])->toBe($agent->identifier());

    // FR-15: "unassign returns to queue (Doc 11)" — a null new assignee IS that,
    // and it is the event Doc 11 will consume.
    $unassign = json_decode((string) $events[1]->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($unassign['old_assignee_id'])->toBe($agent->identifier())
        ->and($unassign['new_assignee_id'])->toBeNull();

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('FR-05/BR-03/R-04: a reply IS a claim, and the per-tenant toggle turns it off', function () {
    [$ticket, $agent] = assignmentFixture();

    // Default on (SS-03): the replier picks the ticket up.
    expect(app(SettingsRepository::class)->get('tickets.implicit_claim'))->toBeTrue();
    expect(app(AssignTicket::class)->implicitClaimOnReply($ticket, $agent))->toBeTrue()
        ->and($ticket->fresh()->assignee_id)->toBe($agent->identifier());

    // R-04's toggle, for strict-dispatch teams: the reply still happens, the claim
    // does not.
    [$other, $otherAgent] = assignmentFixture();
    app(SettingsRepository::class)->set('tickets.implicit_claim', false);

    expect(app(AssignTicket::class)->implicitClaimOnReply($other, $otherAgent))->toBeFalse()
        ->and($other->fresh()->assignee_id)->toBeNull();
});

test('FR-05: an implicit claim never fails the reply it rode in on', function () {
    [$ticket, $first] = assignmentFixture();
    $second = ticketActor(['tickets.view', 'tickets.assign_self'], 'Fixture Second Replier');

    app(AssignTicket::class)->claim($ticket, $first);

    // Someone else replies to an already-claimed ticket. The claim cannot be made —
    // and that must be a quiet false, because the alternative is a reply that throws
    // after the customer's message was already composed.
    expect(app(AssignTicket::class)->implicitClaimOnReply($ticket->fresh(), $second))->toBeFalse()
        ->and($ticket->fresh()->assignee_id)->toBe($first->identifier());
});

test('EC-06: a deactivated assignee hands back every OPEN-family ticket, one event each', function () {
    // ONE tenant throughout: creationFixture() creates a new tenant and rebinds, so a
    // second call would make $leaver invisible under RLS. anotherConversation() adds
    // conversations to the tenant already bound.
    [$tenant, $conversation] = creationFixture();
    $tenantId = $tenant->identifier();
    $leaver = ticketActor(['tickets.view', 'tickets.assign_self', 'tickets.status.change'], 'Fixture Leaver');
    $creator = app(CreateTicket::class);
    $assigner = app(AssignTicket::class);

    // Three open-family tickets on three conversations, plus one resolved.
    $open = [];
    foreach (range(1, 3) as $i) {
        $c = anotherConversation($tenantId, "ec06-{$i}-");
        $t = $creator->create(new NewTicket($c, TicketSource::Agent));
        $assigner->claim($t, $leaver);
        $open[] = $t->identifier();
    }

    $resolvedTicket = $creator->create(new NewTicket($conversation, TicketSource::Agent));
    $assigner->claim($resolvedTicket, $leaver);
    app(App\Domain\Tickets\Transitions\TicketTransitionService::class)->transition(
        $resolvedTicket->fresh(),
        TicketStatus::Resolved,
        App\Domain\Tickets\Transitions\TransitionContext::staff($leaver),
    );

    $handedBack = app(AssigneeDeactivated::class)->handBack($leaver);

    sort($handedBack);
    sort($open);
    expect($handedBack)->toBe($open,
        'a resolved ticket was handed back — finished work does not need re-routing (EC-06)');

    // One event PER TICKET so Doc 11 routes each on its own merits.
    expect(DB::table('outbox')->where('name', 'ticket.assignee_deactivated')->count())->toBe(3);

    foreach ($open as $id) {
        expect(Ticket::query()->whereKey($id)->value('assignee_id'))->toBeNull();
    }

    // The resolved one keeps its assignee — nobody needs to pick it up.
    expect($resolvedTicket->fresh()->assignee_id)->toBe($leaver->identifier());
});
