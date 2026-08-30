<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-01 — the ticket row, exactly as filed in interpretation A-7
|--------------------------------------------------------------------------
|
| FR-01's "Doc 01 Phase-22 schema" reference resolves to nothing (ruling Q1), so the
| base field list is A-7's filed interpretation and THIS test is what keeps the schema
| honest against it: the column INVENTORY is asserted exactly — a column added outside
| the interpretation record, or one silently dropped, fails here by name.
|
| CHECK physics: the 8 statuses are platform-fixed (R-01) — a ninth status is refused
| by the DATABASE, not by validation code that a hotfix can bypass. Same for priority,
| source, subject_source and FR-01's pending_reason enum.
|
| Refused inserts run inside DB::transaction() so each failure rolls back to a
| savepoint and the test session stays usable (Postgres aborts the outer transaction
| otherwise, and later assertions would "pass" on the abort error rather than the
| constraint under test).
*/

function ticketsFixture(): array
{
    $tenant = createTenant();
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_tickets',
        'is_shell' => true,
        'created_source' => 'staff',
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

/** A minimal legal ticket insert naming ONLY app-writable columns (R-19b). */
function ticketRow(string $tenantId, string $conversationId, string $customerId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'new',
        'priority' => 'normal',
        'source' => 'agent',
    ], $overrides);
}

function insertTicketRefused(array $row): void
{
    expect(fn () => DB::transaction(fn () => DB::table('tickets')->insert($row)))
        ->toThrow(QueryException::class);
}

test('FR-01/A-7: the tickets column inventory is exactly the filed interpretation', function () {
    $columns = DB::connection('pgsql_migrator')->select(
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'tickets'",
    );

    $actual = array_map(static fn (object $c): string => $c->column_name, $columns);
    sort($actual);

    // A-7 as filed, PLUS the two signal columns of its advisor-approved amendment
    // (first_response_message_id / agent_first_response_message_id, ruling R-20's
    // note). This assertion is why the amendment had to be filed at all: the columns
    // arrived in step 2 and this list went red until the record caught up.
    // PLUS auto_close_at, T-03's column truth for the FR-07 timer (step 5's
    // migration; its header carries the SS-03/FS-01 reasoning) — same pattern:
    // this list stayed red until the record named the column.
    $expected = [
        'agent_first_response_at', 'agent_first_response_message_id', 'assignee_id',
        'auto_close_at',
        'channel_unreachable', 'closed_at', 'conversation_id', 'created_at', 'customer_id',
        'department_id', 'escalation', 'first_response_at', 'first_response_message_id',
        'form_data', 'id', 'idempotency_key', 'last_status_changed_at', 'linked_work',
        'merged_into', 'number', 'origin_ticket_id', 'parent_ticket_id', 'pending_reason',
        'previous_ticket_id', 'priority',
        // SCHEMA AMENDMENT 5 item 12 (S11 step 2b, countersigned): queue_id (the PlaceInQueue
        // door's column) + the two DB-time placement stamps — the pin moves WITH the filed
        // amendment, which is this test working as intended.
        'queue_id', 'queued_at',
        'reopened_count', 'resolved_at', 'routed_at',
        'source', 'spam_marked_at', 'spam_marked_by', 'status', 'subject',
        'subject_source', 'tags', 'tenant_id', 'updated_at', 'waiting_customer_seconds',
        'waiting_staff_seconds',
    ];

    expect($actual)->toBe($expected,
        'tickets columns drifted from interpretation A-7 — schema changes to this table go through the record, not a quiet migration');
});

test('R-19b: every DB-time column is timestamptz at microsecond precision, and tags is a real array', function () {
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT column_name, data_type, datetime_precision
           FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'tickets'
            AND column_name IN ('created_at', 'last_status_changed_at', 'spam_marked_at',
                                'first_response_at', 'agent_first_response_at',
                                'resolved_at', 'closed_at', 'tags')",
    );

    foreach ($rows as $row) {
        if ($row->column_name === 'tags') {
            expect($row->data_type)->toBe('ARRAY', 'FR-19/T-07 need a GIN-indexable text[]');

            continue;
        }
        expect($row->data_type)->toBe('timestamp with time zone', "{$row->column_name} must be timestamptz (C-03)");
        expect((int) $row->datetime_precision)->toBe(6,
            "{$row->column_name} is not microsecond precision — the whole-second truncation R-19 abolished is back");
    }
});

test('R-01: the database refuses a ninth status — the machine is platform-fixed', function () {
    [$tenant, $customer, $conversationId] = ticketsFixture();

    insertTicketRefused(ticketRow($tenant->identifier(), $conversationId, $customer->identifier(), [
        'status' => 'on_hold',
    ]));
});

test('FR-01 CHECKs: priority, source, subject_source and pending_reason enums are database physics', function () {
    [$tenant, $customer, $conversationId] = ticketsFixture();
    $t = $tenant->identifier();
    $cu = $customer->identifier();

    insertTicketRefused(ticketRow($t, $conversationId, $cu, ['priority' => 'critical']));
    insertTicketRefused(ticketRow($t, $conversationId, $cu, ['source' => 'import']));
    insertTicketRefused(ticketRow($t, $conversationId, $cu, ['subject_source' => 'operator']));
    insertTicketRefused(ticketRow($t, $conversationId, $cu, ['pending_reason' => 'awaiting_internal']));

    // The four legal pending_reason values all insert (each its own conversation to
    // clear T-02) — proving the CHECK refuses by VALUE, not by column.
    foreach (['awaiting_customer', 'awaiting_internal:department', 'awaiting_internal:named_user', 'awaiting_internal:external'] as $i => $reason) {
        $freshConversation = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $freshConversation,
            'tenant_id' => $t,
            'channel' => 'fakechan',
            'channel_ref_id' => 'bot-1',
            'external_chat_id' => 'chat-pending-'.$i,
            'customer_id' => $cu,
            'state' => 'active',
        ]);
        DB::table('tickets')->insert(ticketRow($t, $freshConversation, $cu, [
            'status' => 'pending_internal',
            'pending_reason' => $reason,
        ]));
    }

    expect(DB::table('tickets')->whereNotNull('pending_reason')->count())->toBe(4);
});

test('FR-02: ticket numbers are unique per tenant at the database layer, and the DB stamps created_at itself', function () {
    [$tenant, $customer, $conversationId] = ticketsFixture();
    $t = $tenant->identifier();

    DB::table('tickets')->insert(ticketRow($t, $conversationId, $customer->identifier(), ['number' => 4812]));

    // Same number, same tenant → refused by tickets_tenant_number_unique.
    insertTicketRefused(ticketRow($t, $conversationId, $customer->identifier(), [
        'number' => 4812,
        'status' => 'closed', // clears T-02 so the refusal is unambiguously the number index
    ]));

    // R-19b smoke: the row the app just inserted WITHOUT naming created_at got a real
    // microsecond DB stamp (the deep grant proof lives in TicketGrantPhysicsTest).
    $stamp = DB::table('tickets')->where('number', 4812)->value('created_at');
    expect($stamp)->not->toBeNull()
        ->and((string) $stamp)->toMatch('/\.\d{3,6}/', 'created_at has no fractional seconds — the clock_timestamp() default is not applying');
});
