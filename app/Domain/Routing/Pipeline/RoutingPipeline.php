<?php

declare(strict_types=1);

namespace App\Domain\Routing\Pipeline;

use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Routing\Capacity\CapacityResolver;
use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Intake\RgIntake;
use App\Domain\Routing\Intake\TenantDefaultDepartment;
use App\Domain\Routing\Record\RoutingDecisionRecord;
use App\Domain\Routing\Rg\RgFinality;
use App\Domain\Routing\Seams\ConnectionRoutingTagSource;
use App\Domain\Routing\Strategies\AssignmentStrategy;
use App\Domain\Routing\Strategies\RoundRobin;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Assignment\AlreadyClaimed;
use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Assignment\AtCapacity;
use App\Domain\Tickets\Placement\PlaceInQueue;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Doc 11 FR-07 / FR-08 / FR-17 / T-02 / FS-04 / BR-02 / BR-06 — THE ROUTING PIPELINE, the
 * normative decision order, every step logged into the record:
 *
 *   1. Department: explicit (transfer/API/rule) > AI suggestion accepted by config
 *      (routing.ai.autoaccept on=apply, off=General+flag) > conversation default (the Doc 05
 *      connection tag SEAM) > tenant default department.
 *   2. Queue within department: rule-matched routing tag (a ticket tag ∩ the queue's
 *      routing_tags — Doc 12's rules SET tags, [D12]) > department default_queue. RG override:
 *      rg flag/intent FORCES the is_rg queue — FINAL (RgFinality; boosts cannot demote it).
 *   3. Position: FIFO base + boosts (FR-12) + the starvation cap OUTSIDE the score.
 *   4. Assignment: routable agents (FR-05) → strategy → AssignTicket::assignBySystem
 *      (Doc 06's door, actor=system; commit-time capacity; race → next candidate).
 *   5. No eligible agent → the ticket WAITS in its queue (PlaceInQueue wrote queue_id;
 *      ticket.waiting published) — re-evaluated on availability events (step 6's dispatcher).
 *
 * ROUTING NEVER WRITES TICKET ROWS (T-02): placement through PlaceInQueue, assignment through
 * assignBySystem — both Doc 06 doors. FS-04: any engine exception parks the ticket in the
 * department default with an error record + alert (never lost, never looping, queryable).
 */
final class RoutingPipeline
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
        private readonly TenantDefaultDepartment $tenantDefault,
        private readonly ConnectionRoutingTagSource $connectionTags,
        private readonly RgIntake $rg,
        private readonly RgFinality $finality,
        private readonly PositionInQueue $position,
        private readonly RoutableAgents $routable,
        private readonly CapacityResolver $capacity,
        private readonly AssignmentStrategy $strategy,
        private readonly AssignTicket $assign,
        private readonly PlaceInQueue $placement,
        private readonly EffectiveMembers $members,
        private readonly WaitingSets $waiting,
        private readonly RoundRobin $roundRobin,
    ) {}

    /**
     * @param  array{explicit_department_id?: ?string, explicit_queue_id?: ?string, connection_id?: ?string, explicit_assignee_id?: ?string}  $inputs
     * @return array{record_id: string, outcome: string, queue_id: ?string, department_id: ?string, assigned_to: ?string}
     */
    public function route(Ticket $ticket, string $trigger, array $inputs = []): array
    {
        $record = new RoutingDecisionRecord($this->context, $this->events, $ticket->identifier(), (string) $ticket->conversation_id, $trigger);
        try {
            return $this->run($ticket, $record, $inputs);
        } catch (Throwable $e) {
            return $this->park($ticket, $record, $e);
        }
    }

    /**
     * @param  array{explicit_department_id?: ?string, explicit_queue_id?: ?string, connection_id?: ?string, explicit_assignee_id?: ?string}  $inputs
     * @return array{record_id: string, outcome: string, queue_id: ?string, department_id: ?string, assigned_to: ?string}
     */
    private function run(Ticket $ticket, RoutingDecisionRecord $record, array $inputs): array
    {
        // ── 1. Department ───────────────────────────────────────────────────────
        /** @var ?string $departmentId */
        $departmentId = null;
        $flagged = false;
        $explicit = $inputs['explicit_department_id'] ?? null;
        if (is_string($explicit) && $this->liveDepartment($explicit)) {
            $departmentId = $explicit;
            $record->step('department', ['source' => 'explicit', 'department_id' => $explicit], 'explicit department applied');
        }
        if ($departmentId === null) {
            $suggested = $this->aiSuggestedDepartment($ticket->identifier());
            if ($suggested !== null) {
                if ((bool) $this->settings->get('routing.ai.autoaccept') && $this->liveDepartment($suggested)) {
                    $departmentId = $suggested;
                    $record->step('department', ['source' => 'ai_suggestion', 'department_id' => $suggested, 'autoaccept' => true], 'AI suggestion applied (routing.ai.autoaccept on)');
                } else {
                    $flagged = true;
                    $record->step('department', ['source' => 'ai_suggestion', 'department_id' => $suggested, 'autoaccept' => false], 'AI suggestion NOT applied (routing.ai.autoaccept off) — General + flag');
                }
            }
        }
        if ($departmentId === null && $flagged === false) {
            $tag = $this->connectionTags->tagFor($inputs['connection_id'] ?? null);
            if ($tag !== null) {
                $byTag = $this->departmentByTag($tag);
                if ($byTag !== null) {
                    $departmentId = $byTag;
                    $record->step('department', ['source' => 'connection_tag', 'tag' => $tag, 'department_id' => $byTag], 'conversation default (Doc 05 connection routing tag)');
                } else {
                    $record->step('department', ['source' => 'connection_tag', 'tag' => $tag], 'connection tag matched no department — next source');
                }
            }
        }
        if ($departmentId === null) {
            $default = $this->tenantDefault->resolve();
            if ($default === null) {
                throw new \RuntimeException('no live department with a default queue exists (FS-04)');
            }
            $departmentId = $default['department_id'];
            $record->step('department', ['source' => 'tenant_default', 'department_id' => $departmentId, 'ai_flagged' => $flagged], $flagged ? 'tenant default department (AI suggestion flagged for review)' : 'tenant default department');
        }

        // ── 2. Queue (RG override is FINAL) ─────────────────────────────────────
        $rg = $this->rg->detect($ticket->identifier(), (string) $ticket->customer_id);
        $queueId = null;
        $explicitQueue = $inputs['explicit_queue_id'] ?? null;
        if (is_string($explicitQueue) && $this->liveQueue($explicitQueue, $departmentId)) {
            $queueId = $explicitQueue;
            $record->step('queue', ['source' => 'explicit', 'queue_id' => $queueId], 'explicit queue applied');
        }
        if ($queueId === null) {
            $byTag = $this->queueByTicketTag($ticket, $departmentId);
            if ($byTag !== null) {
                $queueId = $byTag['queue_id'];
                $record->step('queue', ['source' => 'routing_tag', 'tag' => $byTag['tag'], 'queue_id' => $queueId], 'rule-matched routing tag');
            }
        }
        if ($queueId === null) {
            $defaultQueue = DB::table('departments')->where('id', $departmentId)->value('default_queue_id');
            if (! is_string($defaultQueue)) {
                throw new \RuntimeException('department has no default queue (EC-10 net → FS-04)');
            }
            $queueId = $defaultQueue;
            $record->step('queue', ['source' => 'department_default', 'queue_id' => $queueId], 'department default queue');
        }
        if ($rg['is_rg']) {
            $rgQueue = $this->rg->rgQueue();
            $forced = $this->finality->forcedQueue(true, $rgQueue === null ? null : $rgQueue['queue_id'], $queueId);
            if ($rgQueue !== null) {
                $queueId = $forced;
                $departmentId = $rgQueue['department_id'];
                $record->step('rg_override', ['source' => $rg['source'], 'queue_id' => $queueId], 'RG intake FORCED to the responsible-gaming queue — final (BR-02)');
                $this->audit->record('routing.rg_routed', 'ticket', $ticket->identifier(), AuditActor::System, ['ticket_id' => $ticket->identifier(), 'rg_queue_id' => $queueId, 'source' => (string) $rg['source']]);
            } else {
                $record->step('rg_override', ['source' => $rg['source']], 'RG intake but NO is_rg queue exists — resolved queue stands (FR-01 requires one; EC-10 net)');
            }
        }

        // ── Placement (Doc 06's door) ───────────────────────────────────────────
        // Department changes ride Doc 06's transfer door; routing places the QUEUE (the one queue_id writer).
        if ($ticket->getAttribute('queue_id') !== $queueId) {
            $ticket = $this->placement->place($ticket, $queueId, null, 'routing:'.$record->trigger);
        }
        $ticket->refresh();

        // ── 3. Position ─────────────────────────────────────────────────────────
        $ticketFacts = [
            'id' => $ticket->identifier(), 'priority' => (string) $ticket->priority, 'reopened_count' => (int) $ticket->reopened_count,
            'customer_id' => (string) $ticket->customer_id, 'queued_at' => $ticket->getAttribute('queued_at'), 'tags' => $this->ticketTags($ticket),
            'conversation_id' => (string) $ticket->conversation_id,
        ];
        $pos = $this->position->compute($queueId, $ticketFacts);
        $record->boosts($pos['boosts_applied']);
        $record->step('position', ['matched' => $pos['matched'], 'age_seconds' => $pos['age_seconds'], 'cap_minutes' => $pos['cap_minutes'], 'starved' => $pos['starved'], 'score' => $pos['score']],
            $pos['starved'] ? sprintf('STARVED past %d min — promoted to the queue head, every boost outranked (NFR-06)', $pos['cap_minutes']) : sprintf('score %d (boosts: %s)', $pos['score'], $pos['boosts_applied'] === [] ? 'none' : implode(', ', $pos['boosts_applied'])));
        if ($pos['starved']) {
            $this->events->publish('routing.starvation_boosted', ['tenant_id' => $this->context->tenantId(), 'ticket_id' => $ticket->identifier(), 'queue_id' => $queueId, 'waited_seconds' => $pos['age_seconds'], 'cap_minutes' => $pos['cap_minutes']]);
            $this->audit->record('routing.starvation_boosted', 'ticket', $ticket->identifier(), AuditActor::System, ['ticket_id' => $ticket->identifier(), 'queue_id' => $queueId, 'waited_seconds' => $pos['age_seconds'], 'cap_minutes' => $pos['cap_minutes']]);
        }

        // ── 4. Assignment attempt ───────────────────────────────────────────────
        $routable = $this->routable->forQueue($queueId);
        $ordered = $this->strategy->order($queueId, $routable, $ticketFacts + ['department_id' => $departmentId, 'starved' => $pos['starved']]);
        // P-DOORS clause 3, option (a) (S12, countersigned): a rule-DIRECTED assignee
        // is tried FIRST — never instead. It must already be ROUTABLE (online, free
        // slot, effective member — FR-05), the commit-time capacity/scope checks
        // apply unchanged, and queue resolution (RG finality included) happened
        // ABOVE, untouched: directing an agent cannot move an RG-flagged ticket out
        // of the RG queue, and an agent outside that queue's routable set is simply
        // not tried (the countersign's own control).
        $directed = $inputs['explicit_assignee_id'] ?? null;
        if (is_string($directed)) {
            if (in_array($directed, array_column($routable, 'user_id'), true)) {
                $ordered['candidates'] = array_values(array_unique([$directed, ...$ordered['candidates']]));
                $record->step('directed', ['candidate' => $directed], 'rule-directed assignee tried first (Doc 12 FR-03; every guard applies)');
            } else {
                $record->step('directed', ['candidate' => $directed], 'rule-directed assignee not routable here — strategy order stands (honesty, not force)');
            }
        }
        $record->strategy($this->strategy->name(), count($routable));
        $record->snapshot(['routable' => $routable, 'candidates' => $ordered['candidates'], 'position' => $pos, 'queue_id' => $queueId, 'department_id' => $departmentId]);
        $noteCodes = array_filter([
            'note_code' => $ordered['note_code'] ?? null, 'note_slots' => $ordered['note_slots'] ?? null,
            'then_code' => $ordered['then_code'] ?? null, 'then_slots' => $ordered['then_slots'] ?? null,
        ], static fn ($v): bool => $v !== null);
        $record->step('eligibility', ['members' => count($this->members->eligible($queueId)), 'routable' => count($routable)] + $noteCodes + (array) ($ordered['facts'] ?? []), $routable === [] ? 'no eligible agent online with a free slot' : sprintf('%d routable — %s', count($routable), $ordered['note']));

        foreach ($ordered['candidates'] as $userId) {
            $agent = User::query()->find($userId);
            if (! $agent instanceof User) {
                continue;
            }
            try {
                $this->assign->assignBySystem($ticket->fresh() ?? $ticket, $agent, $this->capacity->commitFor($record->id, $queueId));
                $record->step('assign', ['candidate' => $userId], 'assigned (actor=system, Doc 06 door)', 'assign.ok');
                $this->waiting->remove($queueId, $ticket->identifier());   // FS-02: removal ONLY after the commit
                $this->roundRobin->advance($queueId, $userId);              // T-04: the pointer follows the assignment
                $recordId = $record->close('assigned', $queueId, $departmentId, $userId);

                return ['record_id' => $recordId, 'outcome' => 'assigned', 'queue_id' => $queueId, 'department_id' => $departmentId, 'assigned_to' => $userId];
            } catch (AtCapacity $e) {
                $record->step('assign', ['candidate' => $userId], 'at capacity at commit (EC-03) — next candidate', 'assign.at_capacity');
            } catch (AlreadyClaimed $e) {
                $record->step('assign', ['candidate' => $userId, 'by' => $e->by], 'claimed by a human first (BR-03) — routing yields', 'assign.claimed');
                $recordId = $record->close('manual', $queueId, $departmentId, $e->by);

                return ['record_id' => $recordId, 'outcome' => 'manual', 'queue_id' => $queueId, 'department_id' => $departmentId, 'assigned_to' => $e->by];
            } catch (AssigneeOutOfScope $e) {
                $record->step('assign', ['candidate' => $userId], 'assignee out of scope (FR-15) — next candidate', 'assign.out_of_scope');
            }
        }

        $queuedAtSeconds = is_string($ticketFacts['queued_at']) ? (int) strtotime($ticketFacts['queued_at']) : time();
        $this->waiting->add($queueId, $ticket->identifier(), WaitingSets::score($pos['starved'], $pos['rule_score'], $queuedAtSeconds));
        $record->step('wait', ['queue_id' => $queueId, 'position' => $this->waiting->position($queueId, $ticket->identifier())], 'waiting in queue — re-evaluated on the next availability event (FR-08 step 5)');
        $recordId = $record->close('waiting', $queueId, $departmentId, null);

        return ['record_id' => $recordId, 'outcome' => 'waiting', 'queue_id' => $queueId, 'department_id' => $departmentId, 'assigned_to' => null];
    }

    /**
     * FS-04: park in the department default with an error record + alert; never lost, never looping.
     *
     * @return array{record_id: string, outcome: string, queue_id: ?string, department_id: ?string, assigned_to: ?string}
     */
    private function park(Ticket $ticket, RoutingDecisionRecord $record, Throwable $e): array
    {
        $default = null;
        try {
            $default = $this->tenantDefault->resolve();
            if ($default !== null && $ticket->getAttribute('queue_id') !== $default['queue_id']) {
                $this->placement->place($ticket, $default['queue_id'], null, 'routing:engine_error');
            }
        } catch (Throwable $inner) {
            Log::error('routing: parking failed too', ['ticket_id' => $ticket->identifier(), 'error' => $inner->getMessage()]);
        }
        $record->step('engine_error', ['error_class' => $e::class], 'ENGINE ERROR — parked in the department default (FS-04); queryable, alerted');
        $this->audit->record('routing.engine_error_parked', 'ticket', $ticket->identifier(), AuditActor::System, ['ticket_id' => $ticket->identifier(), 'default_queue_id' => $default['queue_id'] ?? null, 'error_class' => $e::class]);
        Log::error('routing: engine error parked a ticket (FS-04)', ['ticket_id' => $ticket->identifier(), 'error' => $e->getMessage()]);
        $recordId = $record->close('parked_error', $default['queue_id'] ?? null, $default['department_id'] ?? null, null);

        return ['record_id' => $recordId, 'outcome' => 'parked_error', 'queue_id' => $default['queue_id'] ?? null, 'department_id' => $default['department_id'] ?? null, 'assigned_to' => null];
    }

    private function liveDepartment(string $id): bool
    {
        return DB::table('departments')->where('id', $id)->whereNull('deleted_at')->where('is_active', true)->exists();
    }

    private function liveQueue(string $id, string $departmentId): bool
    {
        return DB::table('queues')->where('id', $id)->where('department_id', $departmentId)->whereNull('deleted_at')->where('is_active', true)->exists();
    }

    private function aiSuggestedDepartment(string $ticketId): ?string
    {
        $payload = DB::table('ticket_handoff_packets')->where('ticket_id', $ticketId)->value('payload');
        if (! is_string($payload)) {
            return null;
        }
        /** @var array{suggested?: array{department?: ?string}} $packet */
        $packet = (array) json_decode($payload, true);
        $dept = $packet['suggested']['department'] ?? null;

        return is_string($dept) && $dept !== '' ? $dept : null;
    }

    /** A connection tag names a department by slug or by a queue routing tag in that department. */
    private function departmentByTag(string $tag): ?string
    {
        $bySlug = DB::table('departments')->where('slug', $tag)->whereNull('deleted_at')->where('is_active', true)->value('id');
        if (is_string($bySlug) && $bySlug !== '') {
            return $bySlug;
        }
        $byQueueTag = DB::table('queues')->whereRaw('? = ANY(routing_tags)', [$tag])->whereNull('deleted_at')->where('is_active', true)->value('department_id');

        return is_string($byQueueTag) ? $byQueueTag : null;
    }

    /**
     * tickets.tags is a Postgres text[] the model does not cast (Doc 06's FR-19 rewrite target reads
     * it raw): parse the array literal here, once.
     *
     * @return list<string>
     */
    private function ticketTags(Ticket $ticket): array
    {
        $raw = $ticket->getAttribute('tags');
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }
        if (! is_string($raw) || $raw === '' || $raw === '{}') {
            return [];
        }
        $inner = trim($raw, '{}');
        $tags = [];
        foreach (str_getcsv($inner, ',', '"', '\\') as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** @return array{queue_id: string, tag: string}|null */
    private function queueByTicketTag(Ticket $ticket, string $departmentId): ?array
    {
        foreach ($this->ticketTags($ticket) as $tag) {
            $queueId = DB::table('queues')->where('department_id', $departmentId)->whereRaw('? = ANY(routing_tags)', [$tag])
                ->whereNull('deleted_at')->where('is_active', true)->orderBy('position')->value('id');
            if (is_string($queueId)) {
                return ['queue_id' => $queueId, 'tag' => $tag];
            }
        }

        return null;
    }
}
