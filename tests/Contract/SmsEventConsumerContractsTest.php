<?php

declare(strict_types=1);

use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventCatalog;

/*
| Doc 14 step 6 — the CONSUMER AMENDMENT 1 contract held together: the
| doc14.json fixture's `reads` ⊆ each catalog payload at the pinned
| schema_version; every entry names a catalog relationship the catalog itself
| declares (Doc14/Sms or Doc14/SmsSuppression in that event's consumers); and
| every declared relationship is IMPLEMENTED (A-45: never a hook) — a real
| registered OutboxConsumer class consumes that event in ConsumerRegistry.
*/

/**
 * The doc14.json fixture holds the ticket.* half (Doc 06's harness scopes to
 * ticket events); the four non-ticket contracts are pinned HERE, reads exactly
 * as CONSUMER AMENDMENT 1 filed them.
 *
 * @return list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>
 */
function doc14ConsumerEntries(): array
{
    /** @var array{entries: list<array{event: string, consumer: string, schema_version: int, reads: list<string>}>} $data */
    $data = json_decode((string) file_get_contents(base_path('fixtures/tickets/consumers/doc14.json')), true, 512, JSON_THROW_ON_ERROR);

    return [...$data['entries'],
        ['event' => 'automation.sms.requested', 'consumer' => 'Doc14/Sms', 'schema_version' => 1, 'reads' => ['tenant_id', 'rule_id', 'execution_id', 'ticket_id', 'customer_id', 'template_ref']],
        ['event' => 'customer.suppression.set', 'consumer' => 'Doc14/Sms', 'schema_version' => 1, 'reads' => ['tenant_id', 'customer_id']],
        ['event' => 'customer.rg_flag.set', 'consumer' => 'Doc14/SmsSuppression', 'schema_version' => 1, 'reads' => ['tenant_id', 'customer_id']],
        ['event' => 'customer.rg_flag.cleared', 'consumer' => 'Doc14/SmsSuppression', 'schema_version' => 1, 'reads' => ['tenant_id', 'customer_id']],
    ];
}

it('pins the eight consumed events exactly, reads ⊆ payload, schema_version current, the catalog names the consumer back', function (): void {
    $entries = doc14ConsumerEntries();
    expect(array_map(static fn (array $e): string => $e['event'], $entries))->toBe([
        'ticket.resolved', 'ticket.created', 'ticket.assigned', 'ticket.closed',
        'automation.sms.requested', 'customer.suppression.set', 'customer.rg_flag.set', 'customer.rg_flag.cleared',
    ]);

    foreach ($entries as $entry) {
        $definition = EventCatalog::definition($entry['event']);
        expect(array_diff($entry['reads'], $definition['payload']))->toBe([], $entry['event'].': a read outside the payload')
            ->and($entry['schema_version'])->toBe($definition['schema_version'], $entry['event'].': schema_version drifted — reconcile deliberately')
            ->and($definition['consumers'])->toContain($entry['consumer']);
    }
});

it('A-45: every declared relationship is an IMPLEMENTED, registered consumer on its event — and each runs on the sms-transactional family', function (): void {
    $expected = [
        'ticket.resolved' => App\Jobs\Sms\Consumers\TicketResolvedSms::class,
        'ticket.created' => App\Jobs\Sms\Consumers\TicketCreatedSms::class,
        'ticket.assigned' => App\Jobs\Sms\Consumers\TicketAssignedSms::class,
        'ticket.closed' => App\Jobs\Sms\Consumers\TicketClosedSms::class,
        'automation.sms.requested' => App\Jobs\Sms\Consumers\AutomationSmsRequested::class,
        'customer.suppression.set' => App\Jobs\Sms\Consumers\SuppressionSetSms::class,
        'customer.rg_flag.set' => App\Jobs\Sms\Consumers\RgFlagSmsSuppression::class,
        'customer.rg_flag.cleared' => App\Jobs\Sms\Consumers\RgFlagSmsSuppression::class,
    ];
    foreach ($expected as $event => $consumer) {
        expect(ConsumerRegistry::consumersFor($event))->toContain($consumer);
        $job = new $consumer('01a00000-0000-7000-8000-000000000001');
        expect($job->queue)->toBe('sms-transactional');
    }
    // the registry carries EXACTLY these SMS consumers — and ticket.updated has
    // none (the manual notify door's event; window_reengagement agent-initiated)
    $registered = [];
    foreach (ConsumerRegistry::map() as $event => $consumers) {
        foreach ($consumers as $class) {
            if (str_contains($class, 'Sms\\Consumers')) {
                $registered[$event][] = $class;
            }
        }
    }
    $expectedByEvent = array_map(static fn (string $c): array => [$c], $expected);
    ksort($registered);
    ksort($expectedByEvent);
    expect($registered)->toBe($expectedByEvent);
});
