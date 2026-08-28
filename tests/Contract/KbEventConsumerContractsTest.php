<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 10's event contracts (S10 step 6 — FR-12/FR-13 + the ADR 0011 consumer law)
|--------------------------------------------------------------------------
|
| 1. The kb.* family is CLOSED at SIX (the approved plan's enumeration; A-44:
|    an unnamed event enters only by filed amendment). Every payload is
|    identifiers + closed categories only (Q7) — never article text on the bus.
| 2. Durable consumption ⇒ contract: every event the ConsumerRegistry maps to
|    an App\Jobs\Kb job holds a fixture entry pinning schema_version and the
|    read slice (fixtures/kb/consumers/doc10-events.json); the ticket.* roster
|    is deliberately EMPTY (fixtures/tickets/consumers/doc10.json).
*/

// S10 step 7b packet P4: the A-44 amendment GREW the family 6 → 9 (submitted /
// changes_requested / lock.taken_over — the notification arms' triggers).
const KB_EVENT_FAMILY = [
    'kb.article.cited_by_ai',
    'kb.article.inserted_by_agent',
    'kb.article.published',
    'kb.article.viewed',
    'kb.feedback.received',
    'kb.lock.taken_over',
    'kb.retrieval.updated',
    'kb.variant.changes_requested',
    'kb.variant.submitted',
];

const KB_FORBIDDEN_PAYLOAD_KEYS = ['content', 'body', 'text', 'summary', 'title', 'excerpt', 'comment', 'blocks'];

function kbConsumerFixture(string $path): array
{
    /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
    $data = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

it('FR-12/FR-13: the kb.* family in the catalog is EXACTLY the six the plan enumerates — no text-bearing payload key on the bus (Q7)', function (): void {
    $catalogued = array_values(array_filter(EventCatalog::names(), static fn (string $n): bool => str_starts_with($n, 'kb.')));
    sort($catalogued);
    $registered = array_values(array_intersect(KB_EVENT_FAMILY, $catalogued));
    // Steps 4/6 register the two retrieval-side events; steps 10/11 add the four analytics events. The family never grows past six.
    expect(array_diff($catalogued, KB_EVENT_FAMILY))->toBe([], 'a kb.* event outside the closed family: '.implode(', ', array_diff($catalogued, KB_EVENT_FAMILY)));
    // tenant.provisioned is Doc 01's event; Doc 10's consumer of it is pinned below with the rest.
    expect($registered)->toContain('kb.article.published')->toContain('kb.retrieval.updated')->toContain('kb.variant.submitted')->toContain('kb.lock.taken_over')->toContain('kb.article.cited_by_ai')->toContain('kb.article.inserted_by_agent');
    foreach ($catalogued as $name) {
        $definition = EventCatalog::definition($name);
        expect(array_intersect($definition['payload'], KB_FORBIDDEN_PAYLOAD_KEYS))->toBe([], $name.' carries text on the bus');
        expect($definition['payload'])->toContain('tenant_id');
    }
});

it('ADR 0011: every kb.* event a Doc 10 job consumes durably carries a fixture entry pinning schema_version and the read slice; the ticket.* roster is deliberately empty', function (): void {
    $events = [];
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        foreach ($consumers as $class) {
            if (str_starts_with($class, 'App\\Jobs\\Kb\\')) {
                $events[] = $event;
                break;
            }
        }
    }
    sort($events);
    $fixture = kbConsumerFixture('fixtures/kb/consumers/doc10-events.json');
    $pinned = array_values(array_unique(array_column($fixture['entries'], 'event')));
    sort($pinned);
    expect($events)->toBe($pinned, 'a Doc 10 durable consumer without a contract fixture (or a stale fixture)');
    foreach ($fixture['entries'] as $entry) {
        $definition = EventCatalog::definition($entry['event']);
        expect($entry['schema_version'])->toBe($definition['schema_version'])
            ->and(array_diff($entry['reads'], $definition['payload']))->toBe([], $entry['event'].' fixture reads a key the payload does not carry');
    }
    $tickets = kbConsumerFixture('fixtures/tickets/consumers/doc10.json');
    expect($tickets['entries'])->toBe([]);
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        if (str_starts_with($event, 'ticket.')) {
            foreach ($consumers as $class) {
                expect(str_starts_with($class, 'App\\Jobs\\Kb\\'))->toBeFalse('Doc 10 consumes a ticket event without a fixture: '.$event);
            }
        }
    }
});
