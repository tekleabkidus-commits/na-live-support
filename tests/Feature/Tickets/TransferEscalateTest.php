<?php

declare(strict_types=1);

use App\Domain\Tickets\Escalation\EscalateTicket;
use App\Domain\Tickets\Escalation\EscalationPath;
use App\Domain\Tickets\Transfer\TransferTicket;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-22 / FR-23 / EC-05 — transfer + escalate (step 6)
|--------------------------------------------------------------------------
|
| Transfer's scope questions are answered at Doc 03's levels, and pre-Doc 11 the
| Team/Dept levels resolve to EmptyMembership — so retention and destination checks
| FAIL CLOSED for them, which is the step-4 posture and is asserted here as such,
| not worked around.
|
*/

/** A user whose role holds the given keys at the given tickets scope level. */
function scopedActor(array $permissions, string $ticketScope, string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => $permissions,
            'scopes' => ['tickets' => $ticketScope, 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'],
        ],
    );
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

// ── FR-22: transfer ──────────────────────────────────────────────────────────

test('FR-22: a transfer changes the department, keeps the status, retains an All-scope assignee, and emits ticket.transferred', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.department.change', 'tickets.view'], 'Fixture Transfer Role');
    $assignee = ticketActor(['tickets.view'], 'Fixture Assignee Role');   // All scope
    Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => $assignee->identifier()]);
    $ticket->refresh();

    $destination = departmentFixture('Fixture Transfer Target'); // S11 step 2b: a REAL department (tickets_department_fk)
    app(TransferTicket::class)->transfer($ticket, $destination, $actor);

    $ticket->refresh();
    expect($ticket->department_id)->toBe($destination)
        ->and($ticket->status())->toBe(TicketStatus::Open, 'FR-22: transfer never touches status')
        ->and($ticket->assignee_id)->toBe($assignee->identifier(), 'an All-scope assignee still sees the ticket — retained');

    $event = DB::table('outbox')->where('name', 'ticket.transferred')->first();
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['new_department_id'])->toBe($destination)
        ->and($payload['old_department_id'])->toBeNull()
        ->and($payload['assignee_retained'])->toBeTrue();

    expect(DB::table('audit_log')->where('action_key', 'ticket.transferred')->where('entity_id', $ticket->identifier())->count())->toBe(1);
});

test('FR-22: a Dept-scoped assignee FAILS CLOSED pre-Doc 11 — reset to the queue, ticket.assigned emitted with null', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.department.change', 'tickets.view'], 'Fixture Transfer Role');
    $deptScoped = scopedActor(['tickets.view'], 'Dept', 'Fixture Dept-Scoped Role');
    Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => $deptScoped->identifier()]);
    $ticket->refresh();

    app(TransferTicket::class)->transfer($ticket, departmentFixture('Fixture Transfer Target'), $actor); // S11 step 2b

    $ticket->refresh();
    expect($ticket->assignee_id)->toBeNull('EmptyMembership cannot contain the destination — the assignee must not silently keep invisible work');

    $assigned = DB::table('outbox')->where('name', 'ticket.assigned')->orderByDesc('id')->first();
    $payload = json_decode((string) $assigned->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['old_assignee_id'])->toBe($deptScoped->identifier())
        ->and($payload['new_assignee_id'])->toBeNull();

    $transferred = json_decode((string) DB::table('outbox')->where('name', 'ticket.transferred')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($transferred['assignee_retained'])->toBeFalse();
});

test('SEC-01: a Dept-scoped ACTOR cannot transfer into a department outside their (empty pre-Doc 11) membership', function () {
    [, , $ticket] = ticketFixture('open');
    $deptActor = scopedActor(['tickets.department.change', 'tickets.view'], 'Dept', 'Fixture Dept Actor Role');

    expect(fn () => app(TransferTicket::class)->transfer($ticket, departmentFixture('Fixture Transfer Target'), $deptActor)) // S11 step 2b
        ->toThrow(TransitionRefused::class, 'target_out_of_scope');

    expect($ticket->fresh()->department_id)->toBeNull();
});

// ── FR-23: escalate ──────────────────────────────────────────────────────────

test('FR-23: escalation writes the {path, reason, target, at, by} payload in DB time, transitions through the machine, and the assignee auto-follows', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.escalate', 'tickets.view'], 'Fixture Escalate Role');
    $assignee = ticketActor(['tickets.view'], 'Fixture Esc Assignee Role');
    Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => $assignee->identifier()]);
    $ticket->refresh();

    app(EscalateTicket::class)->escalate($ticket, $actor, EscalationPath::supervisorReview(), 'payment dispute needs supervisor judgement on the refund split');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Escalated);

    /** @var array<string, mixed> $escalation */
    $escalation = $ticket->escalation;
    expect($escalation['path'])->toBe('supervisor_review')
        ->and($escalation['reason'])->toContain('supervisor judgement')
        ->and($escalation['target'])->toBeNull()
        ->and($escalation['by'])->toBe($actor->identifier())
        ->and($escalation['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');

    // "original assignee auto-follows."
    expect(DB::table('ticket_followers')->where('ticket_id', $ticket->identifier())->where('user_id', $assignee->identifier())->exists())->toBeTrue();

    // SEC-03: the reason is on the event AND the audit record.
    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.escalated')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['path'])->toBe('supervisor_review')->and($event['reason'])->toContain('refund split');
    expect(DB::table('audit_log')->where('action_key', 'ticket.escalated')->where('entity_id', $ticket->identifier())->count())->toBe(1);
});

test('FR-23/BR-06: a short reason is refused by the edge (reason_too_short) and the escalation payload does not survive the rollback', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.escalate', 'tickets.view'], 'Fixture Escalate Role');

    expect(fn () => app(EscalateTicket::class)->escalate($ticket, $actor, EscalationPath::supervisorReview(), 'too short'))
        ->toThrow(TransitionRefused::class, 'reason_too_short');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->escalation)->toBeNull('the payload written before the refused transition must roll back with it');
});

test('FR-23: a named target who is disabled is refused escalation_target_invalid at escalate time', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.escalate', 'tickets.view'], 'Fixture Escalate Role');
    $gone = ticketActor(['tickets.view'], 'Fixture Gone Role');
    $gone->forceFill(['disabled_at' => Carbon\CarbonImmutable::parse('2026-08-01T00:00:00Z')])->save();

    expect(fn () => app(EscalateTicket::class)->escalate($ticket, $actor, EscalationPath::named($gone->identifier()), 'this reason is certainly long enough'))
        ->toThrow(TransitionRefused::class, 'escalation_target_invalid');

    expect($ticket->fresh()->status())->toBe(TicketStatus::Open);
});

test('FR-23: de-escalation returns to open with the actor\'s note, clears the payload, and emits ticket.deescalated', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.escalate', 'tickets.view'], 'Fixture Escalate Role');

    app(EscalateTicket::class)->escalate($ticket, $actor, EscalationPath::supervisorReview(), 'needs supervisor judgement on the refund split');
    $ticket->refresh();

    app(EscalateTicket::class)->deescalate($ticket, $actor, 'supervisor ruled: refund the stake, close normally');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->escalation)->toBeNull('the CURRENT escalation clears — history lives in the events');

    $note = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'note')->first();
    expect($note)->not->toBeNull('FR-23: the de-escalation note must exist')
        ->and($note->body)->toContain('supervisor ruled');

    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.deescalated')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['previous_path'])->toBe('supervisor_review');
});

// ── EC-05: the later-disabled named target ───────────────────────────────────

test('EC-05: deactivating a named target re-routes the escalation to supervisor_review with a system note and the escalator-notification event', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $escalator = ticketActor(['tickets.escalate', 'tickets.view'], 'Fixture Escalator Role');
    $named = ticketActor(['tickets.view'], 'Fixture Named Target Role');

    app(EscalateTicket::class)->escalate($ticket, $escalator, EscalationPath::named($named->identifier()), 'needs the payments specialist to rule on this');
    $ticket->refresh();
    expect($ticket->escalation['target'])->toBe($named->identifier());

    // LATER: the named target leaves. The deactivation seam runs the fallback.
    $named->forceFill(['disabled_at' => Carbon\CarbonImmutable::parse('2026-08-08T12:00:00Z')])->save();
    $rerouted = app(EscalateTicket::class)->onUserDeactivated($named);

    expect($rerouted)->toBe([$ticket->identifier()]);

    $ticket->refresh();
    /** @var array<string, mixed> $escalation */
    $escalation = $ticket->escalation;
    expect($ticket->status())->toBe(TicketStatus::Escalated, 'the ticket STAYS escalated — only the path re-routes')
        ->and($escalation['path'])->toBe('supervisor_review')
        ->and($escalation['target'])->toBeNull()
        ->and($escalation['fallback_from'])->toBe($named->identifier())
        // (toContain takes no message argument — the ledger's matcher lesson.)
        ->and($escalation['reason'])->toContain('payments specialist')
        ->and($escalation['by'])->toBe($escalator->identifier());

    // The system note in the thread, and A-15's event naming the escalator.
    $note = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('author_type', 'system')->where('kind', 'note')->first();
    expect($note)->not->toBeNull('EC-05: the fallback system note must exist');

    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.escalation_rerouted')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['from_target_user_id'])->toBe($named->identifier())
        ->and($event['notify_user_id'])->toBe($escalator->identifier(), 'EC-05: the ESCALATOR is who gets notified');

    // A user with no named escalations is a clean no-op.
    expect(app(EscalateTicket::class)->onUserDeactivated($escalator))->toBe([]);
});
