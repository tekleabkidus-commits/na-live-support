<?php

declare(strict_types=1);

use App\Support\Events\EventCatalog;
use App\Support\Events\EventPublisher;
use App\Support\Events\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| SCHEMA AMENDMENT 6 packet #3 (Doc 01 core edit, countersigned 2026-09-01) —
| THE ENVELOPE-METADATA EXTENSION IS ADDITIVE AND THE PAYLOAD STAYS CLOSED
|--------------------------------------------------------------------------
|
| Doc 12 T-03's automation_depth rides ENVELOPE metadata (outbox/events
| columns beside correlation/causation), never a payload key. The countersign's
| obligation-2 proofs, each here:
|   1. ADDITIVE — a publish WITHOUT metadata behaves exactly as before
|      (outbox metadata NULL; relay copies NULL; nothing else changed).
|   2. THE CARRY — a publish WITH metadata lands it on the outbox row and the
|      relay copies it BYTE-IDENTICALLY into events (the A-33 copy discipline,
|      key-agnostic).
|   3. THE SMUGGLED-KEY CONTROL — automation_depth pushed INSIDE a payload is
|      REFUSED by the catalog's closed-payload law (assertPayloadMatches), so
|      packet #3 cannot have opened the 13-month event stream to key creep.
|      This control goes red if the closure is ever loosened.
| The catalog and its gate are byte-untouched by this packet (the event-catalog
| gate proves no drift); Doc 01's suites run green in the same commit.
*/

function envelopeScene(): array
{
    $tenant = createTenant();
    bindTenant($tenant);
    $payload = [
        'tenant_id' => $tenant->identifier(),
        'ticket_id' => (string) Str::uuid7(),
        'conversation_id' => (string) Str::uuid7(),
        'record_id' => (string) Str::uuid7(),
        'queue_id' => (string) Str::uuid7(),
        'assigned_to' => null,
    ];

    return [$tenant, $payload];
}

it('packet #3 (1): a publish WITHOUT metadata is byte-compatible — NULL envelope, relay unchanged', function (): void {
    [, $payload] = envelopeScene();

    $eventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('ticket.routed', $payload));

    $outbox = DB::table('outbox')->where('event_id', $eventId)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->metadata)->toBeNull('a metadata-less publish must not invent an envelope');

    commitFixtures();
    asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
    $event = asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('id', $eventId)->first());
    expect($event)->not->toBeNull('the relay stopped relaying a metadata-less row')
        ->and($event->metadata)->toBeNull();
});

it('packet #3 (2): envelope metadata lands on the outbox row and the relay copies it byte-identically', function (): void {
    [, $payload] = envelopeScene();

    $eventId = DB::transaction(fn (): string => app(EventPublisher::class)
        ->publish('ticket.routed', $payload, null, ['automation_depth' => 2]));

    $outbox = DB::table('outbox')->where('event_id', $eventId)->first();
    expect($outbox->metadata)->not->toBeNull('the metadata never reached the outbox row');
    expect(json_decode((string) $outbox->metadata, true))->toBe(['automation_depth' => 2]);

    commitFixtures();
    asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
    $event = asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('id', $eventId)->first());
    expect($event)->not->toBeNull()
        ->and($event->metadata)->toBe($outbox->metadata, 'the relay did not copy the envelope byte-identically');
});

it('packet #3 (3), the smuggled-key control: automation_depth INSIDE a payload is refused by the closed-payload law', function (): void {
    [, $payload] = envelopeScene();
    $smuggled = array_merge($payload, ['automation_depth' => 1]);

    expect(fn () => EventCatalog::assertPayloadMatches('ticket.routed', $smuggled))
        ->toThrow(Exception::class);

    // And through the publisher itself — the refusal is at the door, not only the helper.
    expect(fn () => DB::transaction(fn (): string => app(EventPublisher::class)->publish('ticket.routed', $smuggled)))
        ->toThrow(Exception::class);
});
