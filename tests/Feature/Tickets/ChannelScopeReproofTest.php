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
| Team/Dept are checked too: REFUSED without membership reach, ADMITTED with it — since S11
| step 3 (Packet 3, A-50) MembershipSource binds OrgMembership; the pre-Doc 11 "still
| refused" pin was INVERTED deliberately (the S20 precedent), its refusal half kept.
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

test('T-06 INVERTED (S11 step 3, Packet 3 — A-50; the S20 inversion precedent): Team and Dept scopes ADMIT with real membership reach and STAY REFUSED without it', function () {
    // INVERSION, deliberate and cited: until S11 step 3 this test pinned "Team/Dept stay
    // REFUSED until Doc 11 supplies membership". Doc 11 bound OrgMembership (A-50: Dept =
    // user_departments; Team = the team's queue reach onto department_id). The refusal
    // half is KEPT for an agent WITHOUT reach — no assertion weakened, one added.
    [$tenant] = creationFixture();
    $tenantId = $tenant->identifier();
    $department = departmentFixture('Reach Department');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $mover = ticketActor(['tickets.department.change', 'tickets.view'], 'Fixture Mover Role');

    foreach (['Team', 'Dept'] as $level) {
        $with = scopedAgent($level, $level.' With Reach');
        $without = scopedAgent($level, $level.' Without Reach');
        if ($level === 'Dept') {
            DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $with->identifier(), 'department_id' => $department, 'kind' => 'direct']);
        } else {
            $team = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $team, 'tenant_id' => $tenantId, 'name' => 'Reach team '.$level]);
            DB::table('queue_teams')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'team_id' => $team]);
            DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'team_id' => $team, 'user_id' => $with->identifier()]);
        }

        $conversation = anotherConversation($tenantId, strtolower($level).'-');
        $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
        app(App\Domain\Tickets\Transfer\TransferTicket::class)->transfer($ticket, $department, $mover);
        $ticket->refresh();

        // WITHOUT reach: still refused at FR-15's as-if-assigned check, and the channel stays closed.
        expect(fn () => app(AssignTicket::class)->claim($ticket, $without))
            ->toThrow(App\Domain\Tickets\Assignment\AssigneeOutOfScope::class);
        expect(app(ConversationChannelGate::class)->authorize($without, $conversation->identifier()))->toBeFalse(
            "{$level} scope admitted an agent with NO membership reach — the open-fall NFR-04 exists to catch",
        );

        // WITH reach: the claim lands and the channel admits.
        app(AssignTicket::class)->claim($ticket->fresh(), $with);
        // authorize() answers with the presence member info on ADMIT (array), false on refusal.
        expect(app(ConversationChannelGate::class)->authorize($with, $conversation->identifier()))->toBeArray(
            "{$level} scope with real membership reach was refused — the binding did not widen",
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
