<?php

declare(strict_types=1);

use App\Support\Events\EventCatalog;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-03's two outbound seams as CONTRACT FIXTURES (the Doc 07 fixture-
| fidelity shape): Doc 16 reads automation.notify.requested, Doc 14 reads
| automation.sms.requested — reads ⊆ payload, consumer named in the catalog,
| and the SMS slice provably carries no text key (BR-04).
|--------------------------------------------------------------------------
*/

it('fixtures/automation/doc16-notify-contract.json + doc14-sms-request-contract.json match the catalog — reads ⊆ payload, consumer named, version pinned; the SMS payload holds no text key', function (): void {
    foreach (['doc16-notify-contract.json', 'doc14-sms-request-contract.json'] as $file) {
        /** @var array{event: string, consumer: string, schema_version: int, reads: list<string>} $fixture */
        $fixture = json_decode((string) file_get_contents(base_path('fixtures/automation/'.$file)), true, 8, JSON_THROW_ON_ERROR);
        $definition = EventCatalog::definition($fixture['event']);
        expect($fixture['schema_version'])->toBe($definition['schema_version'], "{$file}: schema_version drifted")
            ->and(array_diff($fixture['reads'], $definition['payload']))->toBe([], "{$file}: reads a key the payload does not carry")
            ->and(in_array($fixture['consumer'], $definition['consumers'], true))->toBeTrue("{$file}: the catalog no longer names {$fixture['consumer']}");
    }
    expect(array_intersect(['body', 'text', 'message', 'content'], EventCatalog::definition('automation.sms.requested')['payload']))->toBe([]);
});

it('fixtures/automation/doc12-events.json (Doc 12\'s NON-ticket durable consumption — the Doc 10 shape): every entry names a catalog event, a named consumer, the current version, and reads ⊆ payload incl. tenant_id', function (): void {
    /** @var array{entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/automation/doc12-events.json')), true, 8, JSON_THROW_ON_ERROR);
    expect($fixture['entries'])->not->toBeEmpty();
    foreach ($fixture['entries'] as $entry) {
        $label = "doc12-events.json: {$entry['event']} → {$entry['consumer']}";
        expect(in_array($entry['event'], EventCatalog::names(), true))->toBeTrue("{$label} — ghost event");
        $definition = EventCatalog::definition($entry['event']);
        expect($entry['schema_version'])->toBe($definition['schema_version'], "{$label} — version drifted")
            ->and(array_diff($entry['reads'], $definition['payload']))->toBe([], "{$label} — reads a key the payload does not carry")
            ->and($entry['reads'])->toContain('tenant_id')
            ->and(in_array($entry['consumer'], $definition['consumers'], true))->toBeTrue("{$label} — the catalog does not name this consumer");
    }
});
