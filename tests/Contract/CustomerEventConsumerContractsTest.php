<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 08's consumer contracts (S08 step 3 — the S22/S07 machinery's shape,
| applied to this document's own durable consumption; ADR 0011)
|--------------------------------------------------------------------------
|
| THE RULE THIS FILE MAKES PHYSICAL: durable consumption ⇒ contract. Every
| event the ConsumerRegistry maps to a Doc 08 job class must hold a fixture
| entry pinning schema_version and the read-slice — a producer bump goes RED
| here until reconciled deliberately, and a new durable subscription cannot
| ship uncontracted. customer.merged / customer.unmerged entries are those
| events' FIRST consumer contracts anywhere.
*/

/** @return array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} */
function customerConsumerFixture(): array
{
    /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
    $data = json_decode(
        (string) file_get_contents(base_path('fixtures/customers/consumers/doc08-events.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $data;
}

/** @return list<string> events the registry maps to a Doc 08 (App\Jobs\Customers) durable consumer */
function customerDurableEvents(): array
{
    $events = [];
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        foreach ($consumers as $class) {
            if (str_starts_with($class, 'App\\Jobs\\Customers\\')) {
                $events[] = $event;
                break;
            }
        }
    }
    sort($events);

    return $events;
}

test('fidelity: every fixture entry matches the catalog — event exists, schema_version pinned, reads ⊆ payload, consumer named', function () {
    $fixture = customerConsumerFixture();
    expect($fixture['entries'])->not->toBeEmpty();

    foreach ($fixture['entries'] as $entry) {
        $label = "doc08-events.json: {$entry['event']} → {$entry['consumer']}";

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

test('completeness: every event the registry maps to a Doc 08 durable consumer holds a contract entry', function () {
    $contracted = array_column(customerConsumerFixture()['entries'], 'event');
    $uncontracted = array_values(array_diff(customerDurableEvents(), $contracted));

    expect($uncontracted)->toBe([], sprintf(
        'durable Doc 08 subscription(s) with NO contract fixture: %s — a consumer cannot ship uncontracted (ADR 0011)',
        implode(', ', $uncontracted),
    ));
});

test('the rollup consumer is registered for the customer merge family — those events\' FIRST durable consumers', function () {
    foreach (['customer.merged', 'customer.unmerged'] as $event) {
        expect(in_array(App\Jobs\Customers\RollupConsumer::class, ConsumerRegistry::consumersFor($event), true))
            ->toBeTrue("{$event} lost its Doc 08 rollup registration");
    }
});
