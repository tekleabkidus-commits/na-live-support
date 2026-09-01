<?php

declare(strict_types=1);

namespace App\Domain\Sla\Instances;

use App\Domain\Hours\CalendarWriter;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-09 / BR-03 / T-05 — instance arming on ticket creation.
 *
 * The FIRST matching policy (position, id — BR-01) arms ONE instance per
 * ticket (the unique), embedding the POLICY SNAPSHOT and the RESOLVED CALENDAR
 * (never refs — a later edit is prospective by grant physics). Stages armed at
 * birth: first_response + resolution; next_response arms per customer message
 * (StageLifecycle). clock_start = the CREATION EVENT's own DB instant, read
 * from the outbox row (C-11 — never an app clock).
 */
final class InstanceArmer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CalendarWriter $calendars,
        private readonly EventPublisher $events,
        private readonly StageLifecycle $stages,
    ) {}

    /** Idempotent on the (tenant, ticket) unique — a redelivery arms nothing. */
    public function armForTicket(string $ticketId, string $eventId): void
    {
        /** @var object{id: string, customer_id: ?string, conversation_id: string, tags: ?string, priority: ?string, department_id: ?string}|null $ticket */
        $ticket = DB::table('tickets')->where('id', $ticketId)->first();
        if ($ticket === null || DB::table('sla_instances')->where('ticket_id', $ticketId)->exists()) {
            return;
        }
        $policy = $this->firstMatch($ticket);
        if ($policy === null) {
            return; // no policy claims this ticket — nothing arms, honestly
        }
        /** @var array<string, mixed> $pauseConditions */
        $pauseConditions = json_decode((string) $policy->pause_conditions, true, 8) ?: [];
        /** @var array<string, array<string, int>> $targets */
        $targets = json_decode((string) $policy->targets, true, 8) ?: [];
        $snapshot = [
            'policy_id' => $policy->id,
            'name' => $policy->name,
            'targets' => $targets,
            'clock_mode' => $policy->clock_mode,
            'warning_pct' => (int) $policy->warning_pct,
            'pause_conditions' => $pauseConditions,
            'retarget_mode' => $policy->retarget_mode,
            'breach_escalation' => is_string($policy->breach_escalation) ? json_decode($policy->breach_escalation, true, 4) : null,
        ];
        $calendarSnapshot = null;
        if ($policy->clock_mode === 'business_hours' && is_string($policy->calendar_id)) {
            $calendarSnapshot = $this->calendars->snapshot($policy->calendar_id);
        }
        $occurredAt = DB::table('outbox')->where('event_id', $eventId)->value('occurred_at');
        if (! is_string($occurredAt)) {
            /** @var object{t: string} $nowRow */
            $nowRow = DB::selectOne('SELECT clock_timestamp() AS t');
            $occurredAt = $nowRow->t;
        }
        $clockStart = $occurredAt;

        $instanceId = (string) Str::uuid7();
        DB::transaction(function () use ($instanceId, $ticket, $policy, $snapshot, $calendarSnapshot, $clockStart, $targets): void {
            DB::table('sla_instances')->insert([
                'id' => $instanceId, 'tenant_id' => $this->context->tenantId(),
                'ticket_id' => $ticket->id, 'policy_id' => $policy->id,
                'policy_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'calendar_snapshot' => $calendarSnapshot === null ? null : json_encode($calendarSnapshot, JSON_THROW_ON_ERROR),
            ]);
            $priority = $ticket->priority ?? 'normal';
            $this->stages->armStage($instanceId, 'first_response', $priority, $targets, $clockStart);
            $this->stages->armStage($instanceId, 'resolution', $priority, $targets, $clockStart);
            $this->events->publish('sla.instance.armed', [
                'tenant_id' => $this->context->tenantId(),
                'ticket_id' => $ticket->id,
                'instance_id' => $instanceId,
                'policy_id' => $policy->id,
            ]);
        });
        $this->stages->recomputeTicket($ticket->id);
    }

    /**
     * @param  object{id: string, customer_id: ?string, conversation_id: string, tags: ?string, priority: ?string, department_id: ?string}  $ticket
     * @return object{id: string, name: string, targets: string, clock_mode: string, calendar_id: ?string, warning_pct: int|string, pause_conditions: string, retarget_mode: string, breach_escalation: ?string}|null
     */
    private function firstMatch(object $ticket): ?object
    {
        /** @var list<object{id: string, name: string, match_conditions: string, targets: string, clock_mode: string, calendar_id: ?string, warning_pct: int|string, pause_conditions: string, retarget_mode: string, breach_escalation: ?string}> $policies */
        $policies = DB::select('SELECT * FROM sla_policies WHERE active ORDER BY position, id');
        $customerTags = [];
        if (is_string($ticket->customer_id)) {
            $raw = DB::table('customers')->where('id', $ticket->customer_id)->value('tags');
            $decodedCustomer = is_string($raw) ? json_decode($raw, true, 4) : [];
            $customerTags = is_array($decodedCustomer) ? array_values($decodedCustomer) : [];
        }
        $channel = DB::table('conversations')->where('id', $ticket->conversation_id)->value('channel');
        $decodedTicket = is_string($ticket->tags) ? json_decode($ticket->tags, true, 4) : [];
        $ticketTags = is_array($decodedTicket) ? array_values($decodedTicket) : [];
        foreach ($policies as $policy) {
            /** @var array<string, mixed> $conditions */
            $conditions = json_decode($policy->match_conditions, true, 8) ?: [];
            if ($this->matches($conditions, $ticket, $channel, $ticketTags, $customerTags)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  object{id: string, customer_id: ?string, conversation_id: string, tags: ?string, priority: ?string, department_id: ?string}  $ticket
     * @param  list<mixed>  $ticketTags
     * @param  list<mixed>  $customerTags
     */
    private function matches(array $conditions, object $ticket, mixed $channel, array $ticketTags, array $customerTags): bool
    {
        $stringy = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        foreach ($conditions as $key => $expected) {
            $ok = match ($key) {
                'department_id' => $ticket->department_id === $expected,
                'priority' => $ticket->priority === $expected,
                'channel' => $channel === $expected,
                'tags' => is_array($expected) && array_intersect(array_map($stringy, $expected), array_map($stringy, $ticketTags)) !== [],
                // FR-08's "VIP source": the vip customer tag — the same fact Doc 11's boost reads.
                'vip_source' => in_array('vip', array_map($stringy, $customerTags), true) === (bool) $expected,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
