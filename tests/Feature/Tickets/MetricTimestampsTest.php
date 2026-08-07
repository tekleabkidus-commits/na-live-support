<?php

declare(strict_types=1);

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-09 / FR-24 — the metric timestamps, on the shared golden fixtures
|--------------------------------------------------------------------------
|
| "AC-09 Metric timestamps: shared golden lifecycle fixtures produce exactly the FR-24
| values Doc 15's dictionary asserts." The fixtures live in fixtures/tickets/lifecycle/
| and are §21's "share golden lifecycle fixtures with Doc 15 from day one" — Doc 15 is
| not built, and they ship now anyway, because the whole point is that both sides run
| the SAME file rather than two readings of one sentence.
|
| HOW THIS IS DETERMINISTIC WITHOUT MOCKING A CLOCK (ruling R-20's clause (c)).
| Nothing here mocks anything: `clock_timestamp()` is unmockable by design, and
| CLAUDE.md forbids asserting on now(). Three techniques instead:
|
|  1. DB-VALUE-TO-DB-VALUE EQUALITY where exactness exists. first_response_at must
|     equal the signal message's own created_at byte-for-byte — two database values
|     compared to each other, with no clock in the assertion at all.
|  2. BACKDATED INTERVALS through `pgsql_platform`. The platform role kept full column
|     privileges precisely so fixtures can write historical values (R-19a), so each
|     step backdates last_status_changed_at by a pinned amount and the accumulator must
|     grow by exactly that. The only slack is the harness's own runtime between
|     backdating and transitioning — bounded by tolerance_seconds, and an implementation
|     that measured the wrong interval would be wrong by hundreds of seconds, not by
|     the millisecond the bound allows.
|  3. RELATIONAL ASSERTIONS for fresh stamps: set, cleared, or ordered relative to
|     another database value.
|
| THE FIXTURE-COMMITTING TEST IS DELIBERATELY ONE TEST. A second connection cannot see
| an uncommitted row, so the backdating driver has to commit and clean up in a finally —
| the pattern RetentionTest and the FR-07 burst already use. It is also the pattern
| implicated in RISK 0a, so it is confined to the single test that genuinely needs it;
| everything else below runs inside the ordinary rolled-back transaction.
*/

/** @return array<string, mixed> */
function lifecycleFixture(string $name): array
{
    $path = base_path("fixtures/tickets/lifecycle/{$name}.json");
    expect(is_file($path))->toBeTrue("golden fixture {$name} is missing — Doc 15 shares this file");

    /** @var array<string, mixed> $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

/** Backdate the ticket's status clock through the platform role (R-19a's escape hatch). */
function backdateStatusClock(string $ticketId, int $seconds): void
{
    DB::connection('pgsql_platform')->update(
        "UPDATE tickets SET last_status_changed_at = clock_timestamp() - (? || ' seconds')::interval WHERE id = ?",
        [$seconds, $ticketId],
    );
}

test('AC-09: the golden lifecycle fixtures produce exactly the FR-24 values', function (string $fixtureName) {
    $fixture = lifecycleFixture($fixtureName);
    $service = app(TicketTransitionService::class);

    [$tenant, , $ticket] = ticketFixture($fixture['start_status']);
    $tenantId = $tenant->identifier();
    $ticketId = $ticket->identifier();

    // A second connection cannot see uncommitted rows — commit, then clean up.
    commitFixtures();

    try {
        foreach ($fixture['steps'] as $index => $step) {
            backdateStatusClock($ticketId, (int) $step['hold_seconds']);

            $fresh = Ticket::query()->findOrFail($ticketId);
            $service->transition(
                $fresh,
                TicketStatus::from($step['to']),
                TransitionContext::system('golden fixture step '.$index),
            );

            if (isset($step['expect_after']['resolved_at'])) {
                $after = Ticket::query()->findOrFail($ticketId);
                $expected = $step['expect_after']['resolved_at'];
                $message = sprintf('step %d (-> %s): resolved_at should be %s', $index, $step['to'], $expected);

                $expected === 'set'
                    ? expect($after->resolved_at)->not->toBeNull($message)
                    : expect($after->resolved_at)->toBeNull($message);
            }
        }

        $final = Ticket::query()->findOrFail($ticketId);
        $tolerance = (int) $fixture['tolerance_seconds'];
        $expect = $fixture['expect'];

        expect($final->status()->value)->toBe($expect['final_status']);

        // The accumulators: never below the pinned total (that would mean an interval
        // was missed or attributed to the wrong party), never more than the harness's
        // own runtime above it.
        foreach (['waiting_staff_seconds', 'waiting_customer_seconds'] as $column) {
            $want = (int) $expect[$column];
            expect($final->{$column})->toBeGreaterThanOrEqual($want, sprintf(
                '%s is %d, below the fixture\'s %d — an interval was lost or credited to the other party',
                $column, $final->{$column}, $want,
            ))->toBeLessThanOrEqual($want + $tolerance, sprintf(
                '%s is %d, more than %d seconds above the fixture\'s %d',
                $column, $final->{$column}, $tolerance, $want,
            ));
        }

        foreach (['resolved_at', 'closed_at'] as $column) {
            $expect[$column] === 'set'
                ? expect($final->{$column})->not->toBeNull("{$column} should be set at the end of {$fixture['id']}")
                : expect($final->{$column})->toBeNull("{$column} should be null at the end of {$fixture['id']}");
        }
    } finally {
        cleanupTenants([$tenantId]);
    }
})->with(['ss01-deposit-dispute', 'reopen-clears-resolved-at']);

// ── FR-24's first-response definitions ───────────────────────────────────────

/** A public outbound message on the conversation, authored by $authorType. */
function replyMessage(string $tenantId, string $conversationId, string $authorType, bool $note = false): string
{
    $id = (string) Str::uuid7();

    DB::table('messages')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'direction' => $note ? 'in' : 'out',
        'author_type' => $authorType,
        'kind' => $note ? 'note' : 'text',
        'body' => 'fixture reply',
        'is_internal' => $note,
        'delivery_state' => $note ? null : 'sent',
    ]);

    return $id;
}

test('FR-24: first_response_at IS the signal message\'s own database timestamp, to the microsecond', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $messageId = replyMessage($tenant->identifier(), $conversation->identifier(), 'ai');

    app(TicketTransitionService::class)->recordPublicReply($ticket, $messageId, isHuman: false);

    $ticket->refresh();
    $messageStamp = DB::table('messages')->where('id', $messageId)->value('created_at');

    // Equality between two DATABASE values — no clock appears in this assertion.
    expect((string) $ticket->first_response_at->format('Y-m-d H:i:s.u'))
        ->toBe((new DateTimeImmutable((string) $messageStamp))->format('Y-m-d H:i:s.u'))
        ->and($ticket->agent_first_response_at)->toBeNull('an AI reply is not a HUMAN reply (FR-24)');
});

test('FR-24: agent_first_response_at counts the first public HUMAN reply, after an AI one', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    $aiMessage = replyMessage($tenant->identifier(), $conversation->identifier(), 'ai');
    $service->recordPublicReply($ticket, $aiMessage, isHuman: false);

    $humanMessage = replyMessage($tenant->identifier(), $conversation->identifier(), 'agent');
    $service->recordPublicReply($ticket->fresh(), $humanMessage, isHuman: true);

    $ticket->refresh();
    $aiStamp = DB::table('messages')->where('id', $aiMessage)->value('created_at');
    $humanStamp = DB::table('messages')->where('id', $humanMessage)->value('created_at');

    // first_response_at still points at the AI reply — FILL-ONLY, it never moves.
    expect($ticket->first_response_at->format('Y-m-d H:i:s.u'))
        ->toBe((new DateTimeImmutable((string) $aiStamp))->format('Y-m-d H:i:s.u'))
        ->and($ticket->agent_first_response_at->format('Y-m-d H:i:s.u'))
        ->toBe((new DateTimeImmutable((string) $humanStamp))->format('Y-m-d H:i:s.u'));
});

test('FR-24 + C-09: a redelivered reply cannot move an existing stamp (fill-only is trigger physics)', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    $first = replyMessage($tenant->identifier(), $conversation->identifier(), 'agent');
    $service->recordPublicReply($ticket, $first, isHuman: true);
    $stamped = $ticket->fresh()->first_response_at->format('Y-m-d H:i:s.u');

    // A LATER reply, and the same one delivered twice — neither may rewrite history.
    $later = replyMessage($tenant->identifier(), $conversation->identifier(), 'agent');
    $service->recordPublicReply($ticket->fresh(), $later, isHuman: true);
    $service->recordPublicReply($ticket->fresh(), $first, isHuman: true);

    expect($ticket->fresh()->first_response_at->format('Y-m-d H:i:s.u'))->toBe($stamped)
        ->and($ticket->fresh()->first_response_message_id)->toBe($first);
});

test('FR-24: the trigger REFUSES an internal note as a first-response signal (advisor strengthening 1)', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $note = replyMessage($tenant->identifier(), $conversation->identifier(), 'agent', note: true);

    // The service not signalling on a note is convention; this refusal is the guarantee.
    expect(fn () => app(TicketTransitionService::class)->recordPublicReply($ticket, $note, isHuman: true))
        ->toThrow(QueryException::class, 'internal note');

    expect($ticket->fresh()->first_response_at)->toBeNull();
});

test('FR-24: the trigger REFUSES a pre-ticket message and a message from another conversation', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    // Another conversation in the SAME tenant — a second ticketFixture() would create
    // a second tenant and rebind the context, and the cross-tenant insert would then
    // be refused by RLS before this assertion could say anything (it was, on the first
    // run: the guard under test is the trigger's, not the policy's).
    $otherConversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $otherConversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(10),
        'customer_id' => $conversation->customer_id,
        'state' => 'active',
    ]);

    $foreign = replyMessage($tenant->identifier(), $otherConversationId, 'agent');
    expect(fn () => $service->recordPublicReply($ticket, $foreign, isHuman: true))
        ->toThrow(QueryException::class, 'another conversation');

    // A message that PREDATES the ticket — FR-24 excludes pre-ticket chatbot messages.
    // Inserted first, so it is genuinely earlier: the app cannot backdate created_at.
    $earlyMessage = replyMessage($tenant->identifier(), $conversation->identifier(), 'ai');
    [, , $laterTicket] = (function () use ($tenant, $conversation) {
        $id = (string) Str::uuid7();
        DB::table('tickets')->insert([
            'id' => $id,
            'tenant_id' => $tenant->identifier(),
            'conversation_id' => $conversation->identifier(),
            'customer_id' => $conversation->customer_id,
            'number' => random_int(1, 2_000_000_000),
            'status' => 'resolved',   // not open-family: coexists with the fixture's ticket
            'priority' => 'normal',
            'source' => 'agent',
        ]);

        return [null, null, Ticket::query()->findOrFail($id)];
    })();

    expect(fn () => $service->recordPublicReply($laterTicket, $earlyMessage, isHuman: false))
        ->toThrow(QueryException::class, 'predates the ticket');
});

test('FR-24: a customer message is not a response at all', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');

    $customerMessage = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $customerMessage,
        'tenant_id' => $tenant->identifier(),
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in',
        'author_type' => 'customer',
        'kind' => 'text',
        'body' => 'where is my deposit',
        'is_internal' => false,
    ]);

    expect(fn () => app(TicketTransitionService::class)->recordPublicReply($ticket, $customerMessage, isHuman: false))
        ->toThrow(QueryException::class, 'not a staff or AI reply');
});

test('R-19b strengthening 2: last_status_changed_at is born from clock_timestamp(), so the first interval measures from the row\'s birth', function () {
    $definition = DB::connection('pgsql_migrator')->selectOne(
        "SELECT column_default, is_nullable
           FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'tickets' AND column_name = 'last_status_changed_at'",
    );

    expect((string) $definition->column_default)->toContain('clock_timestamp()')
        ->and($definition->is_nullable)->toBe('NO',
            'a nullable status clock would make the first accumulator interval measure from nothing');

    // And it is genuinely populated on a row the app inserted without naming it.
    [, , $ticket] = ticketFixture('new');
    expect($ticket->last_status_changed_at)->not->toBeNull()
        ->and($ticket->waiting_staff_seconds)->toBe(0)
        ->and($ticket->waiting_customer_seconds)->toBe(0);
});
