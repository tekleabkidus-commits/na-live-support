<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Org\Membership\DepartmentAccess;
use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Org\OrgRefused;
use App\Domain\Org\Structure\DepartmentWriter;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Org\Structure\TeamWriter;
use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Estimator\EstimateEndpoint;
use App\Domain\Routing\Strategies\RoundRobin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PageCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doc 11 §8/§9/§13 — Backoffice → Organization (S11 step 12; FULL fidelity to B5-S09
 * J01–J15; R-D11-3). The screen is a PREVIEW of what will actually happen, not a form of
 * settings: the RR panel shows the real rotation (the strategy's own order), load rows the
 * real counters, the skill panel the real matchers and the live hold state, J14 the
 * breakdown behind "0 eligible", J15 the bands as the customer reads them (SEC-05's own
 * fence quoted in the drawing). Every mutation goes through the step-3b writers (their
 * audits and refusals); J20's edit conflict is a compare-and-refuse on `updated_at` —
 * nothing is overwritten silently, the operator chooses (Reload theirs / Keep mine).
 */
final class OrgScreensController extends Controller
{
    public function __construct(
        private readonly PageCapabilities $capabilities,
        private readonly EffectiveMembers $members,
        private readonly RoutableAgents $routable,
    ) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Admin/Org/Organization', [
            'can' => $this->capabilities->for($user, 'Admin/Org/Organization'),
            'copy' => __('org'),
            'departments' => $this->departments(),
            'teams' => $this->teams(),
            'queues' => $this->queues(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function departments(): array
    {
        /** @var list<object{id: string, name: string, slug: string, description: ?string, position: int|string, default_queue_id: ?string, business_hours_override_ref: ?string, is_active: bool, updated_at: ?string}> $rows */
        $rows = DB::table('departments')->whereNull('deleted_at')->orderBy('position')->orderBy('slug')
            ->get(['id', 'name', 'slug', 'description', 'position', 'default_queue_id', 'business_hours_override_ref', 'is_active', 'updated_at'])->all();
        $queueCounts = DB::table('queues')->whereNull('deleted_at')->selectRaw('department_id, count(*) AS n')->groupBy('department_id')->pluck('n', 'department_id');
        $queueNames = DB::table('queues')->whereNull('deleted_at')->pluck('name', 'id');

        return array_map(function (object $row) use ($queueCounts, $queueNames): array {
            /** @var array<string, string> $name */
            $name = (array) json_decode($row->name, true);

            return [
                'id' => $row->id,
                'name' => $name,
                'slug' => $row->slug,
                'description' => is_string($row->description) ? ((array) json_decode($row->description, true))['en'] ?? null : null,
                'position' => (int) $row->position,
                'default_queue_id' => $row->default_queue_id,
                'default_queue_name' => $row->default_queue_id !== null ? ($queueNames[$row->default_queue_id] ?? null) : null,
                'business_hours_override_ref' => $row->business_hours_override_ref,
                'is_active' => (bool) $row->is_active,
                'queue_count' => is_numeric($queueCounts[$row->id] ?? null) ? (int) $queueCounts[$row->id] : 0,
                'updated_at' => $row->updated_at,
            ];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function teams(): array
    {
        /** @var list<object{id: string, name: string, description: ?string}> $teams */
        $teams = DB::table('teams')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'description'])->all();
        $out = [];
        foreach ($teams as $team) {
            /** @var list<object{user_id: string, is_lead: bool, name: string}> $members */
            $members = DB::table('team_members as tm')->join('users as u', 'u.id', '=', 'tm.user_id')
                ->where('tm.team_id', $team->id)->orderBy('u.name')->get(['tm.user_id', 'tm.is_lead', 'u.name'])->all();
            // The departments this team SERVES (via its queues) and, per member, whether they can see each.
            /** @var list<object{department_id: string, department_name: string}> $served */
            $served = DB::table('queue_teams as qt')->join('queues as q', 'q.id', '=', 'qt.queue_id')->join('departments as d', 'd.id', '=', 'q.department_id')
                ->whereNull('q.deleted_at')->whereNull('d.deleted_at')->where('qt.team_id', $team->id)
                ->distinct()->orderBy('d.name')->get(['q.department_id', 'd.name as department_name'])->all();
            $memberRows = [];
            foreach ($members as $member) {
                $memberUser = User::query()->find($member->user_id);
                $chips = [];
                $warnings = [];
                foreach ($served as $dept) {
                    /** @var array<string, string> $deptName */
                    $deptName = (array) json_decode($dept->department_name, true);
                    $label = $deptName['en'] ?? $dept->department_id;
                    if ($memberUser instanceof User && $this->members->reaches($memberUser, $dept->department_id)) {
                        $chips[] = ['department_id' => $dept->department_id, 'department' => $label];
                    } else {
                        // J06: the warning names the SPECIFIC misconfiguration (FR-04's badge, drawn).
                        $warnings[] = ['department_id' => $dept->department_id, 'department' => $label];
                    }
                }
                $memberRows[] = ['user_id' => $member->user_id, 'name' => $member->name, 'is_lead' => (bool) $member->is_lead, 'chips' => $chips, 'warnings' => $warnings];
            }
            $out[] = ['id' => $team->id, 'name' => $team->name, 'description' => $team->description, 'members' => $memberRows,
                'served_departments' => array_map(static function (object $d): array {
                    /** @var array<string, string> $n */
                    $n = (array) json_decode($d->department_name, true);

                    return ['id' => $d->department_id, 'name' => $n['en'] ?? $d->department_id];
                }, $served)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function queues(): array
    {
        /** @var list<object{id: string, department_id: string, name: string, position: int|string, assignment_strategy: string, capacity_per_agent: ?int, priority_rules: string, skill_matchers: string, skill_fallback: string, routing_tags: string, is_rg: bool, is_active: bool, updated_at: ?string}> $rows */
        $rows = DB::table('queues')->whereNull('deleted_at')->orderBy('position')->orderBy('name')
            ->get(['id', 'department_id', 'name', 'position', 'assignment_strategy', 'capacity_per_agent', 'priority_rules', 'skill_matchers', 'skill_fallback', 'routing_tags', 'is_rg', 'is_active', 'updated_at'])->all();
        $estimate = app(EstimateEndpoint::class);
        $out = [];
        foreach ($rows as $row) {
            $members = $this->members->members($row->id);
            $names = [];
            $ids = array_column($members, 'user_id');
            if ($ids !== []) {
                /** @var array<string, string> $names */
                $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all();
            }
            $memberRows = array_map(static fn (array $m): array => $m + ['name' => $names[$m['user_id']] ?? $m['user_id']], $members);
            $routable = $this->routable->forQueue($row->id);
            $routableIds = array_column($routable, 'user_id');
            // J07: the REAL rotation — the strategy's own order over the routable set.
            $rotation = [];
            if ($row->assignment_strategy === 'round_robin' && $routable !== []) {
                $ordered = app(RoundRobin::class)->order($row->id, $routable, []);
                foreach (array_slice($ordered['candidates'], 0, 4) as $userId) {
                    $load = null;
                    foreach ($routable as $agent) {
                        if ($agent['user_id'] === $userId) {
                            $load = $agent;
                        }
                    }
                    $rotation[] = ['user_id' => $userId, 'name' => $names[$userId] ?? $userId, 'load' => $load['load'] ?? null, 'capacity' => $load['capacity'] ?? null];
                }
            }
            // J14: the breakdown behind "0 eligible" — members / online / free / with the skill.
            $online = 0;
            $withCapacity = count($routable);
            foreach ($memberRows as $m) {
                $status = app(\App\Domain\Inbox\Status\AgentStatus::class)->current($m['user_id']);
                if ($status['status'] === 'online') {
                    $online++;
                }
            }
            /** @var list<mixed> $matchers */
            $matchers = (array) json_decode($row->skill_matchers, true);
            $waiting = app(\App\Domain\Routing\Waiting\WaitingSets::class)->depth($row->id);
            $out[] = [
                'id' => $row->id,
                'department_id' => $row->department_id,
                'name' => $row->name,
                'strategy' => $row->assignment_strategy,
                'capacity_per_agent' => $row->capacity_per_agent,
                'boosts' => (array) json_decode($row->priority_rules, true),
                'skill_matchers' => $matchers,
                'skill_fallback' => $row->skill_fallback,
                'routing_tags' => $this->pgArray($row->routing_tags),
                'is_rg' => (bool) $row->is_rg,
                'is_active' => (bool) $row->is_active,
                'updated_at' => $row->updated_at,
                'members' => $memberRows,
                'rotation' => $rotation,
                'routable' => array_map(static fn (array $a): array => ['user_id' => $a['user_id'], 'name' => $names[$a['user_id']] ?? $a['user_id'], 'load' => $a['load'], 'capacity' => $a['capacity']], $routable),
                'breakdown' => ['members' => count($memberRows), 'online' => $online, 'free' => $withCapacity, 'skill_required' => $matchers !== [], 'waiting' => $waiting],
                'estimate' => $estimate->forQueue($row->id),
                'rules_targeting' => app(QueueWriter::class)->rulesTargeting($row->id),
            ];
        }

        return $out;
    }

    /** J20: the compare-and-refuse — the operator's choice, never a silent overwrite. */
    private function conflictGuard(string $table, string $id, Request $request): ?JsonResponse
    {
        $expected = $request->input('expected_updated_at');
        if (! is_string($expected) || $expected === '') {
            return null;   // "Keep mine and save" resubmits WITHOUT the guard — deliberate, drawn.
        }
        $current = DB::table($table)->where('id', $id)->value('updated_at');
        if (is_string($current) && $current !== $expected) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 409, 'edited by someone else', extra: ['refusal_code' => 'edit_conflict', 'their_updated_at' => $current]);
        }

        return null;
    }

    public function updateDepartment(Request $request, string $departmentId): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'name' => ['sometimes', 'array'], 'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_queue_id' => ['sometimes', 'string', 'uuid'], 'business_hours_override_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'], 'expected_updated_at' => ['nullable', 'string'],
        ]);
        if (($conflict = $this->conflictGuard('departments', $departmentId, $request)) !== null) {
            return $conflict;
        }
        /** @var User $user */
        $user = $request->user();
        try {
            app(DepartmentWriter::class)->update($departmentId, array_diff_key($input, ['expected_updated_at' => 0]), $user->identifier());
        } catch (OrgRefused $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
        }

        return new JsonResponse(['ok' => true, 'updated_at' => DB::table('departments')->where('id', $departmentId)->value('updated_at')]);
    }

    public function reorderDepartment(Request $request, string $departmentId): JsonResponse
    {
        /** @var array{position: int} $input */
        $input = $request->validate(['position' => ['required', 'integer', 'min:0', 'max:1000']]);
        /** @var User $user */
        $user = $request->user();
        try {
            app(DepartmentWriter::class)->reorder($departmentId, $input['position'], $user->identifier());
        } catch (OrgRefused $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function updateQueue(Request $request, string $queueId): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'assignment_strategy' => ['sometimes', 'string'],
            'capacity_per_agent' => ['sometimes', 'nullable', 'integer'], 'priority_rules' => ['sometimes', 'array'],
            'skill_matchers' => ['sometimes', 'array'], 'skill_fallback' => ['sometimes', 'string'],
            'routing_tags' => ['sometimes', 'array'], 'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['nullable', 'string'],
        ]);
        if (($conflict = $this->conflictGuard('queues', $queueId, $request)) !== null) {
            return $conflict;
        }
        /** @var User $user */
        $user = $request->user();
        try {
            $result = app(QueueWriter::class)->update($queueId, array_diff_key($input, ['expected_updated_at' => 0]), $user->identifier());
        } catch (OrgRefused $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
        }

        return new JsonResponse(['ok' => true, 'warnings' => $result, 'updated_at' => DB::table('queues')->where('id', $queueId)->value('updated_at')]);
    }

    public function createQueue(Request $request): JsonResponse
    {
        /** @var array{department_id: string, name: string, assignment_strategy?: string} $input */
        $input = $request->validate(['department_id' => ['required', 'string', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'assignment_strategy' => ['sometimes', 'string']]);
        /** @var User $user */
        $user = $request->user();
        try {
            $id = app(QueueWriter::class)->create($input['department_id'], $input['name'], $user->identifier(), $input['assignment_strategy'] ?? 'round_robin');
        } catch (OrgRefused $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
        }

        return new JsonResponse(['ok' => true, 'id' => $id], 201);
    }

    public function queueMemberAdd(Request $request, string $queueId): JsonResponse
    {
        return $this->memberDoor($request, fn (string $userId, User $user) => app(QueueWriter::class)->addMember($queueId, $userId, $user->identifier()));
    }

    public function queueMemberRemove(Request $request, string $queueId): JsonResponse
    {
        return $this->memberDoor($request, fn (string $userId, User $user) => app(QueueWriter::class)->removeMember($queueId, $userId, $user->identifier()));
    }

    public function teamMemberAdd(Request $request, string $teamId): JsonResponse
    {
        return $this->memberDoor($request, fn (string $userId, User $user) => app(TeamWriter::class)->addMember($teamId, $userId, $user->identifier()));
    }

    public function teamMemberRemove(Request $request, string $teamId): JsonResponse
    {
        return $this->memberDoor($request, fn (string $userId, User $user) => app(TeamWriter::class)->removeMember($teamId, $userId, $user->identifier()));
    }

    /** @param  callable(string, User): void  $act */
    private function memberDoor(Request $request, callable $act): JsonResponse
    {
        /** @var array{user_id: string} $input */
        $input = $request->validate(['user_id' => ['required', 'string', 'uuid']]);
        /** @var User $user */
        $user = $request->user();
        try {
            $act($input['user_id'], $user);
        } catch (OrgRefused $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
        }

        return new JsonResponse(['ok' => true]);
    }

    /** J04's "Give Verification access" — Doc 03's J04 one-act grant (DepartmentAccess). */
    public function giveAccess(Request $request, string $departmentId): JsonResponse
    {
        /** @var array{user_id: string} $input */
        $input = $request->validate(['user_id' => ['required', 'string', 'uuid']]);
        /** @var User $user */
        $user = $request->user();
        if (! DB::table('users')->where('id', $input['user_id'])->exists()) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
        try {
            app(DepartmentAccess::class)->grant($input['user_id'], $departmentId, $user->identifier());
        } catch (OrgRefused|\RuntimeException $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e instanceof OrgRefused ? $e->refusalCode : 'grant_refused']);
        }

        return new JsonResponse(['ok' => true]);
    }

    /** @return list<string> */
    private function pgArray(string $literal): array
    {
        $trim = trim($literal, '{}');

        return $trim === '' ? [] : array_map(static fn (string $item): string => trim($item, '"'), explode(',', $trim));
    }
}
