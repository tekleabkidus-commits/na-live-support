<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Events\Conversations\RealtimePush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 07 T-02 (view-delta protocol v:1) + T-03 (the counting contract's
| server half) — S07 step 3
|--------------------------------------------------------------------------
*/

/** The RealtimePush frames captured for a view key's channel. */
function viewFrames(string $viewKey, string $event): array
{
    $hash = app(ViewHash::class)->forZsetKey($viewKey);
    $frames = [];
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use ($hash, $event, &$frames): bool {
        if (str_ends_with($push->channelName, '.view.'.$hash) && $push->eventName === $event) {
            $frames[] = $push->payload;
        }

        return true; // collect, never filter the assertion itself
    });

    return $frames;
}

it('T-02: membership changes publish v:1 deltas — add with integer-µs sort_key and row patch, patch on rescore, remove ONLY on real removal', function (): void {
    Event::fake([RealtimePush::class]);
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    reopenCustomerMessage($tenantId, $conversation->identifier());

    $eventId = inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $ticket->customer_id, 'source' => 'agent',
        'actor_type' => 'agent', 'actor_id' => null,
    ]);
    runListProjector($eventId);

    $deltas = viewFrames('unassigned', 'inbox.view_delta');
    expect($deltas)->toHaveCount(1);
    $delta = $deltas[0];
    expect($delta['v'])->toBe(1)
        ->and($delta['op'])->toBe('add')
        ->and($delta['id'])->toBe($ticket->identifier())
        ->and($delta['sort_key'])->toBeInt()
        ->and($delta['patch']['status'])->toBe('new')
        ->and($delta['patch']['snippet'])->toBe('fixture customer follow-up');

    // Redelivery (C-09): the member exists, so the op degrades to PATCH —
    // never a duplicate add.
    runListProjector($eventId);
    $deltas = viewFrames('unassigned', 'inbox.view_delta');
    expect($deltas)->toHaveCount(2)
        ->and($deltas[1]['op'])->toBe('patch');

    // A remove pushes exactly once — re-applying the leave publishes nothing
    // (ZREM returned 0 the second time; T-02 precision).
    DB::table('tickets')->where('id', $ticket->identifier())->update(['status' => 'resolved']);
    $resolvedEvent = inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_status' => 'new', 'new_status' => 'resolved',
        'actor_type' => 'agent', 'actor_id' => null,
    ]);
    runListProjector($resolvedEvent);
    runListProjector($resolvedEvent);

    $removes = array_values(array_filter(viewFrames('unassigned', 'inbox.view_delta'), fn (array $p): bool => $p['op'] === 'remove'));
    expect($removes)->toHaveCount(1, 'a redelivered leave published a second remove — T-02 precision is gone');
});

it('T-03: the pushed count IS the server ZCARD — three rapid deltas inside one window coalesce to ONE push whose value is the FULL count, never the batch size (sabotage #13 goes red here)', function (): void {
    Event::fake([RealtimePush::class]);
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $zsets = app(ViewZsets::class);

    $seed = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): string {
        return inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]);
    };

    // First delta: the window OPENS with a push carrying ZCARD=1.
    runListProjector($seed($ticket, $conversation));
    $counts = viewFrames('unassigned', 'inbox.view_count');
    expect($counts)->toHaveCount(1)
        ->and($counts[0]['count'])->toBe(1)
        ->and($counts[0]['v'])->toBe(1);

    // Two more deltas INSIDE the window: coalesced — no new push.
    $c2 = anotherConversation($tenantId, 'count-2');
    [$t2] = inboxTicketRow($tenantId, $c2, 'open');
    runListProjector($seed($t2, $c2));
    $c3 = anotherConversation($tenantId, 'count-3');
    [$t3] = inboxTicketRow($tenantId, $c3, 'open');
    runListProjector($seed($t3, $c3));
    expect(viewFrames('unassigned', 'inbox.view_count'))->toHaveCount(1, 'the debounce did not coalesce');

    // Window expires (deterministic: the throttle key is cleared, never a
    // sleep) → the next delta pushes the CURRENT FULL ZCARD (4), not the
    // number of deltas since the last push (3) and not the batch size (1).
    Redis::connection('volatile')->del(sprintf('t:%s:viewcount:throttle:%s', $tenantId, 'unassigned'));
    $c4 = anotherConversation($tenantId, 'count-4');
    [$t4] = inboxTicketRow($tenantId, $c4, 'open');
    runListProjector($seed($t4, $c4));

    $counts = viewFrames('unassigned', 'inbox.view_count');
    expect($counts)->toHaveCount(2)
        ->and($counts[1]['count'])->toBe(4, 'the pushed count is not the server ZCARD — T-03\'s contract (client never derives from a partial window) is broken at the source')
        ->and($counts[1]['count'])->toBe($zsets->count('unassigned'))
        ->and($counts[1]['count'])->toBe(SystemViews::directQuery('unassigned')->count());
});

it('SEC-06/32KB: the view-channel door refuses a foreign-tenant payload and an oversized frame — the platform rules every protocol door carries', function (): void {
    [$tenant] = ticketFixture('open');
    $pusher = app(App\Domain\Inbox\Realtime\ViewDeltaPusher::class);

    expect(fn () => $pusher->toViewKey('unassigned', 'inbox.view_delta', [
        'tenant_id' => (string) Illuminate\Support\Str::uuid7(),
    ]))->toThrow(DomainException::class, 'FOREIGN tenant');

    expect(fn () => $pusher->toViewKey('unassigned', 'inbox.view_delta', [
        'blob' => str_repeat('x', 33_000),
    ]))->toThrow(DomainException::class, '32 KB');
});
