<?php

declare(strict_types=1);

namespace App\Domain\Automation\Suppression;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-03 `suppress` — the marketing-suppression flag Doc 08 FR-15's
 * consumers read (RIDER 5 table C; Doc 12-owned, never deleted, cleared by
 * outcome — the rg_flag history posture). ONE live suppression per customer
 * (partial unique); a re-suppress while live is an honest no-op. Setting it
 * publishes `customer.suppression.set` (the first event-family filing's
 * cross-document entry) and audits; a staff clear audits only (consumers
 * re-read the reader contract). THE READER CONTRACT: isSuppressed() — what
 * Doc 14's SMS guard ([D14]) and Doc 12's own send-SMS-template arm call.
 */
final class MarketingSuppression
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function isSuppressed(string $customerId): bool
    {
        return DB::table('customer_marketing_suppressions')->where('customer_id', $customerId)->whereNull('cleared_at')->exists();
    }

    /**
     * @return string|null the new suppression id, or null when one was already live
     */
    public function suppress(string $customerId, string $reason, string $source, ?string $ruleRef, ?string $ruleId, ?string $actorId = null): ?string
    {
        if ($this->isSuppressed($customerId)) {
            return null;
        }
        $id = (string) Str::uuid7();

        return DB::transaction(function () use ($id, $customerId, $reason, $source, $ruleRef, $ruleId, $actorId): string {
            DB::table('customer_marketing_suppressions')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'customer_id' => $customerId,
                'reason' => $reason, 'source' => $source, 'rule_ref' => $ruleRef,
            ]);
            $this->events->publish('customer.suppression.set', [
                'tenant_id' => $this->context->tenantId(), 'customer_id' => $customerId, 'rule_id' => $ruleId, 'reason' => $reason,
            ]);
            $this->audit->record('customer.marketing_suppression.changed', 'customer', $customerId,
                $source === 'rule' ? AuditActor::System : AuditActor::Staff,
                ['customer_id' => $customerId, 'kind' => 'set', 'suppression_id' => $id] + ($ruleRef === null ? [] : ['rule_ref' => $ruleRef]),
                actorId: $actorId);

            return $id;
        });
    }

    /** A staff clear: the live row closes by outcome, audited; history stays. */
    public function clear(string $customerId, string $actorId): bool
    {
        return DB::transaction(function () use ($customerId, $actorId): bool {
            $cleared = DB::table('customer_marketing_suppressions')->where('customer_id', $customerId)->whereNull('cleared_at')
                ->update(['cleared_at' => DB::raw('clock_timestamp()'), 'cleared_by' => $actorId, 'updated_at' => DB::raw('clock_timestamp()')]);
            if ($cleared === 0) {
                return false;
            }
            $this->audit->record('customer.marketing_suppression.changed', 'customer', $customerId, AuditActor::Staff,
                ['customer_id' => $customerId, 'kind' => 'cleared'], actorId: $actorId);

            return true;
        });
    }
}
