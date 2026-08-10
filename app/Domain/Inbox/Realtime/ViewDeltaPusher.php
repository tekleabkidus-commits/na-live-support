<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Realtime;

use App\Events\Conversations\RealtimePush;
use App\Support\Tenancy\TenantContext;
use DomainException;

/**
 * Doc 07 T-02's protocol door — THE DISTINCTION, stated the way Doc 04's
 * RealtimePusher did: view deltas and count pushes are REALTIME PROTOCOL
 * (v:1), not catalog events. Ephemeral at-most-once fan-out; a lost frame is
 * T-07's sync-endpoint recovery, never a durability bug. Durable work rides
 * the outbox + ConsumerRegistry as always.
 *
 * This is Doc 07's OWN protocol door (T-02 names it as a deliverable), not a
 * re-implementation of Doc 04's: the transport is Doc 04's public
 * RealtimePush event (CONSUMED), and the platform rules every protocol door
 * carries — v:1 stamped, the 32 KB ceiling, the SEC-06 emit fence outside
 * production — are enforced here for view channels exactly as RealtimePusher
 * enforces them for conversation/user channels.
 *
 * Delta vocabulary (T-02, closed): {v:1, type:'view_delta', view_key, op:
 * add|patch|remove, id, sort_key(int µs), patch{...row summary}}. Count
 * pushes: {v:1, type:'view_count', view_key, count} — CountPusher owns the
 * debounce.
 */
final class ViewDeltaPusher
{
    public const MAX_PAYLOAD_BYTES = 32_768;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ViewHash $hashes,
    ) {}

    /** @param array<string, mixed> $payload */
    public function toViewKey(string $viewKey, string $event, array $payload): void
    {
        $tenantId = $this->context->tenantId();
        $payload = ['v' => 1] + $payload;

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            throw new DomainException(sprintf(
                'view-channel payload is %d bytes — over the 32 KB ceiling; a list delta that big is inlining content it should reference',
                strlen($serialized),
            ));
        }

        if (config('app.env') !== 'production') {
            $declared = $payload['tenant_id'] ?? null;
            if (is_string($declared) && $declared !== $tenantId) {
                throw new DomainException('view-channel payload names a FOREIGN tenant id (SEC-06) — emit refused.');
            }
        }

        RealtimePush::dispatch(
            sprintf('tenant.%s.view.%s', $tenantId, $this->hashes->forZsetKey($viewKey)),
            $event,
            $payload,
        );
    }

    /** The workspace topbar channel (FR-03 health, FR-26 bell signals). */
    /** @param array<string, mixed> $payload */
    public function toInbox(string $event, array $payload): void
    {
        $tenantId = $this->context->tenantId();
        $payload = ['v' => 1] + $payload;

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            throw new DomainException('inbox-channel payload over the 32 KB ceiling — emit refused.');
        }

        RealtimePush::dispatch(sprintf('tenant.%s.inbox', $tenantId), $event, $payload);
    }
}
