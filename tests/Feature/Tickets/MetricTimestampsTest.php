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

            if (($step['op'] ?? null) === 'merge_into_fresh_target') {
                // The A-13 rider's scenario: the close is FR-20's supersede, driven
                // through the real operation so the golden proves the real doors.
                $mergeActor = ticketActor(['tickets.merge', 'tickets.view'], 'Fixture Golden Merge Role');
                $targetConv = anotherConversation($tenantId, 'golden-tgt', $fresh->customer_id);
                $target = machineClosedTicket($targetConv, 'open');
                app(App\Domain\Tickets\Merge\MergeTickets::class)->merge([$fresh], $target, $mergeActor);
            } else {
                $service->transition(
                    $fresh,
                    TicketStatus::from($step['to']),
                    // Spam edges carry FR-04's mandatory reason; the fixture supplies it.
                    TransitionContext::system($step['reason'] ?? 'golden fixture step '.$index),
                );
            }

            if (isset($step['expect_after']['resolved_at'])) {
                $after = Ticket::query()->findOrFail($ticketId);
                $expected = $step['expect_after']['resolved_at'];
                $message = sprintf('step %d (-> %s): resolved_at should be %s', $index, $step['to'] ?? $step['op'], $expected);

                $expected === 'set'
                    ? expect($after->resolved_at)->not->toBeNull($message)
                    : expect($after->resolved_at)->toBeNull($message);
            }

            if (isset($step['expect_after']['metrics_excluded'])) {
                // AC-06/N-14: the ONE exclusion predicate, asked mid-lifecycle —
                // sabotage #5's target.
                $after = Ticket::query()->findOrFail($ticketId);
                expect(app(App\Domain\Tickets\Metrics\MetricExclusion::class)->excluded($after))
                    ->toBe($step['expect_after']['metrics_excluded'], sprintf(
                        'step %d: MetricExclusion should answer %s here (AC-06/N-14)',
                        $index,
                        $step['expect_after']['metrics_excluded'] ? 'true' : 'false',
                    ));
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

        if (isset($expect['metrics_excluded'])) {
            expect(app(App\Domain\Tickets\Metrics\MetricExclusion::class)->excluded($final))
                ->toBe($expect['metrics_excluded'], sprintf(
                    'MetricExclusion should answer %s at the end of %s (AC-06/N-14)',
                    $expect['metrics_excluded'] ? 'true' : 'false',
                    $fixture['id'],
                ));
        }

        if (isset($expect['events'])) {
            // Event-derived metric counts (the A-13 rider): Doc 15 counts closes from
            // ticket.closed — the golden pins how many of each event THIS ticket
            // contributed. ticket.merged names the source in source_ticket_id.
            foreach ($expect['events'] as $eventName => $count) {
                $observed = DB::table('outbox')
                    ->where('name', $eventName)
                    ->where(function ($query) use ($ticketId): void {
                        $query->whereRaw("payload->>'ticket_id' = ?", [$ticketId])
                            ->orWhereRaw("payload->>'source_ticket_id' = ?", [$ticketId]);
                    })
                    ->count();

                expect($observed)->toBe($count, sprintf(
                    '%s: expected exactly %d %s event(s) for this ticket, found %d — the metric contract owns this count',
                    $fixture['id'], $count, $eventName, $observed,
                ));
            }
        }
    } finally {
        cleanupTenants([$tenantId]);
    }
})->with(['ss01-deposit-dispute', 'reopen-clears-resolved-at', 'spam-mark-excludes-unmark-restores', 'merge-supersede-no-close']);

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
    //
    // NOTE ON WHAT THIS HALF DOES *NOT* PROVE — it is why the bracketed test below
    // exists. A `now()` default would also produce a non-null value here, and would
    // look entirely correct while silently stamping transaction-start time. Non-null
    // is not the property that matters; WHEN is.
    [, , $ticket] = ticketFixture('new');
    expect($ticket->last_status_changed_at)->not->toBeNull()
        ->and($ticket->waiting_staff_seconds)->toBe(0)
        ->and($ticket->waiting_customer_seconds)->toBe(0);
});

test('R-19b strengthening 2 (behavioural): a fresh ticket\'s status clock lands BETWEEN two clock_timestamp() probes', function () {
    // THE BEHAVIOURAL GOLDEN the schema check cannot give. No clock is mocked and no
    // wall clock is asserted against — the bounds are two database readings taken
    // around the insert, so the assertion is DB-value-to-DB-value throughout.
    //
    // WHY THIS DISCRIMINATES clock_timestamp() FROM now(), which is the entire point:
    // the harness wraps each test in a transaction that began BEFORE the opening probe.
    // A `now()` default returns transaction-start time, which is strictly EARLIER than
    // that probe, so the lower bound below fails. Only a per-row clock read at insert
    // time can land inside the window.
    $opening = DB::selectOne('SELECT clock_timestamp() AS t')->t;

    [, , $ticket] = ticketFixture('new');

    $closing = DB::selectOne('SELECT clock_timestamp() AS t')->t;

    // Compared IN the database, so no PHP datetime parsing can blunt the microseconds.
    $bounds = DB::selectOne(
        'SELECT (last_status_changed_at >= ?::timestamptz) AS at_or_after_opening,
                (last_status_changed_at <= ?::timestamptz) AS at_or_before_closing,
                (created_at            >= ?::timestamptz) AS created_at_or_after_opening,
                last_status_changed_at AS stamp
           FROM tickets WHERE id = ?',
        [$opening, $closing, $opening, $ticket->identifier()],
    );

    expect((bool) $bounds->at_or_after_opening)->toBeTrue(sprintf(
        'last_status_changed_at (%s) is EARLIER than the opening probe (%s) — that is transaction-start '
        .'time, i.e. now(), not clock_timestamp(): the first accumulator interval would measure from '
        .'before the ticket existed (FR-24, R-19b).',
        (string) $bounds->stamp, (string) $opening,
    ));

    expect((bool) $bounds->at_or_before_closing)->toBeTrue(sprintf(
        'last_status_changed_at (%s) is LATER than the closing probe (%s) — the stamp does not come from '
        .'the insert at all.',
        (string) $bounds->stamp, (string) $closing,
    ));

    // created_at is the same rule and the same window (R-19b applies to both).
    expect((bool) $bounds->created_at_or_after_opening)->toBeTrue(
        'created_at predates the opening probe — the ordering key is back on transaction-start time (C-11)',
    );
});
