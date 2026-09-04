<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Realtime;

use App\Events\Conversations\RealtimePush;
use App\Support\Tenancy\TenantContext;
use DomainException;

/**
 * Doc 13 T-04 / FR-21 — the widget's one door to the socket layer, the
 * VISITOR half of Doc 04 FR-25's RealtimePusher (which owns the staff
 * conversation/user channels and is not touched — this class is Doc 13's own
 * door, same guards, different channel family).
 *
 * Channels live under Doc 02's pinned visitor namespace, whose callback in
 * routes/channels.php (`tenant.{t}.visitor.{v}.{suffix}`, guards => widget)
 * admits exactly the visitor principal that owns `{v}` — Doc 13 writes NO
 * channel callback of its own; every suffix inherits that boundary:
 *
 *   tenant.{t}.visitor.{v}.thread   the conversation frames (message, typing,
 *                                   stream delta, receipts)
 *   tenant.{t}.visitor.{v}.queue    queue position / band changes (T-05)
 *
 * `thread`, not `conversation`: RealtimePush turns a name containing
 * ".conversation." into a PRESENCE channel; the visitor's channel must stay
 * PRIVATE (no member list is ever exposed to a visitor).
 *
 * Same physics as Doc 04's door: `v: 1` stamped, ≤ 32 KB refused loudly (media
 * rides as descriptors + signed URLs from the cursor endpoint, NEVER bytes),
 * SEC-06 foreign-tenant fence outside production. Realtime is EPHEMERAL
 * fan-out — a lost frame is the cursor endpoint's recovery (FR-21: one sync
 * model), never a durability bug.
 */
final class WebchatPusher
{
    public const MAX_PAYLOAD_BYTES = 32_768;

    public const SUFFIX_THREAD = 'thread';

    public const SUFFIX_QUEUE = 'queue';

    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $payload */
    public function toVisitor(string $visitorId, string $suffix, string $event, array $payload): void
    {
        if ($visitorId === '' || $suffix === '' || str_contains($suffix, '.')) {
            throw new DomainException('a visitor push needs a visitor id and a single-segment channel suffix.');
        }

        $tenantId = $this->context->tenantId();
        $payload = ['v' => 1] + $payload;
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            throw new DomainException(sprintf(
                'webchat realtime payload is %d bytes — over the 32 KB ceiling (Doc 04 FR-25 / Doc 13 §10). Media rides as descriptors, never inlined.',
                strlen($serialized),
            ));
        }

        if (config('app.env') !== 'production') {
            $declared = $payload['tenant_id'] ?? null;
            if (is_string($declared) && $declared !== $tenantId) {
                throw new DomainException('webchat realtime payload names a FOREIGN tenant id (SEC-06) — emit refused.');
            }
        }

        RealtimePush::dispatch(sprintf('tenant.%s.visitor.%s.%s', $tenantId, $visitorId, $suffix), $event, $payload);
    }
}
