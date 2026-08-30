<?php

declare(strict_types=1);

namespace App\Domain\Org\Restructure;

use App\Domain\Routing\Pipeline\PositionInQueue;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Placement\PlaceInQueue;
use App\Domain\Tickets\Transfer\TransferTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Cache\TenantCache;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-18 / NFR-05 / SEC-04 / EC-06 — the CHUNKED MOVER behind both delete wizards.
 *
 * Moves the tickets one CHUNK at a time (CHUNK rows per pass, each its own transaction — intake
 * for sibling queues never pauses: no table lock, no long transaction), every ticket through a
 * Doc 06 door (PlaceInQueue for a queue-to-queue move; TransferTicket for a department move —
 * routing never writes a ticket row), and per chunk:
 *   SEC-04 — the operator admin's permissions are RE-RESOLVED live (Doc 03's job semantics; the
 *            resolution instant recorded); a chunk under lost rights STOPS the move, nothing done
 *            silently;
 *   EC-06 — the waiting-set mirror MIGRATES: a waiting ticket leaves the old ZSET and joins the
 *            target's with the engine's own score (the same PositionInQueue the pipeline uses);
 *   progress — `org.restructure.executed` audited per chunk (chunk / chunk_total) and the cached
 *            progress row updated for the wizard's per-chunk status (T-06's cache shape).
 */
final class ChunkedMover
{
    public const CHUNK = 500;

    public function __construct(
        private readonly PlaceInQueue $place,
        private readonly TransferTicket $transfer,
        private readonly WaitingSets $waiting,
        private readonly PositionInQueue $position,
        private readonly CapabilityMapProvider $maps,
        private readonly AuditLogger $audit,
        private readonly TenantCache $cache,
    ) {}

    /**
     * Move every ticket the query names into `$targetQueueId` (same or another department).
     *
     * @param  callable(int $chunk, int $total): void|null  $betweenChunks  the isolation drill's hook
     * @return array{moved: int, chunks: int}
     */
    public function move(string $wizard, string $targetId, \Illuminate\Database\Query\Builder $tickets, string $targetQueueId, User $actor, string $requiredPermission, ?callable $betweenChunks = null): array
    {
        /** @var object{id: string, department_id: string}|null $target */
        $target = DB::table('queues')->where('id', $targetQueueId)->whereNull('deleted_at')->where('is_active', true)->first(['id', 'department_id']);
        if ($target === null) {
            throw new RestructureRefused('target_queue_invalid', 'the target queue must be live and active');
        }
        $total = (clone $tickets)->count();
        $chunkTotal = max(1, (int) ceil($total / self::CHUNK));
        $moved = 0;
        $chunk = 0;
        while (true) {
            $chunk++;
            // SEC-04: live rights, per chunk.
            $map = $this->maps->mapFor($actor);
            /** @var object{t: string} $clock */
            $clock = DB::selectOne('SELECT clock_timestamp() AS t');
            if (! $map->allows($requiredPermission)) {
                $this->progress($wizard, $targetId, $chunk, $chunkTotal, $moved, 'stopped_permission_lost');
                throw new RestructureRefused('permission_lost', sprintf('%s no longer held at chunk %d — the move stopped', $requiredPermission, $chunk));
            }
            /** @var list<string> $ids */
            $ids = (clone $tickets)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($ids === []) {
                $chunk--;   // nothing left: this pass is not a chunk

                break;
            }
            $movedInChunk = DB::transaction(function () use ($ids, $target, $actor): int {
                $n = 0;
                foreach ($ids as $id) {
                    $ticket = Ticket::query()->find($id);
                    if (! $ticket instanceof Ticket) {
                        continue;
                    }
                    $from = $ticket->getAttribute('queue_id');
                    $from = is_string($from) ? $from : null;
                    $fresh = $ticket->department_id !== $target->department_id
                        ? $this->transfer->transfer($ticket, $target->department_id, $actor, $target->id)   // Doc 06 FR-22: department + queue in one act
                        : $this->place->place($ticket, $target->id, $actor, 'restructure');
                    // EC-06: the mirror migrates with the row.
                    if ($from !== null) {
                        $this->waiting->remove($from, $id);
                    }
                    if ($fresh->assignee_id === null && in_array((string) $fresh->status, ReferenceInventory::OPEN_FAMILY, true)) {
                        $pos = $this->position->compute($target->id, ['id' => $id, 'priority' => (string) $fresh->priority, 'reopened_count' => (int) $fresh->reopened_count, 'customer_id' => $fresh->customer_id, 'queued_at' => $fresh->getAttribute('queued_at'), 'tags' => []]);
                        $queuedAt = $fresh->getAttribute('queued_at');
                        $this->waiting->add($target->id, $id, WaitingSets::score($pos['starved'], $pos['rule_score'], is_string($queuedAt) ? (int) strtotime($queuedAt) : time()));
                    }
                    $n++;
                }

                return $n;
            });
            $moved += $movedInChunk;
            $this->audit->record('org.restructure.executed', 'department', $target->department_id, AuditActor::Staff, [
                'wizard' => $wizard, 'target_id' => $targetId, 'chunk' => $chunk, 'chunk_total' => $chunkTotal, 'moved' => $movedInChunk, 'actor_permissions_resolved_at' => $clock->t,
            ], actorId: $actor->identifier());
            $this->progress($wizard, $targetId, $chunk, $chunkTotal, $moved, 'running');
            if ($betweenChunks !== null) {
                $betweenChunks($chunk, $chunkTotal);
            }
            if ($movedInChunk < self::CHUNK && (clone $tickets)->doesntExist()) {
                break;
            }
        }
        $this->progress($wizard, $targetId, $chunk, $chunkTotal, $moved, 'done');

        return ['moved' => $moved, 'chunks' => $chunk];
    }

    /** The wizard's per-chunk status row (cached, TTL one hour; invalidated by the next write). */
    public function progress(string $wizard, string $targetId, int $chunk, int $chunkTotal, int $moved, string $state): void
    {
        $this->cache->put('restructure:'.$wizard.':'.$targetId, ['chunk' => $chunk, 'chunk_total' => $chunkTotal, 'moved' => $moved, 'state' => $state], 3600, 'org.restructure.executed');
    }

    /** @return array{chunk: int, chunk_total: int, moved: int, state: string}|null */
    public function readProgress(string $wizard, string $targetId): ?array
    {
        $row = $this->cache->get('restructure:'.$wizard.':'.$targetId);
        if (! is_array($row) || ! is_int($row['chunk'] ?? null) || ! is_int($row['chunk_total'] ?? null) || ! is_int($row['moved'] ?? null) || ! is_string($row['state'] ?? null)) {
            return null;
        }

        return ['chunk' => $row['chunk'], 'chunk_total' => $row['chunk_total'], 'moved' => $row['moved'], 'state' => $row['state']];
    }
}
