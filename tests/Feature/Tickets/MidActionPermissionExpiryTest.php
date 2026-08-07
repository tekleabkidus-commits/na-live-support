<?php

declare(strict_types=1);

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 03 EC-02 — mid-action permission expiry, naming the granter
|--------------------------------------------------------------------------
|
| THE INHERITED ROW (7). Doc 03 shipped EC-02 as PARTIAL and said so honestly: the
| resolver-truth denial was proven, but "the mid-action UI state naming the granter
| ships with the ticket surfaces (Docs 04/06) that have actions to interrupt". Doc 04
| then verified it needed ticket ACTIONS specifically and carried it here, in its
| traceability row rather than silently. This file is where it closes: a real Doc 06
| transition, interrupted by a real expiry.
|
| THE SUBTLETY WORTH KEEPING. The live verdict cannot supply the attribution.
| CapabilityMapBuilder drops an override whose `expires_at` has passed BEFORE it
| resolves anything (`expires_at > now()`), so once the grant lapses the verdict is an
| ordinary role-level denial carrying no granter and no expiry. Enforcement is right —
| the answer is deny — but the agent halfway through escalating a ticket is told
| nothing, and a permission that evaporates silently reads as a bug.
|
| So the refusal looks the attribution up from the override ROW, which is still there
| because nothing hard-deletes at tenant scope (BR-02). Enforcement is untouched; only
| the explanation improves. Both halves are asserted below — that it DENIES, and that
| it says who granted it and when it lapsed.
*/

test('EC-02: a transition permitted by a temporary override is refused once it expires, naming the granter and the expiry', function () {
    [, , $ticket] = ticketFixture('open');

    $granter = ticketActor(['admin.overrides.manage'], 'Fixture Granter Role');
    // The agent's ROLE cannot escalate. Only the override ever could.
    $agent = ticketActor(['tickets.view'], 'Fixture No Escalate Role');

    $expiresAt = now()->subMinutes(5);   // already lapsed: the mid-action moment
    UserPermissionOverride::query()->create([
        'user_id' => $agent->identifier(),
        'permission' => 'tickets.escalate',
        'effect' => 'allow',
        'expires_at' => $expiresAt,
        'granted_by' => $granter->identifier(),
        'reason' => 'covering the payments desk over the weekend',
    ]);

    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($agent);

    try {
        app(TicketTransitionService::class)->transition(
            $ticket,
            TicketStatus::Escalated,
            TransitionContext::staff($agent, 'player disputes a withdrawal reversal'),
        );
        throw new RuntimeException('the expired override still permitted the transition — EC-02 is inverted');
    } catch (TransitionNotPermitted $refusal) {
        // It denies…
        expect($refusal->permission)->toBe('tickets.escalate');
        // …and it can say WHY, by WHOSE grant, and WHEN it lapsed.
        expect($refusal->grantedBy)->toBe($granter->identifier(),
            'the refusal does not name the granter — EC-02 asks for attribution, not a bare denial')
            ->and($refusal->expiresAt)->not->toBeNull()
            ->and($refusal->expiresAt->format('Y-m-d H:i'))->toBe($expiresAt->format('Y-m-d H:i'));
    }

    // Nothing moved.
    expect($ticket->fresh()->status())->toBe(TicketStatus::Open)
        ->and(DB::table('outbox')->where('name', 'ticket.status_changed')->count())->toBe(0);
});

test('EC-02: while the SAME override is live, the very same transition succeeds', function () {
    // The positive control. Without it, the test above would also pass against a
    // service that refused everything — the expiry would be proving nothing.
    [, , $ticket] = ticketFixture('open');

    $granter = ticketActor(['admin.overrides.manage'], 'Fixture Granter Role');
    $agent = ticketActor(['tickets.view'], 'Fixture No Escalate Role');

    UserPermissionOverride::query()->create([
        'user_id' => $agent->identifier(),
        'permission' => 'tickets.escalate',
        'effect' => 'allow',
        'expires_at' => now()->addHour(),   // live
        'granted_by' => $granter->identifier(),
        'reason' => 'covering the payments desk over the weekend',
    ]);

    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($agent);

    $result = app(TicketTransitionService::class)->transition(
        $ticket,
        TicketStatus::Escalated,
        TransitionContext::staff($agent, 'player disputes a withdrawal reversal'),
    );

    expect($result->changed)->toBeTrue()
        ->and($result->ticket->status())->toBe(TicketStatus::Escalated);
});

test('EC-02: a plain role denial carries no attribution — there is no granter to name', function () {
    // The other direction: attribution must not be invented for a user who simply
    // never held the key. "Denied by an expiry" and "never permitted" are different
    // facts and the refusal must not blur them.
    [, , $ticket] = ticketFixture('open');
    $agent = ticketActor(['tickets.view'], 'Fixture No Escalate Role');

    try {
        app(TicketTransitionService::class)->transition(
            $ticket,
            TicketStatus::Escalated,
            TransitionContext::staff($agent, 'player disputes a withdrawal reversal'),
        );
        throw new RuntimeException('a role that never held tickets.escalate was allowed to escalate');
    } catch (TransitionNotPermitted $refusal) {
        expect($refusal->permission)->toBe('tickets.escalate')
            ->and($refusal->grantedBy)->toBeNull()
            ->and($refusal->expiresAt)->toBeNull();
    }
});
