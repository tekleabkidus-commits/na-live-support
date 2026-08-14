<?php

declare(strict_types=1);

use App\Domain\Customers\Merge\CustomerMerge;
use App\Domain\Customers\Merge\MergePlan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-11/FR-12 — the merge flow: diff/choose, unions, tombstone,
| the chunk order, and SEC-02's mandatory reason
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** @return array{0: string, 1: string, 2: string} [tenantId, survivorId, mergedId] */
function flowScene(): array
{
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $survivor = enforcedCustomer($tenantId, '2026-05-01 10:00:00+00');
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');

    DB::table('customers')->where('id', $survivor)->update([
        'display_name' => 'አበበ ታደሰ', 'language' => 'am', 'language_source' => 'staff', 'tags' => '{vip,withdrawal-history}',
    ]);
    DB::table('customers')->where('id', $merged)->update([
        'display_name' => 'Abel Tesfaye', 'language' => 'en', 'language_source' => 'detected', 'tags' => '{new-account,vip}',
    ]);

    return [$tenantId, $survivor, $merged];
}

function flowIdentity(string $tenantId, string $customerId, string $value): string
{
    $id = (string) new UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'channel' => 'telegram', 'external_user_id' => $value,
        'type' => 'telegram', 'value' => $value, 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function flowNote(string $tenantId, string $customerId, string $body, bool $pinned = false): string
{
    /** @var object{id: string} $row */
    $row = DB::selectOne(
        'INSERT INTO customer_notes (id, tenant_id, customer_id, author_id, body, pinned) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
        [(string) new UuidV7, $tenantId, $customerId, (string) new UuidV7, $body, $pinned ? 'true' : 'false'],
    );

    return $row->id;
}

it('FR-11/FR-12: the full flow — chosen fields apply, tags/notes/files/identities UNION, everything re-points, the merged becomes a tombstone, and the chunk order is T-03\'s', function (): void {
    [$tenantId, $survivor, $merged] = flowScene();
    $actor = App\Models\User::factory()->create();

    flowIdentity($tenantId, $survivor, 'tg-s1');
    $movedIdentity = flowIdentity($tenantId, $merged, 'tg-m1');
    $conversation = anchoredConversation($tenantId, $merged, 'tg-m1');
    $ticket = rollupTicket($tenantId, $conversation, $merged, 'closed');
    flowNote($tenantId, $survivor, 'survivor pin', true);
    $mergedPin = flowNote($tenantId, $merged, 'merged pin', true);

    $chunks = [];
    $snapshotId = app(CustomerMerge::class)->execute(
        $survivor, $merged,
        ['display_name' => 'merged', 'language' => 'survivor'],
        'fixture: same player, old and new accounts',
        $actor->identifier(),
        function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        },
    );

    // T-03's order, observed.
    expect($chunks)->toBe(['conversations', 'tickets', 'notes_files', 'customer_identities']);

    $survivorRow = DB::table('customers')->where('id', $survivor)->first();
    $mergedRow = DB::table('customers')->where('id', $merged)->first();

    expect($survivorRow->display_name)->toBe('Abel Tesfaye')       // chosen from merged
        ->and($survivorRow->language)->toBe('am')                  // kept from survivor
        ->and(MergePlan::pgArray((string) $survivorRow->tags))->toEqualCanonicalizing(['vip', 'withdrawal-history', 'new-account'])
        ->and($mergedRow->merged_into_id)->toBe($survivor);        // BR-05's redirect pointer

    expect(DB::table('conversations')->where('id', $conversation)->value('customer_id'))->toBe($survivor)
        ->and(DB::table('tickets')->where('id', $ticket)->value('customer_id'))->toBe($survivor)
        ->and(DB::table('customer_identities')->where('id', $movedIdentity)->value('customer_id'))->toBe($survivor)
        // The pinned-note collision: survivor's pin wins, merged's arrives unpinned.
        ->and(DB::table('customer_notes')->where('customer_id', $survivor)->where('pinned', true)->count())->toBe(1)
        ->and(DB::table('customer_notes')->where('id', $mergedPin)->value('pinned'))->toBeFalse()
        ->and(DB::table('customer_notes')->where('customer_id', $survivor)->count())->toBe(2);

    expect(DB::table('outbox')->where('name', 'customer.merged')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.merged')->count())->toBe(1)
        ->and(DB::table('customers')->where('id', $survivor)->value('tickets_count'))->toBe(1)
        ->and(DB::table('customer_merge_snapshots')->where('id', $snapshotId)->value('undone_at'))->toBeNull();
});

it('SEC-02: a merge without a reason refuses before anything moves', function (): void {
    [, $survivor, $merged] = flowScene();
    $actor = App\Models\User::factory()->create();

    expect(fn () => app(CustomerMerge::class)->execute($survivor, $merged, [], '  ', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'reason');
});

it('chain law: a tombstone can take part in NO merge — its only path is unmerge (EC-04)', function (): void {
    [$tenantId, $survivor, $merged] = flowScene();
    $third = enforcedCustomer($tenantId, '2026-07-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: first merge', $actor->identifier());

    expect(fn () => app(CustomerMerge::class)->execute($third, $merged, [], 'fixture: merge a tombstone', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'tombstone');
    expect(fn () => app(CustomerMerge::class)->execute($merged, $third, [], 'fixture: merge into a tombstone', $actor->identifier()))
        ->toThrow(InvalidArgumentException::class, 'tombstone');
});
