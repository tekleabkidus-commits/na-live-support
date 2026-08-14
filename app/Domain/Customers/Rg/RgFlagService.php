<?php

declare(strict_types=1);

namespace App\Domain\Customers\Rg;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-14/FR-15's STORAGE half — the one writer of the RG flag ([D09] owns
 * detection; [D11] routing; this document owns storage and visibility only,
 * §3's explicit split).
 *
 * Every set and clear is an INSERT into rg_flag_history (insert-only BY
 * GRANT — AC-05's immutability is physics) plus the current-state columns on
 * customers. `rg_flag_set_at` is PINNED-PROVENANCE: a BYTE-COPY of the
 * owning history row's own DB-time created_at, read back via RETURNING —
 * never a fresh clock (sabotage amendment #16, control #2; switching this
 * to now() must turn RgStorageTest's byte-equality red).
 *
 * source=ai (FR-14): actor is NULL — the (source='ai') = (actor_id IS NULL)
 * CHECK is the schema's own enforcement — and the triggering-excerpt
 * reference is MANDATORY ("always with the triggering excerpt reference");
 * the producer is Doc 09's ([D09] — fixtures drive it here).
 *
 * Clearing requires the permission + a reason (FR-15; the DOOR maps
 * customers.rg_flag.set); history is retained forever. Clearing an unset
 * flag is a NO-CHANGE result the caller's door declares as an A-37 causal
 * noop — this service just says so honestly via its return.
 *
 * SEC-02: both directions audited with reasons (high-visibility; Doc 17).
 */
final class RgFlagService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $excerptRef
     * @return string the history row id
     */
    public function set(string $customerId, string $reason, string $source, ?string $actorId, ?array $excerptRef = null): string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('an RG flag set requires a reason (FR-14/SEC-02)');
        }
        if ($source === 'ai' && $excerptRef === null) {
            throw new InvalidArgumentException('an AI-set RG flag always carries its triggering excerpt reference (FR-14)');
        }
        if ($source === 'staff' && $actorId === null) {
            throw new InvalidArgumentException('a staff-set RG flag carries its actor (FR-14/SEC-02)');
        }

        return DB::transaction(function () use ($customerId, $reason, $source, $actorId, $excerptRef): string {
            $history = $this->appendHistory($customerId, 'set', $source, $actorId, $reason, $excerptRef);

            // The byte-copy discipline: the current-state stamp IS the
            // history row's own DB stamp.
            DB::table('customers')->where('id', $customerId)->update([
                'rg_flag' => true,
                'rg_flag_set_by' => $actorId,
                'rg_flag_set_at' => $history->created_at,
                'rg_flag_reason' => $reason,
            ]);

            $this->events->publish('customer.rg_flag.set', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'history_id' => $history->id,
                'source' => $source,
            ]);

            $this->audit->record(
                'customer.rg_flag.set',
                'customer',
                $customerId,
                $source === 'ai' ? AuditActor::System : AuditActor::Staff,
                ['reason' => $reason, 'source' => $source],
                actorId: $actorId,
            );

            return $history->id;
        });
    }

    /** @return string|null the history row id; null = no-change (flag was not set — the door's A-37 noop) */
    public function clear(string $customerId, string $reason, string $actorId): ?string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('an RG flag clear requires a reason (FR-15)');
        }

        $currentlySet = (bool) DB::table('customers')->where('id', $customerId)->value('rg_flag');
        if (! $currentlySet) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $reason, $actorId): string {
            $history = $this->appendHistory($customerId, 'cleared', 'staff', $actorId, $reason, null);

            DB::table('customers')->where('id', $customerId)->update([
                'rg_flag' => false,
                'rg_flag_set_by' => null,
                'rg_flag_set_at' => null,
                'rg_flag_reason' => null,
            ]);

            $this->events->publish('customer.rg_flag.cleared', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'history_id' => $history->id,
            ]);

            $this->audit->record(
                'customer.rg_flag.cleared',
                'customer',
                $customerId,
                AuditActor::Staff,
                ['reason' => $reason],
                actorId: $actorId,
            );

            return $history->id;
        });
    }

    /** @param array<string, mixed>|null $excerptRef
     * @return object{id: string, created_at: string} */
    private function appendHistory(string $customerId, string $action, string $source, ?string $actorId, string $reason, ?array $excerptRef): object
    {
        /** @var object{id: string, created_at: string}|null $row */
        $row = DB::selectOne(
            <<<'SQL'
            INSERT INTO rg_flag_history (id, tenant_id, customer_id, action, source, actor_id, reason, excerpt_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id, created_at::text AS created_at
            SQL,
            [
                (string) new UuidV7,
                $this->context->tenantId(),
                $customerId,
                $action,
                $source,
                $actorId,
                $reason,
                $excerptRef === null ? null : json_encode($excerptRef),
            ],
        );

        if ($row === null) {
            throw new \RuntimeException('rg_flag_history insert returned nothing — investigate.');
        }

        return $row;
    }
}
