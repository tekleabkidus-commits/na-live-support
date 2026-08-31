<?php

declare(strict_types=1);

namespace App\Domain\Routing\Record;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-17 / BR-06 / AC-08 / SEC-02 / §21 — THE ROUTING DECISION RECORD, written with the
 * first line of engine code. Every automatic routing writes {trigger, steps[{step, input,
 * outcome}], strategy, eligible_count, boosts_applied, assigned_to|waiting} to
 * routing_decisions (INSERT-ONLY BY GRANT — never rewritten) and publishes
 * ticket.routed {record_id}. The record carries user IDS only, never names (SEC-02's elision
 * is the read door's job — absence, not redaction). `snapshot` is T-08's replay input.
 *
 * Steps are appended as the pipeline runs; close() persists ONCE. An engine exception
 * closes the record as parked_error (FS-04) — never lost, never looping, queryable.
 */
final class RoutingDecisionRecord
{
    public readonly string $id;

    /** @var list<array{step: string, input: array<string, mixed>, outcome: string}> */
    private array $steps = [];

    /** @var list<string> */
    private array $boostsApplied = [];

    private ?string $strategy = null;

    private ?int $eligibleCount = null;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    private bool $closed = false;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        public readonly string $ticketId,
        public readonly string $conversationId,
        public readonly string $trigger,
    ) {
        $this->id = (string) Str::uuid7();
    }

    /** @param  array<string, mixed>  $input */
    /**
     * S11 step 14 (§14): `$code` names the sentence variant when the input alone cannot —
     * ADDITIVE: old records carry no code and the reader falls back to the stored prose.
     * The prose third argument stays the record's own canonical English (INSERT-only data).
     *
     * @param  array<string, mixed>  $input
     */
    public function step(string $step, array $input, string $outcome, ?string $code = null): void
    {
        $entry = ['step' => $step, 'input' => $input, 'outcome' => $outcome];
        if ($code !== null) {
            $entry['code'] = $code;
        }
        $this->steps[] = $entry;
    }

    /** @param  list<string>  $applied */
    public function boosts(array $applied): void
    {
        $this->boostsApplied = $applied;
    }

    public function strategy(?string $strategy, ?int $eligibleCount): void
    {
        $this->strategy = $strategy;
        $this->eligibleCount = $eligibleCount;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function snapshot(array $snapshot): void
    {
        $this->snapshot = $snapshot + $this->snapshot;
    }

    /** Persist ONCE: outcome ∈ assigned|waiting|parked_error|manual. */
    public function close(string $outcome, ?string $queueId, ?string $departmentId, ?string $assignedTo): string
    {
        if ($this->closed) {
            return $this->id;
        }
        $this->closed = true;
        $record = [
            'trigger' => $this->trigger,
            'steps' => $this->steps,
            'strategy' => $this->strategy,
            'eligible_count' => $this->eligibleCount,
            'boosts_applied' => $this->boostsApplied,
            'assigned_to' => $assignedTo,
            'waiting' => $assignedTo === null && $outcome === 'waiting',
            'outcome' => $outcome,
        ];
        DB::table('routing_decisions')->insert([
            'id' => $this->id,
            'tenant_id' => $this->context->tenantId(),
            'ticket_id' => $this->ticketId,
            'queue_id' => $queueId,
            'department_id' => $departmentId,
            'trigger' => $this->trigger,
            'outcome' => $outcome,
            'assigned_to' => $assignedTo,
            'strategy' => $this->strategy,
            'eligible_count' => $this->eligibleCount,
            'record' => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'snapshot' => json_encode($this->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $this->events->publish('ticket.routed', [
            'tenant_id' => $this->context->tenantId(),
            'ticket_id' => $this->ticketId,
            'conversation_id' => $this->conversationId,
            'record_id' => $this->id,
            'queue_id' => $queueId,
            'assigned_to' => $assignedTo,
        ]);

        return $this->id;
    }

    /** @return list<array{step: string, input: array<string, mixed>, outcome: string}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
