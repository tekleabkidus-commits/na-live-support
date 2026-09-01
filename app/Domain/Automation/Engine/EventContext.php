<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use App\Domain\Routing\Hours\HoursContract;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 T-02 — the MATERIALIZED event context: ticket + customer + message +
 * AI facts assembled ONCE per event, evaluated by every candidate rule.
 *
 * Facts are READS of owned truth, never re-implementations of owned CHECKS
 * (BR-02/§7: status legality, scope, permissions belong to their doors — the
 * ACTIONS call those doors; conditions only read stored state). The fact
 * vocabulary is FR-02's, addressed dot-style:
 *   ticket.status|priority|department_id|queue_id|channel|tags|age_minutes|custom.<key>
 *   customer.tags|language|country|blocked|vip_source|custom.<key>
 *   message.body|language
 *   ai.intent|sentiment|confidence|urgency
 *   time.within_business_hours|day_of_week
 */
final class EventContext
{
    /** @param array<string, mixed> $facts */
    private function __construct(public readonly array $facts, public readonly ?string $ticketId, public readonly int $automationDepth) {}

    public static function assemble(string $eventId, HoursContract $hours): self
    {
        /** @var object{name: string, payload: string, metadata: ?string}|null $event */
        $event = DB::table('outbox')->where('event_id', $eventId)->first(['name', 'payload', 'metadata']);
        if ($event === null) {
            return new self([], null, 0);
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);
        $metadata = $event->metadata === null ? [] : json_decode($event->metadata, true, 8, JSON_THROW_ON_ERROR);
        $depth = is_array($metadata) && is_int($metadata['automation_depth'] ?? null) ? $metadata['automation_depth'] : 0;

        $facts = ['event.name' => $event->name];
        $ticketId = is_string($payload['ticket_id'] ?? null) ? $payload['ticket_id'] : null;
        $customerId = is_string($payload['customer_id'] ?? null) ? $payload['customer_id'] : null;
        $messageId = is_string($payload['message_id'] ?? null) ? $payload['message_id'] : null;

        if ($ticketId !== null) {
            $ticket = DB::table('tickets')->where('id', $ticketId)->first();
            if ($ticket !== null) {
                $customerId ??= is_string($ticket->customer_id) ? $ticket->customer_id : null;
                $formData = is_string($ticket->form_data) ? json_decode($ticket->form_data, true, 16) : [];
                $formData = is_array($formData) ? $formData : [];
                $facts += [
                    'ticket.status' => $ticket->status,
                    'ticket.priority' => $ticket->priority,
                    'ticket.department_id' => $ticket->department_id,
                    'ticket.queue_id' => $ticket->queue_id,
                    'ticket.tags' => is_string($ticket->tags) ? json_decode($ticket->tags, true, 4) : [],
                    'ticket.age_minutes' => is_string($ticket->created_at) ? self::minutesSince($ticket->created_at) : 0,
                ];
                $facts['ticket.channel'] = DB::table('conversations')->where('id', $ticket->conversation_id)->value('channel');
                foreach (is_array($formData['custom'] ?? null) ? $formData['custom'] : [] as $key => $value) {
                    $facts['ticket.custom.'.$key] = $value;
                }
            }
        }

        if ($customerId !== null) {
            $customer = DB::table('customers')->where('id', $customerId)->first();
            if ($customer !== null) {
                $facts += [
                    'customer.language' => $customer->language ?? null,
                    'customer.country' => $customer->country ?? null,
                    'customer.blocked' => (bool) ($customer->blocked ?? false),
                    'customer.tags' => is_string($customer->tags ?? null) ? json_decode($customer->tags, true, 4) : [],
                ];
                /** @var list<object{field_key: string, value: ?string}> $values */
                $values = DB::select('SELECT field_key, value FROM customer_field_values WHERE customer_id = ?', [$customerId]);
                foreach ($values as $v) {
                    $facts['customer.custom.'.$v->field_key] = $v->value;
                    if ($v->field_key === 'betting_username' || $v->field_key === 'player_id') {
                        $facts['customer.vip_source'] ??= $v->value; // FR-02's VIP-source field rides the Doc 08 seeded set
                    }
                }
            }
        }

        if ($messageId !== null) {
            $message = DB::table('messages')->where('id', $messageId)->first(['body', 'language']);
            if ($message !== null) {
                $facts['message.body'] = $message->body;
                $facts['message.language'] = $message->language ?? null;
            }
            /** @var object{output: string}|null $classify */
            $classify = DB::selectOne(
                "SELECT output FROM ai_decisions WHERE task = 'classify_message' AND message_id = ? ORDER BY created_at DESC LIMIT 1",
                [$messageId],
            );
            if ($classify !== null) {
                /** @var array<string, mixed> $out */
                $out = json_decode($classify->output, true, 8) ?: [];
                $facts += [
                    'ai.intent' => $out['intent'] ?? null,
                    'ai.sentiment' => $out['sentiment'] ?? null,
                    'ai.confidence' => $out['confidence'] ?? null,
                    'ai.urgency' => $out['urgency'] ?? null,
                ];
            }
        }

        $now = $hours->now();
        $departmentId = is_string($facts['ticket.department_id'] ?? null) ? $facts['ticket.department_id'] : null;
        $facts['time.within_business_hours'] = $hours->isOpen($departmentId, $now);
        $facts['time.day_of_week'] = (int) $now->setTimezone($hours->timezone())->format('N');

        return new self($facts, $ticketId, $depth);
    }

    private static function minutesSince(string $instant): int
    {
        /** @var object{mins: string} $row */
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM (clock_timestamp() - ?::timestamptz)) / 60 AS mins', [$instant]);

        return (int) floor((float) $row->mins);
    }
}
