<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

use App\Models\SmsMessage;
use App\Support\Events\EventPublisher;

/**
 * The lifecycle events (EVENT-FAMILY AMENDMENT 1, landed at this step as
 * filed): one CLOSED payload shape for the eight — ids and enums only, never
 * a phone, never a body (SEC-02). `sms.held` alone carries `release_at`.
 * Every publish rides the caller's open transaction (Doc 01 T-03).
 */
final class SmsLifecycle
{
    public function __construct(private readonly EventPublisher $events) {}

    public function publish(string $name, SmsMessage $row, ?string $reason = null, ?string $releaseAt = null, ?string $causationId = null): string
    {
        $payload = [
            'tenant_id' => $row->tenant_id,
            'sms_message_id' => $row->identifier(),
            'event' => $row->event,
            'ticket_id' => $row->ticket_id,
            'customer_id' => $row->customer_id,
            'gateway_id' => $row->gateway_id,
            'lane' => $row->lane,
            'template_version_id' => $row->template_version_id,
            'reason' => $reason,
        ];
        if ($name === 'sms.held') {
            $payload['release_at'] = $releaseAt;
        }

        return $this->events->publish($name, $payload, $causationId);
    }
}
