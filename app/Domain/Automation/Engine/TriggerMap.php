<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

/**
 * Doc 12 FR-01 — the trigger vocabulary → the catalog events that realize it.
 *
 * The 13 trigger literals are the rules table's CHECK; this map names which
 * outbox events wake each one. Triggers whose events DO NOT EXIST YET are
 * mapped to [] and light up at their landing step (sla.* at step 6,
 * form.submitted at step 10, csat.received at step 12) — the consumer registry
 * grows WITH the events, never ahead of them. `schedule` has no event: the
 * scheduler sweep drives it (step 5).
 */
final class TriggerMap
{
    /** @var array<string, list<string>> trigger → catalog event names */
    public const EVENTS = [
        'ticket.created' => ['ticket.created'],
        'ticket.status_changed' => ['ticket.status_changed'],
        'ticket.priority_changed' => ['ticket.priority_changed'],
        'ticket.transferred' => ['ticket.transferred'],
        'message.received' => ['message.received'],
        'form.submitted' => ['form.submitted'],
        'sla.warned' => ['sla.warned'],
        'sla.breached' => ['sla.breached'],
        'csat.received' => ['csat.received'],
        'telegram.window.closing' => ['telegram.window.closing'],
        'telegram.window.expired' => ['telegram.window.expired'],
        'customer.rg_flag.set' => ['customer.rg_flag.set'],
        'schedule' => [],
    ];

    /**
     * The trigger(s) an event name wakes (usually one).
     *
     * @return list<string>
     */
    public static function triggersFor(string $eventName): array
    {
        $out = [];
        foreach (self::EVENTS as $trigger => $events) {
            if (in_array($eventName, $events, true)) {
                $out[] = $trigger;
            }
        }

        return $out;
    }
}
