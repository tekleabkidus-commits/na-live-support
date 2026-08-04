<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Rbac\LastAdminProtected;
use App\Domain\Rbac\RoleAdministration;
use App\Domain\Rbac\RoleAssignedDeleteBlocked;
use App\Domain\Rbac\RoleEditConflict;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roles API behind B6-S07/S08 (§8: list with users count + high-risk
 * badge, create/clone, guarded delete, the matrix editor's save with EC-03's
 * version, FR-08a/b/c mapped to their drawn error states).
 */
final class RbacRolesController extends Controller
{
    public function __construct(private readonly RoleAdministration $roles) {}

    public function index(Request $request): JsonResponse
    {
        $catalog = app(\App\Support\Rbac\PermissionCatalog::class);

        $roles = Role::query()
            ->withCount('users')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->identifier(),
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'is_system' => $role->is_system,
                'version' => $role->version,
                'permissions' => $role->permissions,
                'scopes' => $role->scopes,
                'users_count' => (int) $role->users()->count(),
                'high_risk_count' => count(array_intersect($role->permissions, $catalog->highKeys())),
                'updated_at' => $role->updated_at?->toIso8601String(),
            ]);

        return new JsonResponse(['roles' => $roles]);
    }

    public function store(Request $request): Response
    {
        try {
            $result = $this->roles->create($this->actor($request), $this->attrs($request), $this->reason($request));
        } catch (RuntimeException $e) {
            return $this->refused($e);
        }

        return new JsonResponse([
            'role' => ['id' => $result['role']->identifier(), 'version' => $result['role']->version],
            'warnings' => $result['warnings'],
            'pending' => $result['pending'],
        ], 201);
    }

    public function update(Request $request, string $roleId): Response
    {
        $role = Role::query()->whereKey($roleId)->firstOrFail();

        try {
            $result = $this->roles->update(
                $this->actor($request),
                $role,
                $this->attrs($request),
                $request->integer('version'),
                $this->reason($request),
            );
        } catch (RoleEditConflict $conflict) {
            // EC-03: the loser sees the WINNER's state and can re-apply.
            return ProblemDetails::response(
                code: ErrorCode::SettingWriteConflict,
                status: 409,
                detail: (string) __('rbac.conflict.role_edited'),
                extra: ['winner' => [
                    'version' => $conflict->winner->version,
                    'permissions' => $conflict->winner->permissions,
                    'scopes' => $conflict->winner->scopes,
                    'name' => $conflict->winner->name,
                ]],
            );
        } catch (LastAdminProtected $blocked) {
            return $this->lastAdmin($blocked);
        } catch (RuntimeException $e) {
            return $this->refused($e);
        }

        return new JsonResponse([
            'role' => ['id' => $result['role']->identifier(), 'version' => $result['role']->version],
            'warnings' => $result['warnings'],
            'pending' => $result['pending'],
        ]);
    }

    public function destroy(Request $request, string $roleId): Response
    {
        $role = Role::query()->whereKey($roleId)->firstOrFail();

        try {
            $this->roles->delete($this->actor($request), $role);
        } catch (RoleAssignedDeleteBlocked $blocked) {
            // FR-08c: count + reassign shortcut, as drawn in B6-S07 §C.
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 409,
                detail: (string) __('rbac.blocked.role_assigned', ['count' => $blocked->assignedCount]),
                extra: ['assigned_count' => $blocked->assignedCount],
            );
        }

        return new JsonResponse(['status' => 'deleted']);
    }

    public function assign(Request $request, string $userId): Response
    {
        $subject = User::query()->whereKey($userId)->firstOrFail();
        $role = Role::query()->whereKey($request->string('role_id')->toString())->firstOrFail();

        try {
            $this->roles->assignRole($this->actor($request), $subject, $role);
        } catch (LastAdminProtected $blocked) {
            return $this->lastAdmin($blocked);
        }

        return new JsonResponse(['status' => 'assigned']);
    }

    private function lastAdmin(LastAdminProtected $blocked): Response
    {
        // §8's error state: explains exactly why and WHO ELSE holds the
        // rights (nobody, here — that is why it is blocked).
        return ProblemDetails::response(
            code: ErrorCode::Forbidden,
            status: 403,
            detail: (string) __('rbac.blocked.last_admin'),
            extra: ['other_holders' => $blocked->otherHolders],
        );
    }

    private function refused(RuntimeException $e): Response
    {
        return ProblemDetails::response(
            code: ErrorCode::ValidationFailed,
            status: 422,
            detail: $e->getMessage(),
        );
    }

    /**
     * @return array{name: string, description: ?string, color: string, permissions: list<string>, scopes: array<string, string>}
     */
    private function attrs(Request $request): array
    {
        $permissions = [];
        foreach ((array) $request->input('permissions', []) as $key) {
            if (is_string($key)) {
                $permissions[] = $key;
            }
        }
        $scopes = [];
        foreach ((array) $request->input('scopes', []) as $module => $level) {
            if (is_string($module) && is_string($level)) {
                $scopes[$module] = $level;
            }
        }

        $description = $request->input('description');

        return [
            'name' => $request->string('name')->toString(),
            'description' => is_string($description) ? $description : null,
            'color' => $request->string('color')->toString(),
            'permissions' => $permissions,
            'scopes' => $scopes,
        ];
    }

    private function reason(Request $request): string
    {
        return $request->string('reason')->toString();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
