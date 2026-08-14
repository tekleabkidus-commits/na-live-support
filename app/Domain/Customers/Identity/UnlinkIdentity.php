<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use App\Domain\Customers\Rollups\CustomerRollups;
use App\Models\Customer;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * FR-10, the high-risk mover: the identity goes to a FRESH SHELL and its
 * anchored conversations — with their tickets — move with it ("conversation
 * ownership follows the identity that anchors it"). Reversible only by
 * re-merging (the flow says so); heavily audited with a mandatory reason
 * (SEC-02).
 *
 * EC-05: unlinking the ONLY identity is allowed — the original profile
 * survives with its staff data and zero identities (the "unreachable" chip
 * is step 11's render); the shell carries the conversations.
 *
 * AC-04 BY CONSTRUCTION: this class re-reads UnlinkMoveSet at execution —
 * the same single source the confirmation renders from — and returns
 * exactly what it moved.
 */
final class UnlinkIdentity
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UnlinkMoveSet $moveSet,
        private readonly CustomerRollups $rollups,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{new_customer_id: string, moved_conversations: int, moved_tickets: int}
     */
    public function unlink(string $identityId, string $reason, string $actorId): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('an unlink requires a reason (FR-10/SEC-02)');
        }

        $set = $this->moveSet->for($identityId);
        if ($set === null) {
            throw new RuntimeException('identity not found');
        }

        return DB::transaction(function () use ($set, $reason, $actorId): array {
            $identity = $set['identity'];

            // The fresh shell: created by this staff action (FR-01's enum).
            $shell = Customer::query()->create([
                'is_shell' => true,
                'created_source' => 'staff',
            ]);
            $shellId = $shell->identifier();

            DB::table('customer_identities')->where('id', $identity->id)->update(['customer_id' => $shellId]);

            if ($set['conversation_ids'] !== []) {
                DB::table('conversations')->whereIn('id', $set['conversation_ids'])->update(['customer_id' => $shellId]);
                DB::table('tickets')->whereIn('conversation_id', $set['conversation_ids'])->update(['customer_id' => $shellId]);
            }

            // Both sides' rollups recompute in the SAME transaction — the
            // re-point changed truth for the old profile and the shell alike.
            $this->rollups->recompute($identity->customer_id);
            $this->rollups->recompute($shellId);

            $this->events->publish('customer.created', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $shellId,
                'created_source' => 'staff',
            ]);
            $this->events->publish('customer.identity.unlinked', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $identity->customer_id,
                'identity_id' => $identity->id,
                'type' => $identity->type,
                'new_customer_id' => $shellId,
            ]);

            $this->audit->record(
                'customer.identity.unlinked',
                'customer',
                $identity->customer_id,
                AuditActor::Staff,
                [
                    'reason' => $reason,
                    'type' => $identity->type,
                    'new_customer_id' => $shellId,
                    'moved_conversations' => count($set['conversation_ids']),
                    'moved_tickets' => count($set['ticket_ids']),
                ],
                actorId: $actorId,
            );

            return [
                'new_customer_id' => $shellId,
                'moved_conversations' => count($set['conversation_ids']),
                'moved_tickets' => count($set['ticket_ids']),
            ];
        });
    }
}
