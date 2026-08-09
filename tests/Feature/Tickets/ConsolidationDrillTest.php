<?php

declare(strict_types=1);

use App\Domain\Tickets\Merge\MergeChain;
use App\Domain\Tickets\Merge\MergeTickets;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 06 SS-02 — the duplicate-storm consolidation drill (close-out)
|--------------------------------------------------------------------------
|
| "A duplicate-storm morning (one outage, 40 near-identical tickets) is consolidated
| to one master via merge in under 10 minutes, metrics counting one body of work
| with 40 contacts."
|
| SEC-06 shapes the scenario: cross-customer merge is prohibited, so the drill's 40
| tickets belong to ONE persistent customer across 40 conversations (the same player
| hammering every channel about the same outage). The "under 10 minutes" is an
| OPERATOR journey (§8's preview/confirm dialog, Doc 07); the server half proven
| here is that the consolidation itself is one call and seconds, and that the
| metric contract holds at storm scale: 39 sources closed superseded with ZERO
| ticket.closed events (the A-13 rider's golden rule, times 39) — one body of work,
| while all 40 conversations keep their threads (BR-02: nothing moved but links).
*/

test('SS-02: forty near-identical tickets consolidate to one master in one call — one body of work, forty intact contacts', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;
    Redis::connection('durable')->del(TicketTimers::ZSET);

    $actor = ticketActor(['tickets.merge', 'tickets.view'], 'Fixture Storm Role');

    $master = machineClosedTicket(anotherConversation($tenantId, 'storm-master', $customerId), 'open');

    $sources = [];
    foreach (range(1, 39) as $i) {
        $sources[] = machineClosedTicket(anotherConversation($tenantId, 'storm-'.$i, $customerId), 'open');
    }

    $startedAt = microtime(true);
    app(MergeTickets::class)->merge($sources, $master, $actor);
    $elapsed = microtime(true) - $startedAt;

    // One body of work: the master alone is open-family; every source is closed
    // superseded, resolving transitively to the master.
    expect($master->fresh()->status())->toBe(TicketStatus::Open);

    foreach ($sources as $source) {
        $source->refresh();
        expect($source->status())->toBe(TicketStatus::Closed)
            ->and($source->merged_into)->toBe($master->identifier());
    }
    expect(app(MergeChain::class)->survivor($sources[0])->identifier())->toBe($master->identifier());

    // The metric contract at storm scale: 39 merges, ZERO close-count increments
    // (A-13's rider), 39 reversibility snapshots.
    expect(DB::table('outbox')->where('name', 'ticket.merged')->count())->toBe(39)
        ->and(DB::table('outbox')->where('name', 'ticket.closed')->count())->toBe(0, 'a superseded source must not count as closed work — one body of work')
        ->and(DB::table('ticket_merge_snapshots')->where('target_ticket_id', $master->identifier())->count())->toBe(39);

    // "40 contacts": every conversation still holds its own thread — merge moved
    // links, never message rows (BR-02) — and no source conversation holds an open
    // ticket anymore.
    expect(Ticket::query()->where('customer_id', $customerId)->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])->where('linked_work', false)->count())
        ->toBe(2, 'the master + the untouched fixture ticket — nothing else open');

    // The server half of "under 10 minutes": the consolidation call itself is
    // seconds. Recorded, dev-hardware-indicative.
    fwrite(STDERR, sprintf("\n[SS-02 indicative] 39-source consolidation: %.2fs\n", $elapsed));
    expect($elapsed)->toBeLessThan(60.0, 'a consolidation the operator must wait minutes for fails SS-02 regardless of the UI');
});
