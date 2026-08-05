<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Realtime;

use App\Events\Conversations\RealtimePush;
use App\Support\Tenancy\TenantContext;
use DomainException;

/**
 * FR-25's one door to the socket layer. Every payload leaves through here, and
 * the door enforces the contract:
 *  - `v: 1` stamped on every payload (T-06's schema version — clients key
 *    compatibility on it; bumping it is a frozen-contract change);
 *  - ≤ 32 KB serialized, refused loudly over — a payload that big is inlining
 *    something (FR-25: media rides as signed URLs, NEVER bytes; the byte guard
 *    is the mechanism that makes the rule physical);
 *  - SEC-06: outside production, a payload naming a tenant other than the
 *    bound one throws AT EMIT — the test asserts the fence, production keeps
 *    the fast path.
 *
 * Realtime is deliberately EPHEMERAL fan-out: at-most-once, no outbox — a lost
 * socket frame is EC-12's sync-endpoint recovery, not a durability bug. The
 * durable consumers (AI, routing, counters) ride the bus; sockets ride this.
 *
 * Channels live under Doc 02's pinned tenant namespace:
 * tenant.{tenant}.conversation.{id} (presence) and tenant.{tenant}.user.{id}.
 */
final class RealtimePusher
{
    public const MAX_PAYLOAD_BYTES = 32_768;

    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $payload */
    public function toConversation(string $conversationId, string $event, array $payload): void
    {
        $this->push('conversation.'.$conversationId, $event, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function toUser(string $userId, string $event, array $payload): void
    {
        $this->push('user.'.$userId, $event, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function push(string $channelSuffix, string $event, array $payload): void
    {
        $tenantId = $this->context->tenantId();
        $payload = ['v' => 1] + $payload;

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            throw new DomainException(sprintf(
                'realtime payload is %d bytes — over FR-25\'s 32 KB ceiling. Media rides as signed URLs, never inlined.',
                strlen($serialized),
            ));
        }

        if (config('app.env') !== 'production') {
            $this->assertNoForeignTenantIds($payload, $tenantId);
        }

        RealtimePush::dispatch(sprintf('tenant.%s.%s', $tenantId, $channelSuffix), $event, $payload);
    }

    /**
     * SEC-06 (dev/staging): the payload must not carry another tenant's id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertNoForeignTenantIds(array $payload, string $tenantId): void
    {
        $declared = $payload['tenant_id'] ?? null;

        if (is_string($declared) && $declared !== $tenantId) {
            throw new DomainException('realtime payload names a FOREIGN tenant id (SEC-06) — emit refused.');
        }
    }
}
