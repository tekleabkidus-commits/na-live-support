<?php

declare(strict_types=1);

namespace App\Domain\Org\Structure;

use App\Domain\Org\OrgRefused;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-02 — teams: people groupings for scope and reporting (Doc 03 Team scope,
 * Doc 15 team reports); may span departments through the queues they join; a user may
 * belong to many. Rename is free; delete is a tombstone (REVOKE DELETE — saved views'
 * shared_to_id and reports reference the id). Membership rows are facts that end:
 * team_members carries a DELETE grant, audited org.team.member_removed.
 */
final class TeamWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $name, string $actorId, ?string $description = null): string
    {
        if (trim($name) === '') {
            throw new OrgRefused('team_name_required', 'a team needs a name (FR-02).');
        }
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $name, $description, $actorId): void {
            DB::table('teams')->insert(['id' => $id, 'tenant_id' => $this->context->tenantId(), 'name' => $name, 'description' => $description]);
            $this->events->publish('org.team.created', ['tenant_id' => $this->context->tenantId(), 'team_id' => $id]);
            $this->audit->record('org.team.created', 'team', $id, AuditActor::Staff, ['team_id' => $id, 'name' => $name], actorId: $actorId);
        });

        return $id;
    }

    /** @param  array<string, mixed>  $changes  name|description */
    public function update(string $teamId, array $changes, string $actorId): void
    {
        $this->live($teamId);
        $update = array_intersect_key($changes, ['name' => 1, 'description' => 1]);
        if ($update === []) {
            return;
        }
        if (array_key_exists('name', $update) && (! is_string($update['name']) || trim($update['name']) === '')) {
            throw new OrgRefused('team_name_required', 'a team needs a name (FR-02).');
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($teamId, $update, $changes, $actorId): void {
            DB::table('teams')->where('id', $teamId)->update($update);
            $this->events->publish('org.team.updated', ['tenant_id' => $this->context->tenantId(), 'team_id' => $teamId, 'changed' => array_keys($changes)]);
            $this->audit->record('org.team.updated', 'team', $teamId, AuditActor::Staff, ['team_id' => $teamId, 'changed' => array_keys($changes)], actorId: $actorId);
        });
    }

    /** FR-18: a tombstone; the team's queue links and memberships end with it. */
    public function delete(string $teamId, string $actorId): void
    {
        $this->live($teamId);
        DB::transaction(function () use ($teamId, $actorId): void {
            DB::table('queue_teams')->where('team_id', $teamId)->delete();
            DB::table('team_members')->where('team_id', $teamId)->delete();
            DB::table('teams')->where('id', $teamId)->update(['deleted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->events->publish('org.team.deleted', ['tenant_id' => $this->context->tenantId(), 'team_id' => $teamId]);
            $this->audit->record('org.team.deleted', 'team', $teamId, AuditActor::Staff, ['team_id' => $teamId], actorId: $actorId);
        });
    }

    public function addMember(string $teamId, string $userId, string $actorId, bool $isLead = false): void
    {
        $this->live($teamId);
        DB::transaction(function () use ($teamId, $userId, $isLead, $actorId): void {
            $exists = DB::table('team_members')->where('team_id', $teamId)->where('user_id', $userId)->exists();
            if ($exists) {
                DB::table('team_members')->where('team_id', $teamId)->where('user_id', $userId)->update(['is_lead' => $isLead, 'updated_at' => DB::raw('clock_timestamp()')]);
            } else {
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'team_id' => $teamId, 'user_id' => $userId, 'is_lead' => $isLead]);
            }
            $this->audit->record('org.team.member_added', 'team', $teamId, AuditActor::Staff, ['team_id' => $teamId, 'user_id' => $userId, 'is_lead' => $isLead], actorId: $actorId);
            // FR-04/T-03: team membership changes the reach of every queue the team serves.
            /** @var list<string> $queues */
            $queues = DB::table('queue_teams')->where('team_id', $teamId)->pluck('queue_id')->all();
            foreach ($queues as $queueId) {
                $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'added', 'via' => 'team']);
            }
        });
    }

    public function removeMember(string $teamId, string $userId, string $actorId): void
    {
        $this->live($teamId);
        DB::transaction(function () use ($teamId, $userId, $actorId): void {
            DB::table('team_members')->where('team_id', $teamId)->where('user_id', $userId)->delete();
            $this->audit->record('org.team.member_removed', 'team', $teamId, AuditActor::Staff, ['team_id' => $teamId, 'user_id' => $userId], actorId: $actorId);
            /** @var list<string> $queues */
            $queues = DB::table('queue_teams')->where('team_id', $teamId)->pluck('queue_id')->all();
            foreach ($queues as $queueId) {
                $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'removed', 'via' => 'team']);
            }
        });
    }

    private function live(string $teamId): void
    {
        if (! DB::table('teams')->where('id', $teamId)->whereNull('deleted_at')->exists()) {
            throw new OrgRefused('team_not_found', 'unknown team');
        }
    }
}
