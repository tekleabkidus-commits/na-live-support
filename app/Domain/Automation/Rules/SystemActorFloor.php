<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rules;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 SEC-01 — the ACTIVATOR-CAPABILITY FLOOR.
 *
 * At activation the activator's capability map is projected through FLOOR_MAP
 * (action type → the permission key a human would need for the same act) and
 * CAPTURED ON THE REVISION (activator_capability_floor jsonb). Rule actions
 * execute as a system actor bounded by that capture: a type the floor does not
 * allow returns `floor_refused` (the ActionRegistry's gate) — a demoted
 * author's old rules can never out-privilege the org silently. Drift (the
 * activator's LIVE map no longer covering the captured floor) surfaces as
 * rule WARNINGS; re-activation by a privileged admin creates a NEW revision
 * with a fresh capture (the fill-only trigger makes the old one history).
 *
 * Types with no per-ticket capability analog (notify, webhook_out,
 * send_sms_template, run_ai_task, suppress) floor on `automation.rules.edit` —
 * the authoring capability itself; the map is DATA so the choice is reviewable.
 */
final class SystemActorFloor
{
    /** @var array<string, string> action type → permission key */
    public const FLOOR_MAP = [
        'assign' => 'tickets.assign',
        'transfer_department' => 'tickets.department.change',
        'set_priority' => 'tickets.priority.change',
        'set_status' => 'tickets.status.change',
        'add_tags' => 'tickets.tags.manage',
        'remove_tags' => 'tickets.tags.manage',
        'set_custom_field' => 'tickets.fields.edit',
        'send_macro_reply' => 'tickets.reply',
        'add_note' => 'tickets.note',
        'escalate' => 'tickets.escalate',
        'notify' => 'automation.rules.edit',
        'send_sms_template' => 'automation.rules.edit',
        'webhook_out' => 'automation.rules.edit',
        'run_ai_task' => 'automation.rules.edit',
        'suppress' => 'automation.rules.edit',
    ];

    public function __construct(private readonly CapabilityMapProvider $capabilities) {}

    /**
     * The capture: which action types the activator's map covers, at this instant.
     *
     * @return array<string, bool>
     */
    public function captureFor(User $activator): array
    {
        $map = $this->capabilities->mapFor($activator);
        $floor = [];
        foreach (self::FLOOR_MAP as $type => $key) {
            $floor[$type] = $map->allows($key);
        }

        return $floor;
    }

    /** The ActionRegistry's gate: does the executing revision's captured floor allow the type? */
    public function allowsExecution(string $executionId, string $type): bool
    {
        $floor = DB::table('automation_rule_executions')
            ->join('automation_rule_revisions', 'automation_rule_revisions.id', '=', 'automation_rule_executions.revision_id')
            ->where('automation_rule_executions.id', $executionId)
            ->value('automation_rule_revisions.activator_capability_floor');
        if (! is_string($floor)) {
            return false; // no captured floor = no activation = nothing executes
        }
        /** @var array<string, bool> $decoded */
        $decoded = json_decode($floor, true, 4) ?: [];

        return ($decoded[$type] ?? false) === true;
    }

    /**
     * SEC-01's drift warnings: action types the ACTIVE revision's floor allows
     * that the activator's LIVE map no longer does.
     *
     * @return list<string>
     */
    public function driftFor(string $ruleId): array
    {
        /** @var object{activator_capability_floor: ?string, activated_by: ?string}|null $revision */
        $revision = DB::table('automation_rules')
            ->join('automation_rule_revisions', 'automation_rule_revisions.id', '=', 'automation_rules.active_revision_id')
            ->where('automation_rules.id', $ruleId)
            ->first(['automation_rule_revisions.activator_capability_floor', 'automation_rule_revisions.activated_by']);
        if ($revision === null || $revision->activator_capability_floor === null || $revision->activated_by === null) {
            return [];
        }
        $activator = User::query()->find($revision->activated_by);
        if ($activator === null) {
            /** @var array<string, bool> $captured */
            $captured = json_decode($revision->activator_capability_floor, true, 4) ?: [];

            return array_keys(array_filter($captured)); // a gone activator drifts everything
        }
        /** @var array<string, bool> $captured */
        $captured = json_decode($revision->activator_capability_floor, true, 4) ?: [];
        $live = $this->captureFor($activator);
        $drift = [];
        foreach ($captured as $type => $allowed) {
            if ($allowed && ($live[$type] ?? false) === false) {
                $drift[] = $type;
            }
        }

        return $drift;
    }
}
