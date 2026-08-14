<?php

declare(strict_types=1);

use App\Domain\Customers\Merge\CustomerMerge;
use App\Domain\Customers\Merge\UnmergeCustomer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-13 / AC-03 / EC-04 — the 30-day undo: byte-identical pointers
| for pre-merge objects; post-merge objects stay AND are listed in the
| preview; chains outermost-first. SABOTAGE #6 (snapshot map incomplete)
| turns the round-trip red.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('AC-03: the round-trip restores BYTE-IDENTICAL pointers (snapshot diff = ∅ for pre-merge objects); post-merge objects STAY with the survivor and the preview LISTS them', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $survivor = enforcedCustomer($tenantId, '2026-05-01 10:00:00+00');
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $survivor)->update(['display_name' => 'Survivor S', 'tags' => '{vip}']);
    DB::table('customers')->where('id', $merged)->update(['display_name' => 'Merged M', 'language' => 'en', 'tags' => '{new-account}']);
    $actor = App\Models\User::factory()->create();

    $conversation = anchoredConversation($tenantId, $merged, 'tg-undo-m');
    $ticket = rollupTicket($tenantId, $conversation, $merged, 'closed');
    $identity = (string) new UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $identity, 'tenant_id' => $tenantId, 'customer_id' => $merged,
        'channel' => 'telegram', 'external_user_id' => 'tg-undo-m',
        'type' => 'telegram', 'value' => 'tg-undo-m', 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // The BYTE baseline: every merged-customer column, pre-merge.
    $mergedBefore = (array) DB::table('customers')->where('id', $merged)->first();
    $survivorBefore = (array) DB::table('customers')->where('id', $survivor)->first();

    $snapshotId = app(CustomerMerge::class)->execute(
        $survivor, $merged, ['display_name' => 'merged'], 'fixture: undo round-trip', $actor->identifier(),
    );

    // POST-merge arrivals on the survivor — these must STAY on undo.
    $postConversation = anchoredConversation($tenantId, $survivor, 'tg-undo-post');
    $postTicket = rollupTicket($tenantId, $postConversation, $survivor, 'closed');

    $preview = app(UnmergeCustomer::class)->preview($snapshotId);
    expect($preview['restores']['conversations'])->toBe(1)
        ->and($preview['restores']['tickets'])->toBe(1)
        ->and($preview['restores']['identities'])->toBe(1)
        ->and($preview['stays']['conversations'])->toBe(1)     // the post-merge arrival, LISTED
        ->and($preview['stays']['tickets'])->toBe(1)
        ->and($preview['undoable'])->toBeTrue();

    app(UnmergeCustomer::class)->undo($snapshotId, 'fixture: chose wrong', $actor->identifier());

    // Byte-identical pointers: pre-merge objects home again…
    expect(DB::table('conversations')->where('id', $conversation)->value('customer_id'))->toBe($merged)
        ->and(DB::table('tickets')->where('id', $ticket)->value('customer_id'))->toBe($merged)
        ->and(DB::table('customer_identities')->where('id', $identity)->value('customer_id'))->toBe($merged);

    // …the merged profile restored (tombstone lifted, fields back)…
    $mergedAfter = (array) DB::table('customers')->where('id', $merged)->first();
    foreach (['display_name', 'language', 'tags', 'merged_into_id', 'created_source'] as $column) {
        expect($mergedAfter[$column])->toBe($mergedBefore[$column]);
    }

    // …the survivor's chosen-field write reversed…
    $survivorAfter = (array) DB::table('customers')->where('id', $survivor)->first();
    foreach (['display_name', 'language', 'tags'] as $column) {
        expect($survivorAfter[$column])->toBe($survivorBefore[$column]);
    }

    // …and the post-merge objects STAYED.
    expect(DB::table('conversations')->where('id', $postConversation)->value('customer_id'))->toBe($survivor)
        ->and(DB::table('tickets')->where('id', $postTicket)->value('customer_id'))->toBe($survivor);

    expect(DB::table('outbox')->where('name', 'customer.unmerged')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.unmerged')->count())->toBe(1);
});

it('EC-04: chains undo OUTERMOST-FIRST — the inner link refuses while the survivor is a tombstone; separating the outer link releases it', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $a = enforcedCustomer($tenantId, '2026-04-01 10:00:00+00');
    $b = enforcedCustomer($tenantId, '2026-05-01 10:00:00+00');
    $c = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    $inner = app(CustomerMerge::class)->execute($b, $a, [], 'fixture: A into B', $actor->identifier());
    $outer = app(CustomerMerge::class)->execute($c, $b, [], 'fixture: B into C', $actor->identifier());

    expect(fn () => app(UnmergeCustomer::class)->undo($inner, 'fixture: wrong order', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'outermost');
    expect(app(UnmergeCustomer::class)->preview($inner)['blocked_by_chain'])->toBeTrue();

    app(UnmergeCustomer::class)->undo($outer, 'fixture: outer first', $actor->identifier());
    app(UnmergeCustomer::class)->undo($inner, 'fixture: now the inner', $actor->identifier());

    expect(DB::table('customers')->where('id', $a)->value('merged_into_id'))->toBeNull()
        ->and(DB::table('customers')->where('id', $b)->value('merged_into_id'))->toBeNull();
});

it('FR-13: past the 30-day window the merge is PERMANENT, and a reversed snapshot cannot reverse twice', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $survivor = enforcedCustomer($tenantId, '2026-05-01 10:00:00+00');
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    // A synthetic EXPIRED snapshot (expires_at is app-INSERTABLE by the
    // countersigned grant — only UPDATE is fenced to undone_at).
    $expired = (string) new UuidV7;
    DB::table('customer_merge_snapshots')->insert([
        'id' => $expired, 'tenant_id' => $tenantId, 'survivor_id' => $survivor, 'merged_id' => $merged,
        'payload' => json_encode(['moved' => ['conversations' => [], 'tickets' => [], 'notes' => [], 'identities' => []], 'linked_work_flagged' => [], 'unpinned_note' => null, 'survivor_before' => []]),
        'expires_at' => DB::raw("clock_timestamp() - interval '1 day'"),
    ]);

    expect(fn () => app(UnmergeCustomer::class)->undo($expired, 'fixture: too late', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'window has passed');

    // The already-reversed latch.
    $snapshotId = app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: real merge', $actor->identifier());
    app(UnmergeCustomer::class)->undo($snapshotId, 'fixture: undo once', $actor->identifier());
    expect(fn () => app(UnmergeCustomer::class)->undo($snapshotId, 'fixture: undo twice', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'already');
});
