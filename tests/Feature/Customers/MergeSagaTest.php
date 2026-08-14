<?php

declare(strict_types=1);

use App\Domain\Customers\Merge\CustomerMerge;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/*
|--------------------------------------------------------------------------
| Doc 08 T-03/T-08/FS-02 — the chaos proof: kill between chunks →
| compensation restores EVERYTHING; the pair is NEVER left half-merged.
| SABOTAGE #5 disables compensation and this file goes red.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** @return array{0: string, 1: string, 2: string, 3: array<string, array<string, string>>} scene + the pre-merge pointer map */
function chaosScene(): array
{
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $survivor = enforcedCustomer($tenantId, '2026-05-01 10:00:00+00');
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $survivor)->update(['tags' => '{vip}']);
    DB::table('customers')->where('id', $merged)->update(['tags' => '{new-account}']);

    $conversation = anchoredConversation($tenantId, $merged, 'tg-chaos-m');
    $ticket = rollupTicket($tenantId, $conversation, $merged, 'closed');

    $identity = (string) new UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $identity, 'tenant_id' => $tenantId, 'customer_id' => $merged,
        'channel' => 'telegram', 'external_user_id' => 'tg-chaos-m',
        'type' => 'telegram', 'value' => 'tg-chaos-m', 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $pointers = [
        'conversations' => [$conversation => $merged],
        'tickets' => [$ticket => $merged],
        'customer_identities' => [$identity => $merged],
    ];

    return [$tenantId, $survivor, $merged, $pointers];
}

/** @param array<string, array<string, string>> $pointers */
function assertPreMergeState(string $survivor, string $merged, array $pointers): void
{
    foreach ($pointers as $table => $rows) {
        foreach ($rows as $id => $owner) {
            expect(DB::table($table)->where('id', $id)->value('customer_id'))->toBe($owner);
        }
    }
    expect(DB::table('customers')->where('id', $merged)->value('merged_into_id'))->toBeNull()
        ->and((string) DB::table('customers')->where('id', $survivor)->value('tags'))->toBe('{vip}')
        ->and((string) DB::table('customers')->where('id', $merged)->value('tags'))->toBe('{new-account}')
        ->and(DB::table('outbox')->where('name', 'customer.merged')->count())->toBe(0);
}

it('FS-02: a crash AFTER the tickets chunk compensates back to the pre-merge state — nothing half-merged, the snapshot latched reversed, the system act audited', function (): void {
    [, $survivor, $merged, $pointers] = chaosScene();
    $actor = App\Models\User::factory()->create();

    try {
        app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: chaos', $actor->identifier(),
            function (string $chunk): void {
                if ($chunk === 'tickets') {
                    throw new RuntimeException('chaos: killed between chunks');
                }
            });
        $this->fail('the chaos throw did not propagate');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('chaos');
    }

    assertPreMergeState($survivor, $merged, $pointers);
    expect(DB::table('customer_merge_snapshots')->whereNotNull('undone_at')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.merge_compensated')->count())->toBe(1);
});

it('FS-02: a crash after the FIRST chunk compensates identically — the restoration is idempotent over the lists', function (): void {
    [, $survivor, $merged, $pointers] = chaosScene();
    $actor = App\Models\User::factory()->create();

    try {
        app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: chaos early', $actor->identifier(),
            function (string $chunk): void {
                if ($chunk === 'conversations') {
                    throw new RuntimeException('chaos: killed at the first chunk');
                }
            });
        $this->fail('the chaos throw did not propagate');
    } catch (RuntimeException) {
    }

    assertPreMergeState($survivor, $merged, $pointers);

    // And a RETRY (a fresh merge) succeeds cleanly after the compensation.
    app(CustomerMerge::class)->execute($survivor, $merged, [], 'fixture: retry after chaos', $actor->identifier());
    expect(DB::table('customers')->where('id', $merged)->value('merged_into_id'))->toBe($survivor);
});
