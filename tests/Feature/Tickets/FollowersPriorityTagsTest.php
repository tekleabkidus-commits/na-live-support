<?php

declare(strict_types=1);

use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Followers\TicketFollowers;
use App\Domain\Tickets\Priority\ChangePriority;
use App\Domain\Tickets\Tags\TagRewrite;
use App\Domain\Tickets\Tags\TicketTags;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-17 / FR-18 / FR-19 / T-07 — priority, followers, tags
|--------------------------------------------------------------------------
|
| Grouped because they share a shape: each is a small write with a permission, an
| event and an audit entry, and each has one clause that is easy to get subtly wrong
| (a no-op that should not event; a follower who cannot see the ticket; a tag rewrite
| that must be resumable and must not double-apply).
*/

/** @return array{0: App\Models\Ticket, 1: App\Models\User} */
function propertyFixture(array $permissions): array
{
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));

    return [$ticket, ticketActor($permissions, 'Fixture Property '.implode(',', $permissions))];
}

// ── FR-17 priority ───────────────────────────────────────────────────────────

test('FR-17: a priority change is evented with old/new; a no-op change is NOT', function () {
    [$ticket, $agent] = propertyFixture(['tickets.priority.change', 'tickets.view']);

    app(ChangePriority::class)->change($ticket, 'urgent', $agent);

    $event = DB::table('outbox')->where('name', 'ticket.priority_changed')->first();
    $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['old_priority'])->toBe('normal')
        ->and($payload['new_priority'])->toBe('urgent')
        ->and($ticket->fresh()->priority)->toBe('urgent');

    // Setting the same value again emits nothing: an SLA consumer that re-targets on
    // every event would otherwise thrash a target for no reason (FR-17 → Doc 12).
    app(ChangePriority::class)->change($ticket->fresh(), 'urgent', $agent);
    expect(DB::table('outbox')->where('name', 'ticket.priority_changed')->count())->toBe(1);
});

test('FR-17: staff need tickets.priority.change; automation and AI need no user', function () {
    [$ticket, $agent] = propertyFixture(['tickets.view']);

    expect(fn () => app(ChangePriority::class)->change($ticket, 'high', $agent))
        ->toThrow(DomainException::class, 'tickets.priority.change');

    // FR-17's other two sources: an AI suggestion at handoff and an automation rule.
    $byAutomation = app(ChangePriority::class)->change($ticket->fresh(), 'high', null);
    expect($byAutomation->priority)->toBe('high');

    $event = DB::table('outbox')->where('name', 'ticket.priority_changed')->first();
    $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['actor_type'])->toBe('system')->and($payload['actor_id'])->toBeNull();
});

test('FR-17: a fifth priority is refused, by the enum and by the database', function () {
    [$ticket, $agent] = propertyFixture(['tickets.priority.change', 'tickets.view']);

    expect(fn () => app(ChangePriority::class)->change($ticket, 'critical', $agent))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => DB::transaction(fn () => DB::statement(
        "UPDATE tickets SET priority = 'critical' WHERE id = ?", [$ticket->identifier()],
    )))->toThrow(Illuminate\Database\QueryException::class);
});

// ── FR-18 followers ──────────────────────────────────────────────────────────

test('FR-18: staff follow themselves with tickets.follow; adding someone else is an assigner\'s act', function () {
    [$ticket, $self] = propertyFixture(['tickets.follow', 'tickets.view']);

    expect(app(TicketFollowers::class)->follow($ticket, $self))->toBeTrue()
        ->and(app(TicketFollowers::class)->followerIds($ticket))->toBe([$self->identifier()]);

    // Following twice is already true, not an error.
    expect(app(TicketFollowers::class)->follow($ticket, $self))->toBeFalse();

    // Adding ANOTHER person needs tickets.assign (mapping A-9) — tickets.follow is
    // only ever about yourself.
    $colleague = ticketActor(['tickets.view'], 'Fixture Colleague');
    expect(fn () => app(TicketFollowers::class)->addFollower($ticket, $colleague, $self))
        ->toThrow(DomainException::class, 'tickets.assign');

    $assigner = ticketActor(['tickets.assign', 'tickets.view'], 'Fixture Assigner');
    expect(app(TicketFollowers::class)->addFollower($ticket->fresh(), $colleague, $assigner))->toBeTrue();
    expect(app(TicketFollowers::class)->followerIds($ticket))->toHaveCount(2);
});

test('FR-18: a follower who cannot see the ticket is refused — notifications about invisible work', function () {
    [$ticket, $assigner] = propertyFixture(['tickets.assign', 'tickets.view']);
    $blind = ticketActor(['conversations.view'], 'Fixture Blind Follower');

    expect(fn () => app(TicketFollowers::class)->addFollower($ticket, $blind, $assigner))
        ->toThrow(AssigneeOutOfScope::class);
});

test('FR-18: unfollowing removes the row and events it; the events are the history', function () {
    [$ticket, $self] = propertyFixture(['tickets.follow', 'tickets.view']);

    app(TicketFollowers::class)->follow($ticket, $self);
    expect(app(TicketFollowers::class)->unfollow($ticket, $self))->toBeTrue()
        ->and(app(TicketFollowers::class)->followerIds($ticket))->toBe([]);

    // Unfollowing when not following is false, not an error.
    expect(app(TicketFollowers::class)->unfollow($ticket, $self))->toBeFalse();

    expect(DB::table('outbox')->where('name', 'ticket.follower_added')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.follower_removed')->count())->toBe(1);
});

// ── FR-19 tags + T-07 rewrite ────────────────────────────────────────────────

test('FR-19: tags are free-form per tenant, de-duplicated, and removable', function () {
    [$ticket, $agent] = propertyFixture(['tickets.tags.manage', 'tickets.view']);
    $tags = app(TicketTags::class);

    $tags->add($ticket, ['deposits', 'urgent-review'], $agent);
    $tags->add($ticket->fresh(), ['deposits', 'vip'], $agent);   // deposits already there

    $stored = DB::table('tickets')->where('id', $ticket->identifier())->value('tags');
    expect($stored)->toContain('deposits')->toContain('urgent-review')->toContain('vip');
    expect(substr_count((string) $stored, 'deposits'))->toBe(1, 'the tag set is a SET');

    $tags->remove($ticket->fresh(), ['urgent-review'], $agent);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('tags'))
        ->not->toContain('urgent-review');

    // The TEXT is in the audit entry, never on the retained event stream (Q7).
    $audit = DB::table('audit_log')->where('action_key', 'ticket.tagged')->first();
    $auditPayload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($auditPayload['tags'])->toContain('deposits');

    $event = DB::table('outbox')->where('name', 'ticket.tagged')->first();
    $eventPayload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($eventPayload)->not->toHaveKey('tags')->and($eventPayload['count'])->toBe(2);
});

test('FR-19: tagging needs tickets.tags.manage, and a blank tag is not a tag', function () {
    [$ticket, $agent] = propertyFixture(['tickets.view']);
    expect(fn () => app(TicketTags::class)->add($ticket, ['x'], $agent))
        ->toThrow(DomainException::class, 'tickets.tags.manage');

    [$other, $tagger] = propertyFixture(['tickets.tags.manage', 'tickets.view']);
    expect(fn () => app(TicketTags::class)->add($other, ['   '], $tagger))
        ->toThrow(InvalidArgumentException::class);
});

test('FR-19: autocomplete offers the tenant\'s own tags, prefix-matched', function () {
    [$ticket, $agent] = propertyFixture(['tickets.tags.manage', 'tickets.view']);
    app(TicketTags::class)->add($ticket, ['deposits', 'deposit-delay', 'withdrawals'], $agent);

    $suggestions = app(TicketTags::class)->suggest('depo');

    expect($suggestions)->toContain('deposits')->toContain('deposit-delay')
        ->and($suggestions)->not->toContain('withdrawals');
});

test('T-07: the rename is chunked, resumable, historical, and audited with counts', function () {
    // ONE tenant: creationFixture() rebinds, so the agent must be created AFTER the
    // first call and every later conversation must join the same tenant.
    [$tenant] = creationFixture();
    $tenantId = $tenant->identifier();
    $agent = ticketActor(['tickets.tags.manage', 'tickets.view'], 'Fixture Rewriter');
    $tags = app(TicketTags::class);
    $creator = app(CreateTicket::class);

    // Seven tickets carrying the old tag, so a chunk size of 2 forces four passes and
    // the loop's resumability is exercised rather than asserted.
    $ids = [];
    foreach (range(1, 7) as $i) {
        $c = anotherConversation($tenantId, "t07-{$i}-");
        $t = $creator->create(new NewTicket($c, TicketSource::Agent));
        $tags->add($t, ['deposit-issue'], $agent);
        $ids[] = $t->identifier();
    }

    // One ticket ALREADY carries the target — renaming into it is the MERGE case, and
    // the row must not end up with the tag twice.
    $mergeConversation = anotherConversation($tenantId, 't07-merge-');
    $mergeTarget = $creator->create(new NewTicket($mergeConversation, TicketSource::Agent));
    $tags->add($mergeTarget, ['deposit-issue', 'deposits'], $agent);

    $rewritten = app(TagRewrite::class)->rename('deposit-issue', 'deposits', $agent, chunkSize: 2);

    expect($rewritten)->toBe(8, 'the rewrite did not cover every historical ticket (FR-19: propagates historically)');

    foreach ([...$ids, $mergeTarget->identifier()] as $id) {
        $stored = (string) DB::table('tickets')->where('id', $id)->value('tags');
        expect($stored)->toContain('deposits')->not->toContain('deposit-issue');
        expect(substr_count($stored, 'deposits'))->toBe(1, 'the merge case duplicated the target tag');
    }

    // "audited with counts" — the count IS the record, because a historical rewrite's
    // blast radius is invisible any other way.
    $audit = DB::table('audit_log')->where('action_key', 'ticket.tags_rewritten')->first();
    $payload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['from_tag'])->toBe('deposit-issue')
        ->and($payload['to_tag'])->toBe('deposits')
        ->and($payload['tickets_rewritten'])->toBe(8);

    // Re-running is a no-op: no row still carries the old tag, so nothing is selected.
    // That is what makes a killed run safe to simply call again (T-07 resumable).
    expect(app(TagRewrite::class)->rename('deposit-issue', 'deposits', $agent))->toBe(0);
});

test('T-07: the rewrite needs tickets.tags.manage and refuses a blank rename', function () {
    creationFixture();   // binds a tenant; users cannot be queried without one (T-05)
    $noPermission = ticketActor(['tickets.view'], 'Fixture No Tag Admin');
    expect(fn () => app(TagRewrite::class)->rename('a', 'b', $noPermission))
        ->toThrow(DomainException::class, 'tickets.tags.manage');

    $agent = ticketActor(['tickets.tags.manage', 'tickets.view'], 'Fixture Rewriter 2');
    expect(fn () => app(TagRewrite::class)->rename('', 'b', $agent))->toThrow(DomainException::class);
    expect(app(TagRewrite::class)->rename('same', 'same', $agent))->toBe(0);
});
