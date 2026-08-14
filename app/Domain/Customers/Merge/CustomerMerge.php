<?php

declare(strict_types=1);

namespace App\Domain\Customers\Merge;

use App\Domain\Conversations\Realtime\RealtimePusher;
use App\Domain\Customers\Rollups\CustomerRollups;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * FR-11/FR-12's merge — T-03's CHUNKED SAGA WITH COMPENSATION, built so the
 * pair is NEVER left half-merged (FS-02):
 *
 * SNAPSHOT FIRST, AS THE DURABLE SAGA LOG: the full re-pointing map (every
 * conversation/ticket/note/identity id, the field diff with chosen values,
 * the survivor's pre-merge values, the merged row whole, the linked_work
 * flags to apply) is INSERTED before any chunk moves — AC-03's byte-identical
 * undo is only reachable if this payload is exhaustive, and compensation
 * needs no separate log because it is IDEMPOTENT OVER THE LISTS: restore
 * each listed object to the merged customer wherever it currently points at
 * the survivor. A chunk that never ran is a no-op to compensate.
 *
 * CHUNKS RUN IN SEPARATE TRANSACTIONS (batches of 500 — NFR-02's 10k-scale
 * posture; one giant transaction is exactly what chunking exists to avoid),
 * with per-chunk progress over Reverb user.{requester} through Doc 04's one
 * door (the TicketBulkJob idiom). A crash between chunks → compensate():
 * every pointer restored, applied flags cleared, undone_at stamped on the
 * snapshot (undone_at non-null = "this snapshot's re-pointing was REVERSED",
 * by undo or by compensation — documented here because it is the one
 * app-updatable column, by the countersigned grant). T-08's chaos test kills
 * between chunks via the $onChunk hook and proves the restoration.
 *
 * FR-12's linked_work flags are applied at FINALIZE through a COLUMN write +
 * NotesWriter::addSystemNote (Doc 06's public system-note door) — linked_work
 * is not a status, so the sole-status-writer law is untouched and the
 * escalation-#4 forecast RESOLVES TO CONSUMPTION: no Doc 06 file edited.
 *
 * PINNED-NOTE COLLISION (the union's one conflict): notes always union, but
 * one pin per customer is a partial-unique FACT — when both sides carry a
 * pinned note the SURVIVOR's pin wins and the merged one arrives unpinned
 * (recorded in the snapshot for byte-identical undo).
 *
 * SEC-02: reason MANDATORY (the B4-S04 frames draw no reason input — a
 * design-vs-document divergence filed for step 11's frame mapping; the
 * document wins here and now).
 */
final class CustomerMerge
{
    private const CHUNK = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly MergePlan $planner,
        private readonly CustomerRollups $rollups,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly RealtimePusher $realtime,
    ) {}

    /**
     * @param  array<string, 'survivor'|'merged'>  $fieldChoices  per-field pick (FR-11 step 2)
     * @param  callable(string): void|null  $onChunk  test/chaos hook + progress seam
     * @return string the snapshot id
     */
    public function execute(string $survivorId, string $mergedId, array $fieldChoices, string $reason, string $actorId, ?callable $onChunk = null): string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('a merge requires a reason (SEC-02)');
        }

        $plan = $this->planner->build($survivorId, $mergedId);

        // Resolve the field choices against the live diff.
        $applied = [];
        foreach ($plan['field_diff'] as $field => $pair) {
            $choice = $fieldChoices[$field] ?? 'survivor';
            $applied[$field] = $choice === 'merged' ? $pair['merged'] : $pair['survivor'];
        }

        // The merged side's pinned note arrives unpinned when the survivor
        // already holds a pin (partial-unique physics; snapshot records it).
        /** @var string|null $mergedPinned */
        $mergedPinned = DB::table('customer_notes')->where('customer_id', $mergedId)->where('pinned', true)->value('id');
        $survivorPinned = DB::table('customer_notes')->where('customer_id', $survivorId)->where('pinned', true)->exists();
        $unpinOnMove = ($mergedPinned !== null && $survivorPinned) ? $mergedPinned : null;

        $snapshotId = (string) new UuidV7;
        $payload = [
            'requested_by' => $actorId,
            'reason' => $reason,
            'applied_fields' => $applied,
            'survivor_before' => [
                'display_name' => $plan['survivor']->display_name,
                'language' => $plan['survivor']->language,
                'language_source' => $plan['survivor']->language_source,
                'country' => $plan['survivor']->country,
                'country_source' => $plan['survivor']->country_source,
                'tags' => $plan['survivor']->tags,
            ],
            'merged_row' => (array) $plan['merged'],
            'moved' => [
                'conversations' => $plan['conversations'],
                'tickets' => $plan['tickets'],
                'notes' => $plan['notes'],
                'identities' => $plan['identities'],
            ],
            'linked_work_flagged' => $plan['linked_work_to_flag'],
            'unpinned_note' => $unpinOnMove,
            'tags_union' => $plan['tags_union'],
        ];

        DB::table('customer_merge_snapshots')->insert([
            'id' => $snapshotId,
            'tenant_id' => $this->context->tenantId(),
            'survivor_id' => $survivorId,
            'merged_id' => $mergedId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'expires_at' => DB::raw("clock_timestamp() + interval '30 days'"),
        ]);

        try {
            $this->repointChunked('conversations', 'customer_id', $plan['conversations'], $survivorId, $actorId, $onChunk);
            $this->repointChunked('tickets', 'customer_id', $plan['tickets'], $survivorId, $actorId, $onChunk);
            $this->chunkDone('notes_files', $actorId, $onChunk, function () use ($plan, $survivorId, $unpinOnMove): void {
                DB::transaction(function () use ($plan, $survivorId, $unpinOnMove): void {
                    if ($unpinOnMove !== null) {
                        DB::table('customer_notes')->where('id', $unpinOnMove)->update(['pinned' => false, 'updated_at' => now()]);
                    }
                    if ($plan['notes'] !== []) {
                        DB::table('customer_notes')->whereIn('id', $plan['notes'])->update(['customer_id' => $survivorId]);
                    }
                    // Files ride their conversations (media hangs off messages) —
                    // the conversations chunk already carried them (T-03's
                    // "files/notes" slot, stated).
                });
            });
            $this->repointChunked('customer_identities', 'customer_id', $plan['identities'], $survivorId, $actorId, $onChunk);

            $this->finalize($snapshotId, $survivorId, $mergedId, $plan, $applied, $reason, $actorId);
        } catch (Throwable $e) {
            $this->compensate($snapshotId);

            throw $e;
        }

        return $snapshotId;
    }

    /**
     * FS-02's restoration, IDEMPOTENT OVER THE SNAPSHOT LISTS — callable
     * mid-crash, after a partial chunk, or on a re-driven job: every listed
     * object pointing at the survivor goes back to the merged customer;
     * applied flags clear; the snapshot is stamped reversed.
     */
    public function compensate(string $snapshotId): void
    {
        $snapshot = $this->restore($snapshotId);
        if ($snapshot === null) {
            return;
        }

        // Compensation is a SYSTEM act; a staff undo runs restore() through
        // UnmergeCustomer and writes ITS OWN attribution — one restoration
        // machinery, two honest audit trails.
        $this->audit->record('customer.merge_compensated', 'customer', $snapshot->survivor_id, AuditActor::System, ['snapshot_id' => $snapshot->id]);

        $this->rollups->recompute($snapshot->survivor_id);
        $this->rollups->recompute($snapshot->merged_id);
    }

    /**
     * The shared restoration (undo AND compensation): idempotent over the
     * snapshot lists; stamps undone_at; NO audit, NO rollups — callers own
     * attribution and recompute.
     *
     * @return object{id: string, survivor_id: string, merged_id: string, payload: string}|null
     */
    public function restore(string $snapshotId): ?object
    {
        /** @var object{id: string, survivor_id: string, merged_id: string, payload: string}|null $snapshot */
        $snapshot = DB::table('customer_merge_snapshots')->where('id', $snapshotId)->first(['id', 'survivor_id', 'merged_id', 'payload']);
        if ($snapshot === null) {
            return null;
        }
        /** @var array{moved: array{conversations: list<string>, tickets: list<string>, notes: list<string>, identities: list<string>}, linked_work_flagged: list<string>, unpinned_note: ?string, survivor_before: array<string, mixed>} $payload */
        $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($snapshot, $payload): void {
            foreach ([['conversations', 'conversations'], ['tickets', 'tickets'], ['customer_notes', 'notes'], ['customer_identities', 'identities']] as [$table, $key]) {
                foreach (array_chunk($payload['moved'][$key], self::CHUNK) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->where('customer_id', $snapshot->survivor_id)->update(['customer_id' => $snapshot->merged_id]);
                }
            }
            if ($payload['linked_work_flagged'] !== []) {
                DB::table('tickets')->whereIn('id', $payload['linked_work_flagged'])->update(['linked_work' => false]);
            }
            if (($payload['unpinned_note'] ?? null) !== null && ! DB::table('customer_notes')->where('customer_id', $snapshot->merged_id)->where('pinned', true)->exists()) {
                DB::table('customer_notes')->where('id', $payload['unpinned_note'])->update(['pinned' => true]);
            }
            DB::table('customers')->where('id', $snapshot->survivor_id)->update($payload['survivor_before']);
            DB::table('customers')->where('id', $snapshot->merged_id)->update(['merged_into_id' => null]);
            DB::table('customer_merge_snapshots')->where('id', $snapshot->id)->update(['undone_at' => DB::raw('clock_timestamp()')]);
        });

        return $snapshot;
    }

    /** @param list<string> $ids */
    private function repointChunked(string $table, string $column, array $ids, string $survivorId, string $actorId, ?callable $onChunk): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $batch) {
            DB::transaction(function () use ($table, $column, $batch, $survivorId): void {
                DB::table($table)->whereIn('id', $batch)->update([$column => $survivorId]);
            });
        }
        $this->chunkDone($table, $actorId, $onChunk, null);
    }

    private function chunkDone(string $name, string $actorId, ?callable $onChunk, ?callable $work): void
    {
        if ($work !== null) {
            $work();
        }
        // NFR-02's progress: per-chunk over user.{requester}, Doc 04's one door.
        $this->realtime->toUser($actorId, 'customer.merge.progress', ['chunk' => $name]);
        if ($onChunk !== null) {
            $onChunk($name);
        }
    }

    /** @param array<string, mixed> $plan
     * @param array<string, mixed> $applied */
    private function finalize(string $snapshotId, string $survivorId, string $mergedId, array $plan, array $applied, string $reason, string $actorId): void
    {
        DB::transaction(function () use ($snapshotId, $survivorId, $mergedId, $plan, $applied, $reason, $actorId): void {
            /** @var list<string> $tagsUnion */
            $tagsUnion = $plan['tags_union'];
            $tags = '{'.implode(',', $tagsUnion).'}';
            DB::table('customers')->where('id', $survivorId)->update($applied + ['tags' => $tags]);

            /** @var list<string> $toFlag */
            $toFlag = $plan['linked_work_to_flag'];
            if ($toFlag !== []) {
                DB::table('tickets')->whereIn('id', $toFlag)->update(['linked_work' => true]);
                // FR-12's system note on each flagged ticket's conversation —
                // Doc 06's public system-note door, consumed not edited.
                /** @var list<object{id: string, conversation_id: string}> $flagged */
                $flagged = DB::table('tickets')->whereIn('id', $toFlag)->get(['id', 'conversation_id'])->all();
                $notes = app(\App\Domain\Conversations\Notes\NotesWriter::class);
                foreach ($flagged as $ticket) {
                    /** @var \App\Models\Conversation $conversation */
                    $conversation = \App\Models\Conversation::query()->findOrFail($ticket->conversation_id);
                    $notes->addSystemNote($conversation, __('customers.merge.linked_work_note'));
                }
            }

            DB::table('customers')->where('id', $mergedId)->update(['merged_into_id' => $survivorId]);

            $this->events->publish('customer.merged', [
                'tenant_id' => $this->context->tenantId(),
                'survivor_id' => $survivorId,
                'merged_id' => $mergedId,
                'snapshot_id' => $snapshotId,
            ]);
            $this->audit->record('customer.merged', 'customer', $survivorId, AuditActor::Staff, [
                'reason' => $reason,
                'snapshot_id' => $snapshotId,
                'merged_id' => $mergedId,
                'moved_conversations' => is_countable($plan['conversations']) ? count($plan['conversations']) : 0,
                'moved_tickets' => is_countable($plan['tickets']) ? count($plan['tickets']) : 0,
            ], actorId: $actorId);
        });

        $this->rollups->recompute($survivorId);
    }
}
