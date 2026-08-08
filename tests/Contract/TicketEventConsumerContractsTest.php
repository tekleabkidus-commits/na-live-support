<?php

declare(strict_types=1);

use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 06 NFR-06 / T-09 — consumer contract tests for the ticket.* schemas
|--------------------------------------------------------------------------
|
| "Every FR-26 event schema is versioned with at least one consumer contract test
| per consuming doc." The consuming documents are not built; their CONTRACTS are —
| fixtures/tickets/consumers/docNN.json pins, per consuming document, the events it
| consumes, the schema_version it was written against, and the payload keys it
| DEPENDS ON. This suite holds the two sides together:
|
|   producer side   every ticket.* catalog event lists consumers; each consumer
|                   document must hold a fixture entry for it (a new event or a new
|                   consumer cannot ship uncontracted);
|   consumer side   every fixture entry must name a real catalog relationship, read
|                   only keys the payload carries, and pin the CURRENT
|                   schema_version — so a version bump goes red HERE until every
|                   consuming document's fixture is reconciled deliberately, which
|                   is NFR-06's "versioned" clause with teeth.
|
| The runtime half is already structural: EventPublisher refuses any publish whose
| keys differ from the catalog in either direction, so an emitted event can never
| drift from what these fixtures read. Docs 12, 15, 17 are T-09's named minimum;
| all seven consuming documents (07, 11, 12, 14, 15, 16, 17) are covered.
*/

/** @return array<string, array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>}> keyed by docNN */
function consumerContractFixtures(): array
{
    $fixtures = [];

    foreach (glob(base_path('fixtures/tickets/consumers/*.json')) ?: [] as $path) {
        /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $fixtures[strtolower($data['doc'])] = $data;
    }

    return $fixtures;
}

/** @return array<string, array{schema_version: int, payload: list<string>, producers: list<string>, consumers: list<string>, description: string}> */
function ticketEvents(): array
{
    $events = [];

    foreach (EventCatalog::names() as $name) {
        if (str_starts_with($name, 'ticket.')) {
            $events[$name] = EventCatalog::definition($name);
        }
    }

    return $events;
}

test('NFR-06 completeness: every ticket.* event has a contract entry in EVERY consuming document\'s fixture', function () {
    $fixtures = consumerContractFixtures();
    expect($fixtures)->not->toBeEmpty('the consumer fixtures are missing entirely');

    $missing = [];

    foreach (ticketEvents() as $event => $definition) {
        foreach ($definition['consumers'] as $consumer) {
            $doc = strtolower(explode('/', $consumer)[0]);

            $entry = collect($fixtures[$doc]['entries'] ?? [])->first(
                static fn (array $e): bool => $e['event'] === $event && $e['consumer'] === $consumer,
            );

            if ($entry === null) {
                $missing[] = "{$event} → {$consumer}";
            }
        }
    }

    expect($missing)->toBe([], sprintf(
        "NFR-06: %d catalog consumer relationship(s) have no contract fixture:\n%s\nAdd the entry to fixtures/tickets/consumers/<doc>.json — an uncontracted consumer is exactly what NFR-06 forbids.",
        count($missing),
        implode("\n", $missing),
    ));
});

test('NFR-06 fidelity: every fixture entry names a real relationship, reads only carried keys, and pins the CURRENT schema version', function () {
    $events = ticketEvents();

    foreach (consumerContractFixtures() as $doc => $fixture) {
        foreach ($fixture['entries'] as $entry) {
            $label = "{$fixture['doc']}: {$entry['event']}";

            expect(array_key_exists($entry['event'], $events))->toBeTrue("{$label} — the event is not in the catalog; a fixture for a ghost event contracts nothing");

            $definition = $events[$entry['event']];

            expect($entry['consumer'])->toBeIn($definition['consumers'], "{$label} — the catalog no longer names {$entry['consumer']} as a consumer; the fixture is stale");

            // The versioning teeth: a catalog bump goes red here, per consuming
            // document, until each is reconciled on purpose.
            expect($entry['schema_version'])->toBe($definition['schema_version'], sprintf(
                '%s — the fixture pins v%d but the catalog is at v%d: reconcile this consumer DELIBERATELY (NFR-06)',
                $label, $entry['schema_version'], $definition['schema_version'],
            ));

            expect($entry['reads'])->not->toBeEmpty("{$label} — a consumer that reads nothing is not a consumer")
                ->and(array_diff($entry['reads'], $definition['payload']))->toBe([], "{$label} — reads keys the payload does not carry");

            // C-02's discipline crosses the bus: every consumer slice is tenant-scoped.
            expect($entry['reads'])->toContain('tenant_id');
        }
    }
});

test('T-09: the named minimum — Docs 12, 15 and 17 each hold a non-trivial ticket contract', function () {
    $fixtures = consumerContractFixtures();

    foreach (['doc12', 'doc15', 'doc17'] as $doc) {
        expect($fixtures)->toHaveKey($doc);
        expect(count($fixtures[$doc]['entries']))->toBeGreaterThanOrEqual(5, "T-09 names {$doc} as a fixture consumer — its contract looks vestigial");
    }
});
