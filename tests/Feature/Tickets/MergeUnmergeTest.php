<?php

declare(strict_types=1);

use App\Domain\Tickets\Merge\MergeChain;
use App\Domain\Tickets\Merge\MergeTickets;
use App\Domain\Tickets\Merge\UnmergeTicket;
use App\Domain\Tickets\Reopen\ReopenTicket;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-04 — merge / unmerge (step 6)
|--------------------------------------------------------------------------
|
| "AC-04 Merge: preview → confirm → 24 h unmerge restores byte-identical prior state
| (snapshot diff = ∅); chains resolve transitively."
|
| The byte-identical bar is proven by construction: MergeTickets::snapshotShape() is
| applied twice — at merge time (stored in ticket_merge_snapshots) and after unmerge
| (recomputed live) — and the two arrays must be IDENTICAL. Sabotage #3 (skip the
| snapshot write) turns every restoring test here red; sabotage #8 (drop the
| cross-customer guard) turns the SEC-06 test red.
|
*/

/** One tenant, one customer, two machine-walked tickets in separate conversations. */
function mergePair(string $sourceStatus = 'open'): array
{
    [$tenant, $conversation, $fixtureTicket] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    // The fixture ticket occupies its conversation's slot; merge fixtures get their
    // own conversations, sharing ONE customer (SEC-06's precondition).
    $customerId = $conversation->customer_id;
    $sourceConv = anotherConversation($tenantId, 'merge-src', $customerId);
    $targetConv = anotherConversation($tenantId, 'merge-tgt', $customerId);

    $source = machineClosedTicket($sourceConv, $sourceStatus);
    $target = machineClosedTicket($targetConv, 'open');

    $actor = ticketActor(['tickets.merge', 'tickets.view', 'tickets.reopen'], 'Fixture Merge Role');

    return [$tenant, $source, $target, $actor, $sourceConv, $targetConv, $fixtureTicket];
}

/**
 * jsonb canonicalizes key order, so a stored snapshot and a PHP-built shape can only
 * be compared strictly after both are key-sorted the same way. Values stay untouched —
 * the byte-identical bar is about CONTENT, and loose toEqual would let null == ''
 * slide, which is exactly the kind of drift AC-04 exists to catch.
 */
function canonical(array $shape): array
{
    foreach ($shape as &$value) {
        if (is_array($value)) {
            $value = canonical($value);
        }
    }
    unset($value);
    ksort($shape);

    return $shape;
}

/** A transcript anchor row on the given ticket (the shape FR-11 writes). */
function anchorOn(Ticket $ticket, Conversation $conversation): string
{
    $id = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('ticket_message_anchors')->insert([
        'id' => $id,
        'tenant_id' => $ticket->getAttribute('tenant_id'),
        'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'from_message_id' => $messageId,
        'to_message_id' => $messageId,
        'kind' => 'transcript_anchor',
    ]);

    return $id;
}

beforeEach(function (): void {
    Redis::connection('durable')->del(TicketTimers::ZSET);
});

// ── AC-04: the full round trip, byte-identical ───────────────────────────────

test('AC-04: merge moves links and unions sets; unmerge within 24 h restores byte-identical prior state — snapshot diff = ∅', function () {
    [$tenant, $source, $target, $actor, $sourceConv] = mergePair('open');

    // Pre-merge texture on BOTH sides, so the restore has something to prove.
    $follower = ticketActor(['tickets.view'], 'Fixture Follower Role');
    app(App\Domain\Tickets\Followers\TicketFollowers::class)->autoFollow($source, $follower);
    DB::update("UPDATE tickets SET tags = ARRAY['payments','urgent-review']::text[] WHERE id = ?", [$source->identifier()]);
    DB::update("UPDATE tickets SET tags = ARRAY['payments','vip']::text[] WHERE id = ?", [$target->identifier()]);
    Ticket::query()->whereKey($source->identifier())->update(['assignee_id' => $actor->identifier()]);
    $anchorId = anchorOn($source, $sourceConv);
    $source->refresh();
    $target->refresh();

    // The stored snapshot must equal the shape BEFORE any mutation.
    $expectedShape = app(MergeTickets::class)->snapshotShape($source, $target);

    app(MergeTickets::class)->merge([$source], $target, $actor);

    $source->refresh();
    $target->refresh();

    // FR-20's confirm outcomes.
    expect($source->status())->toBe(TicketStatus::Closed, 'the source must close superseded')
        ->and($source->merged_into)->toBe($target->identifier())
        ->and($source->closed_at)->not->toBeNull('the R-20 trigger stamps the operation door too')
        ->and(DB::table('ticket_message_anchors')->where('id', $anchorId)->value('ticket_id'))->toBe($target->identifier(), 'BR-02: the link moved')
        ->and(DB::table('ticket_followers')->where('ticket_id', $target->identifier())->where('user_id', $follower->identifier())->exists())->toBeTrue('followers union')
        ->and(app(App\Domain\Tickets\Tags\TicketTags::class)->tagsOf($target))->toContain('urgent-review')
        ->and($sourceConv->fresh()->open_ticket_id)->toBeNull('the source released its FR-10 slot');

    // A-13: status_changed + merged ride the bus; ticket.closed DOES NOT — a
    // superseded ticket has no reopen window to anchor.
    expect(DB::table('outbox')->where('name', 'ticket.merged')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.closed')->count())->toBe(0, 'A-13: a merge-close must not emit ticket.closed');

    $stored = DB::table('ticket_merge_snapshots')
        ->where('source_ticket_id', $source->identifier())
        ->where('target_ticket_id', $target->identifier())
        ->first();
    expect($stored)->not->toBeNull('T-04: the snapshot row must exist')
        ->and(canonical(json_decode((string) $stored->snapshot, true)))->toBe(canonical($expectedShape), 'the snapshot must capture the exact pre-merge shape');

    // THE REVERSAL (AC-04). Post-merge noise on the target proves the reset is real.
    DB::update("UPDATE tickets SET tags = ARRAY['payments','vip','merged-extra']::text[] WHERE id = ?", [$target->identifier()]);

    app(UnmergeTicket::class)->unmerge($source, $actor);

    $source->refresh();
    $target->refresh();

    expect($source->status())->toBe(TicketStatus::Open, 'the source must return to its pre-merge status')
        ->and($source->merged_into)->toBeNull()
        ->and($source->reopened_count)->toBe(0, 'A-13: an unmerge is not a reopen')
        ->and($sourceConv->fresh()->open_ticket_id)->toBe($source->identifier(), 'the FR-10 slot must be re-claimed');

    // THE AC-04 SENTENCE, LITERALLY: recompute the same extraction; diff = ∅.
    expect(app(MergeTickets::class)->snapshotShape($source, $target))
        ->toBe($expectedShape, 'AC-04: post-unmerge state differs from the pre-merge snapshot');

    expect(DB::table('outbox')->where('name', 'ticket.unmerged')->count())->toBe(1);
});

// ── A-13: the armed timer survives the round trip ────────────────────────────

test('a resolved source\'s armed timer is cancelled by the merge and restored EXACTLY by the unmerge (column and candidate)', function () {
    [$tenant, $source, $target, $actor] = mergePair('resolved');
    $member = $tenant->identifier().'|'.$source->identifier();

    $source->refresh();
    $armedDeadline = $source->auto_close_at;
    expect($armedDeadline)->not->toBeNull('fixture failed: resolve did not arm')
        ->and(ticketTimerScore($member))->not->toBeNull();

    app(MergeTickets::class)->merge([$source], $target, $actor);
    $source->refresh();

    expect($source->auto_close_at)->toBeNull('a superseded ticket must not auto-close')
        ->and(ticketTimerScore($member))->toBeNull('the candidate must be reaped with the column');

    app(UnmergeTicket::class)->unmerge($source, $actor);
    $source->refresh();

    expect($source->status())->toBe(TicketStatus::Resolved)
        ->and($source->auto_close_at?->format('Uu'))->toBe($armedDeadline->format('Uu'), 'the ORIGINAL deadline must return — never re-derived (SS-03)')
        ->and(ticketTimerScore($member))->toBe((int) $armedDeadline->format('Uu'));
});

// ── SEC-06: the hard rule (sabotage #8's target) ─────────────────────────────

test('SEC-06: a cross-customer merge is refused server-side with cross_customer_merge, and nothing mutates', function () {
    [$tenant, , $target, $actor] = mergePair('open');

    // A source belonging to a DIFFERENT customer (anotherConversation's default).
    $foreignConv = anotherConversation($tenant->identifier(), 'foreign');
    $foreignSource = machineClosedTicket($foreignConv, 'open');

    expect(fn () => app(MergeTickets::class)->merge([$foreignSource], $target, $actor))
        ->toThrow(TransitionRefused::class, 'cross_customer_merge');

    $foreignSource->refresh();
    expect($foreignSource->status())->toBe(TicketStatus::Open, 'a refused merge must not close the source')
        ->and($foreignSource->merged_into)->toBeNull()
        ->and(DB::table('ticket_merge_snapshots')->count())->toBe(0, 'a refused merge must not snapshot')
        ->and(DB::table('outbox')->where('name', 'ticket.merged')->count())->toBe(0);
});

// ── SEC-01: the actor's scope is re-checked on the TARGET ────────────────────

test('SEC-01: an actor who cannot see the merge target is refused target_out_of_scope', function () {
    [, $source, $target] = mergePair('open');

    // tickets.merge but NO tickets.view: the blindest possible actor.
    $blind = ticketActor(['tickets.merge'], 'Fixture Blind Merge Role');

    expect(fn () => app(MergeTickets::class)->merge([$source], $target, $blind))
        ->toThrow(TransitionRefused::class, 'target_out_of_scope');

    expect($source->fresh()->merged_into)->toBeNull();
});

// ── A-13: source/target admission rules ──────────────────────────────────────

test('A-13: a closed ticket may be a merge TARGET but never a source; a spam target is refused; a cycle is refused', function () {
    [$tenant, $source, $target, $actor] = mergePair('open');
    $customerId = $source->customer_id;

    // Closed SOURCE → refused (FR-07: immutable except as a target).
    $closedConv = anotherConversation($tenant->identifier(), 'closed-src', $customerId);
    $closedTicket = machineClosedTicket($closedConv, 'closed');
    expect(fn () => app(MergeTickets::class)->merge([$closedTicket], $target, $actor))
        ->toThrow(TransitionRefused::class, 'merge_source_closed');

    // Closed TARGET → sanctioned explicitly.
    app(MergeTickets::class)->merge([$source], $closedTicket, $actor);
    expect($source->fresh()->merged_into)->toBe($closedTicket->identifier(), 'FR-07: "serving as a merge target" is legal for closed');

    // Cycle: the chain's head merged back into its own tail.
    $third = machineClosedTicket(anotherConversation($tenant->identifier(), 'cycle', $customerId), 'open');
    expect(fn () => app(MergeTickets::class)->merge([$closedTicket], $source, $actor))
        ->toThrow(TransitionRefused::class, 'merge_cycle');

    // Spam target → refused. (Inserted directly in THIS tenant — a second
    // ticketFixture() call would rebind the context, the Pest.php warning.)
    $spamConv = anotherConversation($tenant->identifier(), 'spam-tgt', $customerId);
    $spamId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $spamId,
        'tenant_id' => $tenant->identifier(),
        'conversation_id' => $spamConv->identifier(),
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'spam',
        'priority' => 'normal',
        'source' => 'agent',
    ]);
    /** @var Ticket $spamTicket */
    $spamTicket = Ticket::query()->findOrFail($spamId);
    expect(fn () => app(MergeTickets::class)->merge([$third], $spamTicket, $actor))
        ->toThrow(TransitionRefused::class, 'merge_target_spam');
});

// ── A-14: the merged source is FROZEN, and out of FR-08's ladders ────────────

test('A-14: a merged source refuses every ordinary door (merged_superseded) and the reopen consumer never picks it', function () {
    [$tenant, $source, $target, $actor, $sourceConv] = mergePair('open');
    app(MergeTickets::class)->merge([$source], $target, $actor);
    $source->refresh();

    // The staff reopen door — legal closed→open in the matrix — is frozen shut.
    expect(fn () => app(ReopenTicket::class)->reopenByStaff($source, $actor, 'trying to reopen a superseded ticket'))
        ->toThrow(TransitionRefused::class, 'merged_superseded');

    // FR-08's customer door: a reply within 7 d of closed_at would reopen any
    // ordinarily-closed ticket; the merged one is excluded from the ladder.
    $messageId = reopenCustomerMessage($tenant->identifier(), $sourceConv->identifier());
    runReopenConsumer(reopenSeedEvent($sourceConv, $messageId));

    $source->refresh();
    expect($source->status())->toBe(TicketStatus::Closed, 'A-14: the consumer must not reopen a merged source')
        ->and($source->reopened_count)->toBe(0)
        ->and(Ticket::query()->where('conversation_id', $sourceConv->identifier())->count())->toBe(1, 'no successor either — the survivor owns the follow-up')
        ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(0);
});

// ── EC-03: chains resolve transitively; unmerge is per-link ──────────────────

test('EC-03: merged_into chains resolve to the final survivor, and a mid-chain unmerge re-points resolution per-link', function () {
    [$tenant, $a, $b, $actor] = mergePair('open');
    $customerId = $a->customer_id;
    $c = machineClosedTicket(anotherConversation($tenant->identifier(), 'chain-c', $customerId), 'open');

    // A → B, then B → C (the "target later merged" case EC-03 names).
    app(MergeTickets::class)->merge([$a], $b, $actor);
    $b->refresh();
    app(MergeTickets::class)->merge([$b], $c, $actor);

    $a->refresh();
    $b->refresh();
    expect(app(MergeChain::class)->survivor($a)->identifier())->toBe($c->identifier(), 'the banner must link the FINAL survivor')
        ->and(app(MergeChain::class)->survivor($b)->identifier())->toBe($c->identifier());

    // Unmerge the B→C link within ITS OWN window: B is restored; A's chain now
    // resolves to B. The A→B link is untouched — per-link, exactly as EC-03 says.
    app(UnmergeTicket::class)->unmerge($b, $actor);

    $a->refresh();
    $b->refresh();
    expect($b->merged_into)->toBeNull()
        ->and($b->status())->toBe(TicketStatus::Open)
        ->and($a->merged_into)->toBe($b->identifier(), 'the A→B link must survive the B→C unmerge')
        ->and(app(MergeChain::class)->survivor($a)->identifier())->toBe($b->identifier());
});

// ── FS-04: snapshot presence is the authority; both attempts audited ─────────

test('FS-04: once the snapshot is pruned, unmerge refuses with unmerge_window_passed and the refusal is audited', function () {
    [$tenant, $source, $target, $actor] = mergePair('open');
    $tenantId = $tenant->identifier();
    app(MergeTickets::class)->merge([$source], $target, $actor);
    $source->refresh();

    commitFixtures();

    try {
        // Age the snapshot past its window (only the platform role can touch the
        // R-19b created_at), then run the REAL pruner — platform-side, exactly as
        // the scheduler runs it (PlatformConnection refuses a bound tenant).
        DB::connection('pgsql_platform')->update(
            "UPDATE ticket_merge_snapshots SET created_at = created_at - interval '25 hours' WHERE source_ticket_id = ?",
            [$source->identifier()],
        );
        $exit = asPlatformWorker(fn (): int => Illuminate\Support\Facades\Artisan::call('tickets:merge-snapshots-prune'));
        expect($exit)->toBe(0);

        asTenant($tenant, function () use ($source, $target, $actor): void {
            expect(DB::table('ticket_merge_snapshots')->where('source_ticket_id', $source->identifier())->count())
                ->toBe(0, 'T-04: the pruner must remove the expired snapshot');

            expect(fn () => app(UnmergeTicket::class)->unmerge($source->fresh(), $actor))
                ->toThrow(TransitionRefused::class, 'unmerge_window_passed');

            // "both attempts audited": the refusal is on the record with its reason.
            $refused = DB::table('audit_log')
                ->where('action_key', 'ticket.unmerge_refused')
                ->where('entity_id', $source->identifier())
                ->first();
            expect($refused)->not->toBeNull('FS-04: the refused attempt must be audited');

            // The merge is now permanent: the source stays frozen behind its banner.
            expect($source->fresh()->merged_into)->toBe($target->identifier());
        });
    } finally {
        cleanupTenants([$tenantId]);
    }
});

// ── A-13: the unmerge open-slot conflict ─────────────────────────────────────

test('A-13: unmerge refuses open_ticket_exists when the source conversation grew a new open ticket during the window', function () {
    [, $source, $target, $actor, $sourceConv] = mergePair('open');
    app(MergeTickets::class)->merge([$source], $target, $actor);
    $source->refresh();

    // The slot the merge released is legitimately re-taken by newer work.
    $newer = machineClosedTicket($sourceConv->fresh(), 'open');

    expect(fn () => app(UnmergeTicket::class)->unmerge($source, $actor))
        ->toThrow(App\Domain\Conversations\Storage\OpenTicketExists::class);

    $source->refresh();
    expect($source->status())->toBe(TicketStatus::Closed, 'the rolled-back unmerge must leave the source merged')
        ->and($source->merged_into)->toBe($target->identifier())
        ->and($newer->fresh()->status())->toBe(TicketStatus::Open, 'the newer ticket keeps the slot');
});

// ── A-13: restore, then the EC-06 reconcile ──────────────────────────────────

test('A-13: an assignee deactivated while the source sat merged is handed back on unmerge, separately evented', function () {
    [, $source, $target, $actor] = mergePair('open');

    $leaver = ticketActor(['tickets.view'], 'Fixture Leaver Role');
    Ticket::query()->whereKey($source->identifier())->update(['assignee_id' => $leaver->identifier()]);
    $source->refresh();

    app(MergeTickets::class)->merge([$source], $target, $actor);

    // Deactivated DURING the merged interval — EC-06's sweep only walks open-family
    // tickets, so nothing has caught this one.
    $leaver->forceFill(['disabled_at' => Carbon\CarbonImmutable::parse('2026-08-08T00:00:00Z')])->save();

    app(UnmergeTicket::class)->unmerge($source->fresh(), $actor);

    $source->refresh();
    expect($source->status())->toBe(TicketStatus::Open)
        ->and($source->assignee_id)->toBeNull('a deactivated assignee must not silently keep restored work')
        ->and(DB::table('outbox')->where('name', 'ticket.assignee_deactivated')->count())->toBe(1);
});

// ── The plain not-merged refusal ─────────────────────────────────────────────

test('unmerging a ticket that is not merged refuses with not_merged', function () {
    [, $source, , $actor] = mergePair('open');

    expect(fn () => app(UnmergeTicket::class)->unmerge($source, $actor))
        ->toThrow(TransitionRefused::class, 'not_merged');
});
