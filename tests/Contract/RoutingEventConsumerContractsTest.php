<?php

declare(strict_types=1);

use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-19 / T-09 / Doc 06 NFR-06's shape — consumer contracts for the ROUTING family
|--------------------------------------------------------------------------
| The eighteen org.* / queue.* / ticket.routed / ticket.waiting / routing.* / rescue.* events:
| every declared consumer document holds a fixture entry (fixtures/routing/consumers/docNN-
| events.json) that reads only keys the payload carries and pins the current schema_version —
| a version bump or a consumer added silently goes red HERE. The TicketEventConsumerContractsTest
| shape, for Doc 11's own family (ticket.routed/ticket.waiting are covered there too, being
| ticket.*; the routing fixtures carry them as well so this family stands on its own).
*/

const ROUTING_FAMILY = '/^(org\.|queue\.|ticket\.routed$|ticket\.waiting$|routing\.|rescue\.)/';

/** @return array<string, array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>}> keyed by docNN */
function routingConsumerFixtures(): array
{
    $fixtures = [];
    foreach (glob(base_path('fixtures/routing/consumers/*-events.json')) ?: [] as $path) {
        /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $fixtures[strtolower($data['doc'])] = $data;
    }

    return $fixtures;
}

/** @return array<string, array{schema_version: int, payload: list<string>, producers: list<string>, consumers: list<string>, description: string}> */
function routingEvents(): array
{
    $events = [];
    foreach (EventCatalog::names() as $name) {
        if (preg_match(ROUTING_FAMILY, $name) === 1) {
            $events[$name] = EventCatalog::definition($name);
        }
    }

    return $events;
}

test('the routing family is exactly EIGHTEEN events (FR-19; grown only by filed amendment)', function () {
    expect(array_keys(routingEvents()))->toHaveCount(18);
});

test('completeness: every routing-family event has a contract entry in EVERY consuming document\'s fixture', function () {
    $fixtures = routingConsumerFixtures();
    expect($fixtures)->not->toBeEmpty();
    $missing = [];
    foreach (routingEvents() as $event => $definition) {
        foreach ($definition['consumers'] as $consumer) {
            $doc = strtolower(explode('/', $consumer)[0]);
            $entry = collect($fixtures[$doc]['entries'] ?? [])->first(static fn (array $e): bool => $e['event'] === $event && $e['consumer'] === $consumer);
            if ($entry === null) {
                $missing[] = "{$event} → {$consumer}";
            }
        }
    }
    expect($missing)->toBe([], 'uncontracted consumers: '.implode(', ', $missing));
});

test('fidelity: every fixture entry names a real catalog relationship, reads only keys the payload carries, and pins the CURRENT schema_version', function () {
    $events = routingEvents();
    $problems = [];
    foreach (routingConsumerFixtures() as $doc => $fixture) {
        foreach ($fixture['entries'] as $entry) {
            $definition = $events[$entry['event']] ?? null;
            if ($definition === null) {
                $problems[] = "{$doc}: {$entry['event']} is not a routing-family catalog event";

                continue;
            }
            if (! in_array($entry['consumer'], $definition['consumers'], true)) {
                $problems[] = "{$doc}: {$entry['consumer']} is not a declared consumer of {$entry['event']}";
            }
            foreach (array_diff($entry['reads'], $definition['payload']) as $key) {
                $problems[] = "{$doc}: {$entry['event']} reads {$key} which the payload does not carry";
            }
            if ($entry['schema_version'] !== $definition['schema_version']) {
                $problems[] = "{$doc}: {$entry['event']} pins schema_version {$entry['schema_version']}, catalog is {$definition['schema_version']}";
            }
        }
    }
    expect($problems)->toBe([]);
});

test('Doc 11\'s own durable consumers are contracted: the availability consumer on queue.membership_changed and queue.temporary_grant.created', function () {
    $doc11 = routingConsumerFixtures()['doc11'] ?? ['entries' => []];
    $events = array_column($doc11['entries'], 'event');
    expect($events)->toContain('queue.membership_changed')->toContain('queue.temporary_grant.created');
});
