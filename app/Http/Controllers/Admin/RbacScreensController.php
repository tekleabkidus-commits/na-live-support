<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PendingGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac\PageCapabilities;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The four B6 screens (Doc 03 §8/§9; DESIGN-FIDELITY-MANDATE): server side.
 * Props are REGISTRY-DRIVEN (the matrix renders what the registry seeds —
 * the drawn 113 rows include two other-doc illustrative keys, a filed
 * design-track ruling) and capability payloads come from the T-04 manifest,
 * never the full map.
 */
final class RbacScreensController extends Controller
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PageCapabilities $capabilities,
    ) {}

    public function roles(Request $request): Response
    {
        $user = $this->staff($request);
        $catalog = $this->catalog;

        $limitSetting = app(SettingsRepository::class)->get('rbac.roles.soft_limit');

        return Inertia::render('Admin/Rbac/RolesList', [
            'can' => $this->capabilities->for($user, 'Admin/Rbac/RolesList'),
            'copy' => __('rbac.ui.roles'),
            'chrome' => __('rbac.ui.shell'),
            'initials' => $this->initials($user),
            'roles' => Role::query()->withCount('users')->orderBy('created_at')->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->identifier(),
                    'name' => $role->name,
                    'description' => $role->description,
                    'color' => $role->color,
                    'is_system' => $role->is_system,
                    'users_count' => $role->users()->count(),
                    'high_risk_count' => count(array_intersect($role->permissions, $catalog->highKeys())),
                    'updated_at' => $role->updated_at?->toIso8601String(),
                ]),
            'soft_limit' => is_numeric($limitSetting) ? (int) $limitSetting : 100,
        ]);
    }

    public function editor(Request $request, string $roleId): Response
    {
        $user = $this->staff($request);
        $role = Role::query()->withCount('users')->whereKey($roleId)->firstOrFail();

        return Inertia::render('Admin/Rbac/RoleEditor', [
            'can' => $this->capabilities->for($user, 'Admin/Rbac/RoleEditor'),
            'copy' => __('rbac.ui.editor'),
            'chrome' => __('rbac.ui.shell'),
            'initials' => $this->initials($user),
            'role' => [
                'id' => $role->identifier(),
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'is_system' => $role->is_system,
                'version' => $role->version,
                'permissions' => $role->permissions,
                'scopes' => $role->scopes,
                'users_count' => $role->users()->count(),
            ],
            'catalog' => $this->catalogPayload(),
        ]);
    }

    public function overrides(Request $request, string $userId): Response
    {
        $user = $this->staff($request);
        $subject = User::query()->whereKey($userId)->firstOrFail();
        $role = $subject->role()->first();

        return Inertia::render('Admin/Rbac/UserOverrides', [
            'can' => $this->capabilities->for($user, 'Admin/Rbac/UserOverrides'),
            'copy' => __('rbac.ui.overrides'),
            'chrome' => __('rbac.ui.shell'),
            'initials' => $this->initials($user),
            'subject' => [
                'id' => $subject->identifier(),
                'name' => $this->displayName($subject),
                'role' => $role === null ? null : ['id' => $role->identifier(), 'name' => $role->name, 'color' => $role->color],
            ],
            'overrides' => UserPermissionOverride::query()
                ->where('user_id', $subject->identifier())->live()->orderBy('created_at')->get()
                ->map(fn (UserPermissionOverride $override): array => [
                    'id' => $override->identifier(),
                    'permission' => $override->permission,
                    'effect' => $override->effect,
                    'expires_at' => $override->expires_at?->toIso8601String(),
                    'granted_by' => $override->granted_by,
                    'reason' => $override->reason,
                ]),
            'catalog' => $this->catalogPayload(),
        ]);
    }

    public function pending(Request $request): Response
    {
        $user = $this->staff($request);

        return Inertia::render('Admin/Rbac/PendingGrants', [
            'can' => $this->capabilities->for($user, 'Admin/Rbac/PendingGrants'),
            'copy' => __('rbac.ui.pending'),
            'chrome' => __('rbac.ui.shell'),
            'initials' => $this->initials($user),
            'four_eyes_enabled' => (bool) app(SettingsRepository::class)->get('rbac.four_eyes'),
            'pending' => PendingGrant::query()
                ->where('status', 'pending')->whereRaw('expires_at > now()')
                ->orderBy('created_at')->get()
                ->map(fn (PendingGrant $grant): array => [
                    'id' => $grant->identifier(),
                    'subject_type' => $grant->subject_type,
                    'permission' => $grant->permission,
                    'requested_by' => $grant->requested_by,
                    'reason' => $grant->reason,
                    'expires_at' => $grant->expires_at->toIso8601String(),
                ]),
            'me' => $user->identifier(),
        ]);
    }

    /**
     * The matrix's registry-driven rows: module → keys with label/desc keys,
     * risk and scope dimension (§8's tooltip carries description + risk).
     *
     * @return list<array{module: string, keys: list<array{key: string, label: string, description: string, high: bool, scoped: bool}>}>
     */
    private function catalogPayload(): array
    {
        $grouped = [];
        foreach ($this->catalog->all() as $definition) {
            $grouped[$definition->module][] = [
                'key' => $definition->key,
                'label' => (string) __($definition->labelI18nKey),
                'description' => (string) __($definition->descriptionI18nKey),
                'high' => $definition->high,
                'scoped' => $definition->scoped,
            ];
        }

        $payload = [];
        foreach ($grouped as $module => $keys) {
            $payload[] = [
                'module' => $module,
                'module_label' => (string) __('perm_module.'.$module),
                'keys' => $keys,
            ];
        }

        return $payload;
    }

    private function displayName(User $user): string
    {
        $name = $user->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    /** The drawn avatar's initials — data, not copy (N-03 untouched). */
    private function initials(User $user): string
    {
        $name = $user->getAttribute('name');
        $parts = array_values(array_filter(explode(' ', is_string($name) ? $name : '')));
        $first = $parts[0] ?? '';
        $last = $parts[count($parts) - 1] ?? '';

        return mb_strtoupper(mb_substr($first, 0, 1).($last !== $first ? mb_substr($last, 0, 1) : ''));
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
