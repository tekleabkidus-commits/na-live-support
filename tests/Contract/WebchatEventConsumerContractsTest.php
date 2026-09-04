<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 13 — consumer contracts (Doc 06 NFR-06's shape; ADR 0011): every event the
| ConsumerRegistry maps to a Doc 13 job class holds a fixture entry pinning
| schema_version and the read-slice (fixtures/webchat/consumers/doc13-events.json
| for the non-ticket families; the ticket.* slices live in
| fixtures/tickets/consumers/doc13.json, the routing slices in
| fixtures/routing/consumers/doc13-events.json); and every consumer the catalog
| DECLARES for Doc 13 is IMPLEMENTED (A-45).
|--------------------------------------------------------------------------
*/
/** @return list<array{event: string, consumer: string, schema_version: int, reads: list<string>}> */
function doc13Contracts(): array
{
    $entries = [];
    foreach (['fixtures/webchat/consumers/doc13-events.json', 'fixtures/tickets/consumers/doc13.json', 'fixtures/routing/consumers/doc13-events.json'] as $path) {
        if (! is_file(base_path($path))) {
            continue;
        }
        /** @var array{entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);
        $entries = [...$entries, ...$data['entries']];
    }

    return $entries;
}

test('A-45: every consumer the catalog declares for Doc 13 is registered on a Doc 13 job class, and every Doc 13 registration is contracted with the CURRENT schema version and a read-slice the payload carries', function (): void {
    $declared = [];
    foreach (EventCatalog::names() as $event) {
        foreach (EventCatalog::definition($event)['consumers'] as $consumer) {
            if (str_starts_with($consumer, 'Doc13/')) {
                $declared[] = "{$event} → {$consumer}";
            }
        }
    }
    $contracts = doc13Contracts();
    $contracted = array_map(static fn (array $e): string => "{$e['event']} → {$e['consumer']}", $contracts);
    expect(array_diff($declared, $contracted))->toBe([], 'a declared Doc 13 consumer has no contract entry');

    foreach ($contracts as $entry) {
        $definition = EventCatalog::definition($entry['event']);
        expect($entry['schema_version'])->toBe($definition['schema_version'], "{$entry['event']}: schema_version drifted")
            ->and(array_diff($entry['reads'], $definition['payload']))->toBe([], "{$entry['event']}: reads a key the payload does not carry");
        $jobs = array_filter(ConsumerRegistry::consumersFor($entry['event']), static fn (string $class): bool => str_starts_with($class, 'App\\Jobs\\Webchat\\'));
        expect($jobs)->not->toBeEmpty("{$entry['event']}: contracted for Doc 13 but no Doc 13 job is registered");
    }
});
