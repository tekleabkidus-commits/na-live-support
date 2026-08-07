<?php

declare(strict_types=1);

use App\Domain\Conversations\Realtime\ConversationChannelGate;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\CapabilityCacheInvalidator;

/*
|--------------------------------------------------------------------------
| Doc 04 T-06 RE-PROVEN against real assignment columns (inherited row 2)
|--------------------------------------------------------------------------
|
| Doc 04 shipped the conversation channel gate FAIL-CLOSED and said so: the
| conversations table had no assignment columns, so any role scoped Own/Team/Dept
| resolved to an empty set and only `All` could subscribe. Its docblock promised
| "Doc 06 re-proves this gate against real assignment columns". This is that re-proof.
|
| BOTH DIRECTIONS ARE ASSERTED, and the deny half is the one that matters. The
| stand-in refused a scoped agent by refusing EVERY scoped agent; the real rule refuses
| them on the merits. Those two produce the same answer for an unassigned ticket, so
| only the ADMIT case proves anything changed — and only the DENY case proves nothing
| silently widened while it did.
|
| Team/Dept are checked too: they must STILL be refused, because MembershipSource binds
| to EmptyMembership until Doc 11 supplies real membership. A scope that started
| answering "yes" here before Doc 11 exists would be the open-fall Doc 03's NFR-04
| sabotage was written to catch.
*/

/** A role with a chosen ticket scope level, and a user holding it. */
function scopedAgent(string $ticketScope, string $label): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => "Fixture Scope {$label}"],
        [
            'color' => 'role-agent',
            'permissions' => ['tickets.view', 'tickets.assign_self', 'tickets.status.change', 'conversations.view'],
            'scopes' => ['tickets' => $ticketScope, 'conversations' => $ticketScope, 'customers' => $ticketScope, 'reports' => $ticketScope],
        ],
    );

    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

test('T-06 ADMIT: an Own-scoped agent may subscribe to a conversation they hold an assigned ticket on', function () {
    [, $conversation] = creationFixture();
    $agent = scopedAgent('Own', 'Own Admit');

    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    app(AssignTicket::class)->claim($ticket, $agent);

    $member = app(ConversationChannelGate::class)->authorize($agent, $conversation->identifier());

    // THIS IS THE HALF THAT WAS IMPOSSIBLE BEFORE DOC 06: under Doc 04's stand-in an
    // Own-scoped role could never subscribe, because there was no column that could
    // make a conversation "own".
    expect($member)->toBeArray()
        ->and($member['id'])->toBe($agent->identifier());
});

test('T-06 DENY: the same Own-scoped agent is refused on a conversation they hold nothing on', function () {
    // Two conversations, ONE tenant — a second creationFixture() would rebind and put
    // the first conversation out of RLS reach entirely, which is a different (and
    // uninteresting) reason for a refusal.
    [$tenant, $mine] = creationFixture();
    $theirs = anotherConversation($tenant->identifier(), 'deny-');
    $agent = scopedAgent('Own', 'Own Deny');

    $ticket = app(CreateTicket::class)->create(new NewTicket($mine, TicketSource::Agent));
    app(AssignTicket::class)->claim($ticket, $agent);

    // Someone else's conversation, with a ticket the agent does not hold.
    $foreign = app(CreateTicket::class)->create(new NewTicket($theirs, TicketSource::Agent));
    expect($foreign->assignee_id)->toBeNull();

    expect(app(ConversationChannelGate::class)->authorize($agent, $theirs->identifier()))->toBeFalse(
        'an Own-scoped agent was admitted to a conversation they hold no assigned ticket on — the '
        .'deny half WIDENED when Doc 06 replaced the fail-closed stand-in, which is exactly what '
        .'the stand-in existed to prevent',
    );

    // And the admit half still holds in the same test, so this is not passing because
    // the gate refuses everything.
    expect(app(ConversationChannelGate::class)->authorize($agent, $mine->identifier()))->toBeArray();
});

test('T-06 DENY: unassigning takes the subscription right away again', function () {
    [, $conversation] = creationFixture();
    $agent = scopedAgent('Own', 'Own Revoke');

    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    app(AssignTicket::class)->claim($ticket, $agent);
    expect(app(ConversationChannelGate::class)->authorize($agent, $conversation->identifier()))->toBeArray();

    // FR-15's unassign returns the ticket to the queue — and with it, the visibility.
    $assigner = ticketActor(['tickets.assign', 'tickets.view'], 'Fixture Unassigner');
    app(AssignTicket::class)->unassign($ticket->fresh(), $assigner);

    expect(app(ConversationChannelGate::class)->authorize($agent, $conversation->identifier()))->toBeFalse(
        'scope survived the unassign — the gate is caching a decision that must be live',
    );
});

test('T-06: Team and Dept scopes stay REFUSED until Doc 11 supplies membership', function () {
    // One conversation PER LEVEL, in one tenant: FR-10 allows a single open-family
    // ticket per conversation, so reusing one conversation would test T-02 rather
    // than the scope level.
    [$tenant] = creationFixture();
    $tenantId = $tenant->identifier();

    foreach (['Team', 'Dept'] as $level) {
        $agent = scopedAgent($level, $level.' Still Closed');
        $conversation = anotherConversation($tenantId, strtolower($level).'-');

        $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));

        // A Team/Dept-scoped role cannot even be ASSIGNED pre-Doc 11: the ticket's
        // department is not in their (empty) membership, so FR-15's as-if-assigned
        // check refuses. That refusal is itself the fail-closed behaviour under test.
        expect(fn () => app(AssignTicket::class)->claim($ticket, $agent))
            ->toThrow(App\Domain\Tickets\Assignment\AssigneeOutOfScope::class);

        expect(app(ConversationChannelGate::class)->authorize($agent, $conversation->identifier()))->toBeFalse(
            "{$level} scope started admitting before Doc 11 supplied membership — this is the open-fall "
            .'NFR-04 exists to catch',
        );
    }
});

test('T-06: an All-scoped agent still subscribes, and conversations.view is still required first', function () {
    [, $conversation] = creationFixture();

    $all = scopedAgent('All', 'All Access');
    expect(app(ConversationChannelGate::class)->authorize($all, $conversation->identifier()))->toBeArray();

    // The permission verdict runs BEFORE scope and refuses on its own.
    $noView = ticketActor(['tickets.view'], 'Fixture No Conversation View');
    expect(app(ConversationChannelGate::class)->authorize($noView, $conversation->identifier()))->toBeFalse();

    // C-10: an unknown conversation is refused identically to a foreign one.
    expect(app(ConversationChannelGate::class)->authorize($all, (string) Illuminate\Support\Str::uuid7()))->toBeFalse();
});
