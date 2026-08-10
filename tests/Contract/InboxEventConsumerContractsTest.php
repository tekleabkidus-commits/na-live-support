<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 07's consumer contracts for NON-ticket events (S07 step 3 — the S22
| machinery's shape, applied to this document's own consumption; the ticket.*
| slices live in fixtures/tickets/consumers/doc07.json under
| TicketEventConsumerContractsTest)
|--------------------------------------------------------------------------
|
| THE RULE THIS FILE MAKES PHYSICAL: durable consumption ⇒ contract. Every
| event the ConsumerRegistry maps to a Doc 07 job class must hold a fixture
| entry pinning schema_version and the read-slice — so a producer bump goes
| RED here until reconciled deliberately, and a new durable subscription
| cannot ship uncontracted. channel.health.changed's entry is the event's
| FIRST consumer contract anywhere (inherited row 5).
|
| Catalog rows labelled 'Doc07/Realtime' on message.* / media.* are the
| SOCKET-CLIENT curation (Doc 04's own RealtimePusher serves those frames;
| the browser client is not a bus consumer) — they gain entries here the day
| a DURABLE Doc 07 consumer subscribes, and the completeness clause below is
| what forces that day to bring its contract.
*/

/** @return array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} */
function inboxConsumerFixture(): array
{
    /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
    $data = json_decode(
        (string) file_get_contents(base_path('fixtures/inbox/consumers/doc07-events.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $data;
}

/** @return list<string> events the registry maps to a Doc 07 (App\Jobs\Inbox) durable consumer */
function inboxDurableEvents(): array
{
    $events = [];
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        foreach ($consumers as $class) {
            if (str_starts_with($class, 'App\\Jobs\\Inbox\\')) {
                $events[] = $event;
                break;
            }
        }
    }
    sort($events);

    return $events;
}

test('fidelity: every fixture entry matches the catalog — event exists, schema_version pinned (a bump goes red until reconciled), reads ⊆ payload, consumer named', function () {
    $fixture = inboxConsumerFixture();
    expect($fixture['entries'])->not->toBeEmpty();

    foreach ($fixture['entries'] as $entry) {
        $label = "doc07-events.json: {$entry['event']} → {$entry['consumer']}";

        expect(in_array($entry['event'], EventCatalog::names(), true))
            ->toBeTrue("{$label} — the event is not in the catalog; a fixture for a ghost event contracts nothing");

        $definition = EventCatalog::definition($entry['event']);

        expect($entry['schema_version'])->toBe($definition['schema_version'],
            "{$label} — schema_version diverged from the catalog; reconcile the bump DELIBERATELY, then update this pin");

        expect(array_diff($entry['reads'], $definition['payload']))->toBe([],
            "{$label} — reads a key the payload no longer carries; the producer broke this document");

        expect(in_array($entry['consumer'], $definition['consumers'], true))
            ->toBeTrue("{$label} — the catalog no longer names {$entry['consumer']} as a consumer; the fixture is stale");
    }
});

test('completeness: every event the registry maps to a Doc 07 durable consumer holds a contract entry (ticket.* in doc07.json, the rest here)', function () {
    /** @var array{entries: list<array{event: string}>} $ticketFixture */
    $ticketFixture = json_decode(
        (string) file_get_contents(base_path('fixtures/tickets/consumers/doc07.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $contracted = array_merge(
        array_column(inboxConsumerFixture()['entries'], 'event'),
        array_column($ticketFixture['entries'], 'event'),
    );

    $uncontracted = array_values(array_diff(inboxDurableEvents(), $contracted));

    expect($uncontracted)->toBe([], sprintf(
        'durable Doc 07 subscription(s) with NO contract fixture: %s — a consumer cannot ship uncontracted (NFR-06 discipline)',
        implode(', ', $uncontracted),
    ));
});

test('the health consumer is registered for channel.health.changed — the event\'s FIRST durable consumer (inherited row 5)', function () {
    expect(in_array(App\Jobs\Inbox\HealthIndicatorConsumer::class, ConsumerRegistry::consumersFor('channel.health.changed'), true))
        ->toBeTrue('channel.health.changed lost its Doc 07 consumer registration');

    // v2 pinned at the fixture: the first consumer contract this event has.
    $entry = collect(inboxConsumerFixture()['entries'])->firstWhere('event', 'channel.health.changed');
    expect($entry)->not->toBeNull()
        ->and($entry['schema_version'])->toBe(2);
});
