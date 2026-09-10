<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueResult;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Domain\Sms\Support\PhoneMask;
use App\Jobs\OutboxConsumer;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The shared spine of Doc 14's durable consumers (CONSUMER AMENDMENT 1;
 * ADR 0011; A-45: a declared consumer is IMPLEMENTED, never a hook):
 *
 *  - the per-event toggle is checked FIRST (`sms.events.<event>.enabled`) —
 *    off means nothing exists, not a skipped row;
 *  - idempotent on `event_id` (C-09) BY CAUSATION: every row this family
 *    creates publishes its lifecycle event with `causation_id` = the consumed
 *    event's id, so a redelivery finds its own trace and stops — hours later
 *    as well as seconds (the duplicate window alone would not survive a
 *    late re-drive);
 *  - the customer is RE-FETCHED by `ticket_id` (SEC-07's posture — values
 *    come from truth, payloads carry identifiers);
 *  - every enqueue goes through the ONE EnqueueSms door, inside this
 *    consumer's own transaction.
 */
abstract class SmsEventConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'sms-transactional';
    }

    /**
     * @param  list<string>  $names
     * @return array{name: string, payload: array<string, mixed>}|null
     */
    protected function consumedEvent(array $names): ?array
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null || ! in_array($event->name, $names, true)) {
            return null;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);

        return ['name' => $event->name, 'payload' => $payload];
    }

    protected function toggleOn(SendableEvent $event): bool
    {
        return app(SettingsRepository::class)->get('sms.events.'.$event->value.'.enabled') === true;
    }

    /** C-09 by causation: has this delivery already left its trace? */
    protected function alreadyProduced(): bool
    {
        return DB::table('outbox')->where('causation_id', $this->eventId)->where('name', 'like', 'sms.%')->exists()
            || DB::table('events')->where('causation_id', $this->eventId)->where('name', 'like', 'sms.%')->exists();
    }

    /** The one enqueue path: re-fetch the ticket, cross-check any payload customer id, walk the door. */
    protected function enqueueForTicket(SendableEvent $event, string $ticketId, ?string $payloadCustomerId = null): ?EnqueueResult
    {
        /** @var object{customer_id: string, conversation_id: string}|null $ticket */
        $ticket = DB::table('tickets')->where('id', $ticketId)->first(['customer_id', 'conversation_id']);
        if ($ticket === null) {
            return null; // a stale delivery for a row this tenant no longer shows: dropped harmlessly
        }
        if ($payloadCustomerId !== null && $payloadCustomerId !== $ticket->customer_id) {
            // SEC-07: truth wins; the payload id is read as a cross-check only
            Log::warning('sms consumer: payload customer differs from the ticket row — truth used', ['event_id' => $this->eventId, 'ticket_id' => $ticketId]);
        }

        $result = DB::transaction(fn (): EnqueueResult => app(EnqueueSms::class)->enqueue(
            new SendRequest($event, $ticket->customer_id, $ticketId, $ticket->conversation_id),
            $this->eventId,
        ));

        if ($result->status === 'refused_no_phone') {
            Log::info('sms consumer: customer has no phone identity — nothing to send', ['event_id' => $this->eventId, 'ticket_id' => $ticketId, 'event' => $event->value]);
        }

        return $result;
    }

    /**
     * The suppression re-evaluation both suppression consumers share: every HELD
     * non-essential row of the customer becomes `skipped:{reason}` (held→skipped is
     * a legal edge); essential-service-continuity events stay held (FR-15's law).
     */
    protected function skipHeldNonEssential(string $customerId, string $reason): int
    {
        $skipped = 0;
        foreach (\App\Models\SmsMessage::query()->where('customer_id', $customerId)->where('state', 'held')->get() as $row) {
            $event = SendableEvent::tryFrom($row->event);
            if ($event === null || $event->essentialServiceContinuity()) {
                continue;
            }
            DB::transaction(function () use ($row, $reason): void {
                $row->skip_reason = $reason;
                $row->state = app(\App\Domain\Sms\State\SmsDeliveryStateMachine::class)
                    ->transition(\App\Domain\Sms\State\SmsDeliveryState::Held, \App\Domain\Sms\State\SmsDeliveryState::Skipped)->value;
                $row->save();
                app(\App\Domain\Sms\Pipeline\SmsLifecycle::class)->publish('sms.skipped', $row, $reason, causationId: $this->eventId);
            });
            app(\App\Support\Timers\DueSet::class)->cancel('sms_hold_release', \App\Support\Timers\DueSet::member($row->tenant_id, $row->identifier()));
            Log::info('sms suppression: held row skipped', ['sms_message_id' => $row->identifier(), 'reason' => $reason, 'to_masked' => PhoneMask::forLog($row->phone)]);
            $skipped++;
        }

        return $skipped;
    }
}
