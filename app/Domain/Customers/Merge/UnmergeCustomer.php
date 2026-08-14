<?php

declare(strict_types=1);

namespace App\Domain\Customers\Merge;

use App\Domain\Customers\Rollups\CustomerRollups;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * FR-13's 30-day undo. AC-03's bar: PRE-MERGE objects restore
 * BYTE-IDENTICALLY (the snapshot lists are the authority — snapshot diff
 * = ∅); objects CREATED AFTER the merge stay with the survivor and are
 * LISTED in the preview (computed as current-state minus the snapshot's
 * lists — D09's frame renders exactly this split).
 *
 * EC-04: chains undo OUTERMOST-FIRST — while the survivor is itself a
 * tombstone, this link refuses with the chain explained (D10's frame);
 * separate the outer merge first. The window is DB time against expires_at;
 * undone_at (the one app-updatable column) latches a snapshot as reversed.
 */
final class UnmergeCustomer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CustomerMerge $merge,
        private readonly CustomerRollups $rollups,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The undo preview: what comes back vs what stays (post-merge objects).
     *
     * @return array{restores: array<string, int>, stays: array<string, int>, expires_at: string, undoable: bool, blocked_by_chain: bool}
     */
    public function preview(string $snapshotId): array
    {
        $snapshot = $this->snapshot($snapshotId);
        /** @var array{moved: array{conversations: list<string>, tickets: list<string>, notes: list<string>, identities: list<string>}} $payload */
        $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);

        $stays = [];
        $restores = [];
        foreach ([['conversations', 'conversations'], ['tickets', 'tickets'], ['customer_notes', 'notes'], ['customer_identities', 'identities']] as [$table, $key]) {
            $restores[$key] = count($payload['moved'][$key]);
            // Post-merge arrivals: on the survivor NOW, absent from the
            // snapshot's lists — they stay, and the preview says so (FR-13).
            $stays[$key] = DB::table($table)
                ->where('customer_id', $snapshot->survivor_id)
                ->whereNotIn('id', $payload['moved'][$key])
                ->count();
        }

        $survivorTombstoned = DB::table('customers')->where('id', $snapshot->survivor_id)->value('merged_into_id') !== null;
        /** @var object{ok: bool} $window */
        $window = DB::selectOne('SELECT expires_at > clock_timestamp() AS ok FROM customer_merge_snapshots WHERE id = ?', [$snapshotId]);
        $inWindow = (bool) $window->ok;

        return [
            'restores' => $restores,
            'stays' => $stays,
            'expires_at' => (string) $snapshot->expires_at,
            'undoable' => $inWindow && ! $survivorTombstoned && $snapshot->undone_at === null,
            'blocked_by_chain' => $survivorTombstoned,
        ];
    }

    public function undo(string $snapshotId, string $reason, string $actorId): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('an un-merge requires a reason (SEC-02/FR-13)');
        }

        $snapshot = $this->snapshot($snapshotId);

        if ($snapshot->undone_at !== null) {
            throw new InvalidArgumentException('this merge was already reversed');
        }
        /** @var object{ok: bool} $window */
        $window = DB::selectOne('SELECT expires_at > clock_timestamp() AS ok FROM customer_merge_snapshots WHERE id = ?', [$snapshotId]);
        if (! (bool) $window->ok) {
            throw new InvalidArgumentException('the 30-day undo window has passed — this merge is permanent (FR-13)');
        }
        // EC-04: outermost first. A tombstoned survivor means an OUTER merge
        // exists; unpicking this one first would leave tickets pointing at a
        // profile that no longer stands on its own (D10's words).
        if (DB::table('customers')->where('id', $snapshot->survivor_id)->value('merged_into_id') !== null) {
            throw new InvalidArgumentException('undo runs outermost-first — separate the outer merge before this one (EC-04)');
        }

        // ONE restoration machinery (the snapshot lists are the single
        // authority); attribution is OURS — staff, with the reason.
        $this->merge->restore($snapshotId);

        DB::transaction(function () use ($snapshot, $reason, $actorId): void {
            $this->events->publish('customer.unmerged', [
                'tenant_id' => $this->context->tenantId(),
                'survivor_id' => $snapshot->survivor_id,
                'restored_id' => $snapshot->merged_id,
                'snapshot_id' => $snapshot->id,
            ]);
            $this->audit->record('customer.unmerged', 'customer', $snapshot->survivor_id, AuditActor::Staff, [
                'reason' => $reason,
                'snapshot_id' => $snapshot->id,
                'restored_id' => $snapshot->merged_id,
            ], actorId: $actorId);
        });

        $this->rollups->recompute($snapshot->survivor_id);
        $this->rollups->recompute($snapshot->merged_id);
    }

    /** @return object{id: string, survivor_id: string, merged_id: string, payload: string, expires_at: string, undone_at: ?string} */
    private function snapshot(string $snapshotId): object
    {
        /** @var object{id: string, survivor_id: string, merged_id: string, payload: string, expires_at: string, undone_at: ?string}|null $row */
        $row = DB::table('customer_merge_snapshots')->where('id', $snapshotId)
            ->first(['id', 'survivor_id', 'merged_id', 'payload', 'expires_at', 'undone_at']);

        if ($row === null) {
            throw new RuntimeException('merge snapshot not found');
        }

        return $row;
    }
}
