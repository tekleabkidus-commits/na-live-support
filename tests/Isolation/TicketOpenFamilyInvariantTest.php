<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 T-02 / AC-02 (index half) — the open-family invariant as physics
|--------------------------------------------------------------------------
|
| `tickets_one_open_family` — partial unique ON (conversation_id) WHERE status IN
| (open-family) AND linked_work = false — sits BEHIND the TicketTransitionService
| check (§21: keep it even though the service checks first; the one hotfix that
| bypasses the service must hit this index, never create a duplicate-ticket
| incident). This file proves the INDEX, by direct SQL with no service anywhere:
|
| - a second open-family row on one conversation is refused for EVERY pair of
|   open-family statuses — including via the PLATFORM role, which bypasses RLS and
|   the app grants but not index physics;
| - resolved / closed / spam rows coexist freely (FR-10: resolved is NOT
|   open-family — that is what makes FR-08's reopen the path instead of a duplicate);
| - linked_work = true rows (FR-21 split targets) are EXEMPT — the one sanctioned
|   multi-open case; their cap of 3 is the service's job (EC-04), not the index's.
|
| AC-02's concurrent-creates half arrives with the creation paths (step 3); this
| file is the "direct-SQL bypass fails on the T-02 index" half.
*/

function openFamilyFixture(): array
{
    $tenant = createTenant();
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_openfam',
        'is_shell' => true,
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(8),
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);

    return [$tenant, $customer, $conversationId];
}

function rawTicket(string $tenantId, string $conversationId, string $customerId, string $status, bool $linkedWork = false): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => $status,
        'priority' => 'normal',
        'source' => 'agent',
        'linked_work' => $linkedWork,
    ];
}

const OPEN_FAMILY = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

test('T-02: every open-family pair on one conversation is refused by the index, all 25 cells', function () {
    [$tenant, $customer, $conversationId] = openFamilyFixture();
    $t = $tenant->identifier();
    $cu = $customer->identifier();

    foreach (OPEN_FAMILY as $first) {
        foreach (OPEN_FAMILY as $second) {
            $firstId = (string) Str::uuid7();
            DB::table('tickets')->insert(array_merge(
                rawTicket($t, $conversationId, $cu, $first),
                ['id' => $firstId],
            ));

            expect(fn () => DB::transaction(
                fn () => DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, $second)),
            ))->toThrow(
                QueryException::class,
                'tickets_one_open_family',
            );

            // Clear the slot for the next cell — closed leaves the open family.
            DB::table('tickets')->where('id', $firstId)->update(['status' => 'closed']);
        }
    }
});

test('FR-10: resolved, closed and spam are NOT open-family — they coexist with one open ticket', function () {
    [$tenant, $customer, $conversationId] = openFamilyFixture();
    $t = $tenant->identifier();
    $cu = $customer->identifier();

    DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, 'open'));

    foreach (['resolved', 'closed', 'spam'] as $status) {
        DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, $status));
    }

    expect(DB::table('tickets')->where('conversation_id', $conversationId)->count())->toBe(4);
});

test('FR-21: linked_work rows are exempt from the index — the one sanctioned multi-open case', function () {
    [$tenant, $customer, $conversationId] = openFamilyFixture();
    $t = $tenant->identifier();
    $cu = $customer->identifier();

    DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, 'open'));

    // The index permits ANY number of linked_work opens; the cap of 3 is EC-04's
    // service refusal (step 6), deliberately not encoded here.
    foreach (range(1, 3) as $i) {
        DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, 'open', linkedWork: true));
    }

    expect(DB::table('tickets')->where('conversation_id', $conversationId)->where('linked_work', true)->count())->toBe(3);
});

test('AC-02: the direct-SQL bypass fails on the index even for the RLS-bypassing platform role', function () {
    [$tenant, $customer, $conversationId] = openFamilyFixture();
    $t = $tenant->identifier();
    $cu = $customer->identifier();

    DB::table('tickets')->insert(rawTicket($t, $conversationId, $cu, 'open'));

    // A second connection cannot see uncommitted rows — commit the fixtures first
    // (the ConversationSchemaTest/soak pattern), clean up in finally.
    commitFixtures();

    try {
        $row = rawTicket($t, $conversationId, $cu, 'escalated');
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        expect(fn () => DB::connection('pgsql_platform')->statement(
            "INSERT INTO tickets ({$columns}) VALUES ({$placeholders})",
            array_values($row),
        ))->toThrow(QueryException::class, 'tickets_one_open_family');
    } finally {
        cleanupTenants([$t]);
    }
});
