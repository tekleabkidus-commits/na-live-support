<?php

declare(strict_types=1);

use App\Domain\Customers\Merge\CustomerMerge;
use App\Domain\Customers\Merge\MergeBlocked;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-12's open-family collision + EC-12's cap refusal (Doc 06
| FR-10/FR-21 consumed — the linked_work flag is a column write + the public
| system-note door; NO Doc 06 file edited, escalation #4 resolved to
| consumption)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** @return array{0: string, 1: string, 2: string} */
function collisionScene(): array
{
    $tenant = createTenant();
    $tenantId = $tenant->identifier();

    return [$tenantId, enforcedCustomer($tenantId, '2026-05-01 10:00:00+00'), enforcedCustomer($tenantId, '2026-06-01 10:00:00+00')];
}

it('FR-12: both sides open → the NEWER open ticket is auto-flagged linked_work with a system note; the oldest keeps the plain slot', function (): void {
    [$tenantId, $survivor, $merged] = collisionScene();
    $actor = App\Models\User::factory()->create();

    $cs = anchoredConversation($tenantId, $survivor, 'tg-col-s');
    $cm = anchoredConversation($tenantId, $merged, 'tg-col-m');
    $older = rollupTicket($tenantId, $cs, $survivor, 'open');
    sleep(0); // ordering by created_at; same-instant rows tiebreak by insert order
    $newer = rollupTicket($tenantId, $cm, $merged, 'open');

    app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: collision', $actor->identifier());

    expect(DB::table('tickets')->where('id', $older)->value('linked_work'))->toBeFalse()
        ->and(DB::table('tickets')->where('id', $newer)->value('linked_work'))->toBeTrue()
        // FR-12's system note, through Doc 06's public door, on the flagged
        // ticket's conversation — an internal note Message row.
        ->and(DB::table('messages')->where('conversation_id', $cm)->where('is_internal', true)->count())->toBe(1);
});

it('one side only open → nothing is flagged (no collision exists)', function (): void {
    [$tenantId, $survivor, $merged] = collisionScene();
    $actor = App\Models\User::factory()->create();

    $cm = anchoredConversation($tenantId, $merged, 'tg-col2-m');
    $only = rollupTicket($tenantId, $cm, $merged, 'open');

    app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: no collision', $actor->identifier());

    expect(DB::table('tickets')->where('id', $only)->value('linked_work'))->toBeFalse();
});

it('EC-12: combined open linked_work beyond Doc 06\'s cap of 3 → BLOCKED at confirm with the EXACT tickets listed, nothing changed', function (): void {
    [$tenantId, $survivor, $merged] = collisionScene();
    $actor = App\Models\User::factory()->create();

    // Survivor: one plain open + two open linked_work (a prior sanctioned pile).
    $cs = anchoredConversation($tenantId, $survivor, 'tg-cap-s');
    rollupTicket($tenantId, $cs, $survivor, 'open');
    $lw = [];
    foreach ([1, 2] as $i) {
        $c = anchoredConversation($tenantId, $survivor, "tg-cap-s{$i}");
        $t = rollupTicket($tenantId, $c, $survivor, 'open');
        DB::table('tickets')->where('id', $t)->update(['linked_work' => true]);
        $lw[] = $t;
    }
    // Merged: two more open — flagging both would make 4 open linked_work.
    foreach ([1, 2] as $i) {
        $c = anchoredConversation($tenantId, $merged, "tg-cap-m{$i}");
        $lw[] = rollupTicket($tenantId, $c, $merged, 'open');
    }

    try {
        app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: over the cap', $actor->identifier());
        $this->fail('the over-cap merge was ACCEPTED — EC-12\'s refusal is gone');
    } catch (MergeBlocked $e) {
        expect(count($e->openLinkedWorkTicketIds))->toBe(4)
            ->and($e->openLinkedWorkTicketIds)->toEqualCanonicalizing($lw);
    }

    // Nothing changed: no tombstone, no snapshot, no flags added.
    expect(DB::table('customers')->where('id', $merged)->value('merged_into_id'))->toBeNull()
        ->and(DB::table('customer_merge_snapshots')->count())->toBe(0)
        ->and(DB::table('tickets')->where('linked_work', true)->count())->toBe(2);
});
