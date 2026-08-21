<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 09's event contracts (S09 step 9 — FR-21 + the ADR 0011 consumer law)
|--------------------------------------------------------------------------
|
| TWO RULES MADE PHYSICAL:
|
| 1. FR-21's family is CLOSED — six named by the document plus the one
|    rule-16 amendment approved at the step-8 stop (ai.provider.health_changed)
|    = SEVEN. A new ai.* event goes red here until an amendment is filed.
|    Every payload is identifiers + closed categories only (the Q7 split):
|    no prompt, output, body, or excerpt text ever rides the bus — the
|    ai_decisions table is the record; the stream is a signal.
|
| 2. Durable consumption ⇒ contract: every event the ConsumerRegistry maps to
|    an App\Jobs\Ai job holds a fixture entry pinning schema_version and the
|    read slice (ticket.* in fixtures/tickets/consumers/doc09.json, the rest
|    in fixtures/ai/consumers/doc09-events.json).
*/

/** @return array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} */
function aiConsumerFixture(string $path): array
{
    /** @var array{doc: string, entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
    $data = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

/** @return list<string> */
function aiDurableEvents(): array
{
    $events = [];
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        foreach ($consumers as $class) {
            if (str_starts_with($class, 'App\\Jobs\\Ai\\')) {
                $events[] = $event;
                break;
            }
        }
    }
    sort($events);

    return $events;
}

const AI_EVENT_FAMILY = [
    'ai.config.published',
    'ai.decision.logged',
    'ai.handoff.created',
    'ai.killswitch.changed',
    'ai.provider.health_changed',
    'ai.resolved',
    'ai.rg.flagged',
];

test('FR-21: the ai.* family is CLOSED at seven (six + the approved rule-16 amendment) — exact set, every one schema_version 1 with a Doc 09 producer', function () {
    $inCatalog = array_values(array_filter(EventCatalog::names(), fn (string $n): bool => str_starts_with($n, 'ai.')));
    sort($inCatalog);

    expect($inCatalog)->toBe(AI_EVENT_FAMILY, 'the ai.* family drifted — an addition needs a filed rule-16 amendment, a removal a filed deprecation');

    foreach (AI_EVENT_FAMILY as $name) {
        $definition = EventCatalog::definition($name);
        expect($definition['schema_version'])->toBe(1, "{$name} bumped without this pin moving");
        expect(array_filter($definition['producers'], fn (string $p): bool => str_starts_with($p, 'Doc09/')))
            ->not->toBeEmpty("{$name} has no Doc 09 producer");
    }
});

test('Q7/SEC-06: every ai.* payload is identifiers + closed categories — tenant_id always, and NO text-shaped key can ride the bus', function () {
    $textShaped = ['text', 'body', 'content', 'prompt', 'output', 'excerpt', 'answer', 'message', 'transcript', 'draft', 'payload', 'request', 'response'];

    foreach (AI_EVENT_FAMILY as $name) {
        $payload = EventCatalog::definition($name)['payload'];
        expect($payload)->toContain('tenant_id');
        foreach ($payload as $key) {
            foreach ($textShaped as $needle) {
                expect(str_contains($key, $needle))->toBeFalse("{$name}.{$key} is text-shaped — content never rides the bus (Q7)");
            }
        }
    }
});

test('FR-21: every ai.* event is actually PRODUCED by Doc 09 code (a catalog row nobody publishes contracts nothing)', function () {
    $sources = '';
    foreach ([base_path('app/Domain/Ai'), base_path('app/Jobs/Ai')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $sources .= file_get_contents($file->getPathname());
            }
        }
    }

    foreach (AI_EVENT_FAMILY as $name) {
        expect(str_contains($sources, "'{$name}'"))->toBeTrue("{$name} is in the catalog but no Doc 09 source publishes it");
    }
});

test('ai.decision.logged is SAMPLED by a registered platform setting (FR-21: high-volume stream, the table is the record)', function () {
    expect(in_array('ai.decisions.event_sample_permille', app(App\Support\Settings\SettingsRegistry::class)->keys(), true))->toBeTrue();
    expect(EventCatalog::definition('ai.decision.logged')['payload'])->not->toContain('request_payload')->not->toContain('response_payload');
});

test('fidelity: every Doc 09 fixture entry matches the catalog — event exists, schema_version pinned, reads ⊆ payload, consumer named', function () {
    foreach (['fixtures/ai/consumers/doc09-events.json', 'fixtures/tickets/consumers/doc09.json'] as $path) {
        $fixture = aiConsumerFixture($path);
        expect($fixture['entries'])->not->toBeEmpty();

        foreach ($fixture['entries'] as $entry) {
            $label = "{$path}: {$entry['event']} → {$entry['consumer']}";
            expect(in_array($entry['event'], EventCatalog::names(), true))->toBeTrue("{$label} — ghost event");
            $definition = EventCatalog::definition($entry['event']);
            expect($entry['schema_version'])->toBe($definition['schema_version'], "{$label} — schema_version diverged; reconcile the bump DELIBERATELY, then move this pin");
            expect(array_diff($entry['reads'], $definition['payload']))->toBe([], "{$label} — reads a key the payload no longer carries");
            expect(in_array($entry['consumer'], $definition['consumers'], true))->toBeTrue("{$label} — the catalog no longer names this consumer");
        }
    }
});

test('completeness: every event the registry maps to an App\\Jobs\\Ai durable consumer holds a contract entry', function () {
    $contracted = array_merge(
        array_column(aiConsumerFixture('fixtures/ai/consumers/doc09-events.json')['entries'], 'event'),
        array_column(aiConsumerFixture('fixtures/tickets/consumers/doc09.json')['entries'], 'event'),
    );

    $uncontracted = array_values(array_diff(aiDurableEvents(), $contracted));

    expect($uncontracted)->toBe([], sprintf('durable Doc 09 subscription(s) with NO contract fixture: %s', implode(', ', $uncontracted)));
    // S09 step 11: ticket.created joined (GenerateTicketSubject — Doc 06 FR-03's generation half); the pin moves deliberately, same commit as the registry + fixture.
    expect(aiDurableEvents())->toBe(['media.needs_ocr', 'media.needs_transcript', 'message.received', 'ticket.created', 'ticket.reopened', 'ticket.resolved']);
});
