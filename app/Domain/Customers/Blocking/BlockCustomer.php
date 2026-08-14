<?php

declare(strict_types=1);

namespace App\Domain\Customers\Blocking;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FR-18's customer-level block — belt AND braces BY DESIGN (T-06/FS-04):
 * this class flips the customer columns and publishes the event; the DURABLE
 * cascade (BlockingCascadeConsumer) quiets every conversation, and the
 * OUTBOUND GUARD (CustomerSendGuard, checked inside Doc 04's compose door)
 * refuses sends INDEPENDENTLY — so a conversation created mid-cascade is
 * still refused, and a crashed cascade leaves the guard holding (FS-04). Do
 * not "simplify" either layer away; the document built two on purpose.
 *
 * `blocked_at` is PINNED-PROVENANCE (SCHEMA AMENDMENT 2): a BYTE-COPY of the
 * block's own event stamp — the outbox row's `occurred_at` (Postgres now()
 * at publish, the A-33 physics) — never a fresh app clock. Sabotage
 * amendment #16's THIRD control asserts the byte-equality.
 *
 * Reasons are MANDATORY both directions (FR-18: "reason mandatory"; §8:
 * unblock is "a distinct permissioned action with reason" — riding
 * customers.block per A-39). Unblocking an unblocked customer is the A-37
 * noop (null return) for the step-11 door to declare.
 */
final class BlockCustomer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string|null the event id; null = no-change (already blocked) */
    public function block(string $customerId, string $reason, string $actorId): ?string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('a customer block requires a reason (FR-18)');
        }
        if ((bool) DB::table('customers')->where('id', $customerId)->value('blocked')) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $reason, $actorId): string {
            $eventId = $this->events->publish('customer.blocked', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'blocked_by' => $actorId,
            ]);

            // The byte-copy discipline: the current-state stamp IS the block
            // event's own outbox stamp.
            // publish() returns the EVENT id; the outbox row keys it as
            // event_id (the row's own id is the relay cursor).
            /** @var object{occurred_at: string} $outbox */
            $outbox = DB::selectOne('SELECT occurred_at::text AS occurred_at FROM outbox WHERE event_id = ?', [$eventId]);

            DB::table('customers')->where('id', $customerId)->update([
                'blocked' => true,
                'blocked_reason' => $reason,
                'blocked_by' => $actorId,
                'blocked_at' => $outbox->occurred_at,
            ]);

            $this->audit->record('customer.blocked', 'customer', $customerId, AuditActor::Staff, ['reason' => $reason], actorId: $actorId);

            return $eventId;
        });
    }

    /** @return string|null the event id; null = no-change (not blocked — the A-37 noop) */
    public function unblock(string $customerId, string $reason, string $actorId): ?string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('an unblock requires a reason (§8; A-39)');
        }
        if (! (bool) DB::table('customers')->where('id', $customerId)->value('blocked')) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $reason, $actorId): string {
            $eventId = $this->events->publish('customer.unblocked', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'unblocked_by' => $actorId,
            ]);

            DB::table('customers')->where('id', $customerId)->update([
                'blocked' => false,
                'blocked_reason' => null,
                'blocked_by' => null,
                'blocked_at' => null,
            ]);

            $this->audit->record('customer.unblocked', 'customer', $customerId, AuditActor::Staff, ['reason' => $reason], actorId: $actorId);

            return $eventId;
        });
    }
}
