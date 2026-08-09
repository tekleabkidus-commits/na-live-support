<?php

declare(strict_types=1);

use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Reopen\ReopenTicket;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Jobs\Tickets\TicketReopenConsumer;
use App\Models\Ticket;
use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventPublisher;
use App\Support\Events\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| ADR 0011 — the relay dispatches durable consumers; FS-02 — the catch-up drill
|--------------------------------------------------------------------------
|
| "FS-02's 'catches up from the backlog' is satisfied by queue durability plus
| re-drive, and must be proven by test: kill the consumer between persist and
| reopen, re-drive, assert the reopen lands exactly once." — ADR 0011, verbatim.
|
| The dispatch tests run the REAL relay on the platform connection against
| committed fixtures (the OutboxChaosTest pattern); the kill runs inside the
| consumer's own transaction, which is what a worker death mid-flight leaves
| behind once Postgres reaps the connection: a rolled-back attempt and a job the
| queue re-drives.
|
| The job is executed directly here, tenant bound, as the queue WORKER would run
| it — in production the relay process never executes consumers inline, so a test
| that ran the job inside the relay's platform closure would prove a path that
| does not exist.
|
*/

beforeEach(function (): void {
    Redis::connection('durable')->del(TicketTimers::ZSET);
});

test('ADR 0011: the dispatch map is a registry, and message.received maps to exactly the reopen consumer', function () {
    // S23 step 8: WindowReopenConsumer joined message.received DELIBERATELY
    // (Doc 05 FR-24 reopen). This guard catches SILENT additions — a deliberate
    // one updates it, and the exact-list assertion still fails a stray consumer.
    expect(ConsumerRegistry::consumersFor('message.received'))->toBe([
        TicketReopenConsumer::class,
        App\Jobs\Telegram\WindowReopenConsumer::class,
    ])
        ->and(ConsumerRegistry::consumersFor('ticket.created'))->toBe([], 'no other event has grown a consumer silently');
});

test('ADR 0011: relaying a mapped event queues the consumer once — event id, tenant and correlation stamped — and a relay re-run re-dispatches nothing', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    // A real publish, exactly as InboundPersister does it: in the business
    // transaction, through the outbox.
    $messageId = reopenCustomerMessage($tenantId, $conversation->identifier());
    $eventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('message.received', [
        'tenant_id' => $tenantId,
        'message_id' => $messageId,
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $conversation->customer_id,
        'kind' => 'text',
    ]));

    $row = DB::table('outbox')->where('event_id', $eventId)->first();
    commitFixtures();

    try {
        Queue::fake();

        $relayed = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
        expect($relayed)->toBeGreaterThan(0);

        Queue::assertPushed(TicketReopenConsumer::class, function (TicketReopenConsumer $job) use ($eventId, $tenantId, $row): bool {
            return $job->eventId === $eventId
                && $job->tenantId === $tenantId
                && $job->correlationId === $row->correlation_id   // C-14 survives the relay hop
                && $job->queue === 'automation';
        });
        Queue::assertPushed(TicketReopenConsumer::class, 1);

        // "a relay restart re-dispatches nothing already relayed" (ADR 0011).
        $second = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
        expect($second)->toBe(0);
        Queue::assertPushed(TicketReopenConsumer::class, 1);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('FS-02 drill: kill the consumer between persist and reopen; re-drive lands the reopen EXACTLY once; a third delivery changes nothing', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    // A closed-within-window ticket and the customer's follow-up, all app-side —
    // the drill is about the CONSUMER's crash recovery, not the relay's.
    $conv = anotherConversation($tenantId, 'drill');
    $ticket = machineClosedTicket($conv);
    $messageId = reopenCustomerMessage($tenantId, $conv->identifier());
    $eventId = reopenSeedEvent($conv, $messageId);

    $reopenedEvents = fn (): int => DB::table('outbox')->where('name', 'ticket.reopened')->count();

    // ── Attempt 1: THE KILL — mid-transaction, after the status UPDATE ran and
    // before it committed. Everything the attempt persisted must vanish with it.
    $armed = true;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'update "tickets"') && str_contains($query->sql, '"status"')) {
            throw new RuntimeException('drill: worker died between persist and reopen');
        }
    });

    try {
        runReopenConsumer($eventId);
        $this->fail('the kill hook did not fire — the drill proved nothing');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('drill: worker died');
    } finally {
        $armed = false;
    }

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Closed, 'the killed attempt must leave NO trace')
        ->and($ticket->reopened_count)->toBe(0)
        ->and($reopenedEvents())->toBe(0, 'an event survived a rolled-back attempt — outbox atomicity is broken');

    // ── Attempt 2: THE RE-DRIVE. Same job, same event id, world untouched.
    runReopenConsumer($eventId);

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open, 'the re-driven consumer must complete the reopen')
        ->and($ticket->reopened_count)->toBe(1, 'EXACTLY once')
        ->and($reopenedEvents())->toBe(1, 'EXACTLY one ticket.reopened');

    // ── Attempt 3: DUPLICATE DELIVERY (C-09's at-least-once, arriving late).
    runReopenConsumer($eventId);

    $ticket->refresh();
    expect($ticket->reopened_count)->toBe(1, 'a duplicate delivery re-reopened')
        ->and($reopenedEvents())->toBe(1)
        ->and(Ticket::query()->where('conversation_id', $conv->identifier())->count())->toBe(1, 'a duplicate delivery minted a ticket');
});

test('FS-02 end-to-end: relay dispatch → worker-style execution → the closed ticket is reopened, once', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    $conv = anotherConversation($tenantId, 'e2e');
    $ticket = machineClosedTicket($conv);
    $messageId = reopenCustomerMessage($tenantId, $conv->identifier());

    $eventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('message.received', [
        'tenant_id' => $tenantId,
        'message_id' => $messageId,
        'conversation_id' => $conv->identifier(),
        'customer_id' => $conv->customer_id,
        'kind' => 'text',
    ]));

    commitFixtures();

    try {
        Queue::fake();
        asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        /** @var TicketReopenConsumer $job */
        $job = Queue::pushed(TicketReopenConsumer::class)->first();
        expect($job)->not->toBeNull('the relay never dispatched the consumer');

        // Execute as the worker would: tenant bound (the job middleware's doing in
        // production), handler invoked with its real collaborators.
        asTenant($tenant, fn () => $job->handle(app(ReopenTicket::class), app(CreateTicket::class)));

        asTenant($tenant, function () use ($ticket, $conv): void {
            $ticket->refresh();
            expect($ticket->status())->toBe(TicketStatus::Open, 'the event did not reopen the ticket end-to-end')
                ->and($ticket->reopened_count)->toBe(1)
                ->and(Ticket::query()->where('conversation_id', $conv->identifier())->count())->toBe(1);
        });
    } finally {
        cleanupTenants([$tenantId]);
    }
});
