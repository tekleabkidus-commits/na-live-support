<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Settings\SettingsRepository;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Per-user exceptions (FR-04, US-02, SEC-04, BR-02) behind the §8 override
 * editor. Adding replaces any live override for the same key in one
 * transaction (one live override per user+permission is index physics —
 * both halves audited), removal is soft with cause 'removed'.
 *
 * SEC-04: the reason is mandatory, ≥ 10 characters, enforced HERE (the write
 * path) and audited — the migration keeps the column NOT NULL so no raw path
 * escapes either.
 *
 * FR-08b: with four-eyes ON, a high-risk ALLOW goes pending (a distinct
 * second admin applies it); denies and normal keys apply directly — a DENY
 * narrows, it never over-grants, so it is not a "grant" in FR-08b's sense.
 */
final class OverrideAdministration
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly SettingsRepository $settings,
        private readonly FourEyes $fourEyes,
        private readonly LastAdminGuard $lastAdmin,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly CapabilityCacheInvalidator $invalidator,
    ) {}

    /**
     * @return array{override: ?UserPermissionOverride, pending: bool, warnings: list<string>}
     */
    public function add(
        User $actor,
        User $subject,
        string $permission,
        string $effect,
        string $reason,
        ?DateTimeInterface $expiresAt = null,
    ): array {
        if (! $this->catalog->has($permission)) {
            throw new RuntimeException(sprintf('Unknown permission key "%s" — the catalog is closed (BR-03).', $permission));
        }
        if (! in_array($effect, ['allow', 'deny'], true)) {
            throw new RuntimeException('Effect must be allow or deny.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new RuntimeException('A reason of at least 10 characters is required (SEC-04).');
        }

        // FR-08a through the override path: a DENY on either pair key is a
        // demotion in disguise.
        if ($effect === 'deny' && in_array($permission, LastAdminGuard::REQUIRED_PAIR, true)) {
            $this->lastAdmin->assertNotLastAdmin($subject);
        }

        if ($effect === 'allow' && $this->catalog->isHigh($permission) && $this->fourEyes->enabled()) {
            $this->fourEyes->requestOverrideGrant($subject, $permission, $actor, $reason);

            return ['override' => null, 'pending' => true, 'warnings' => $this->warnings($permission)];
        }

        $override = DB::transaction(function () use ($actor, $subject, $permission, $effect, $reason, $expiresAt): UserPermissionOverride {
            // Replace-any-live: the partial unique index arbitrates races.
            UserPermissionOverride::query()
                ->where('user_id', $subject->identifier())
                ->where('permission', $permission)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => DB::raw('now()'),
                    'revoked_by' => $actor->identifier(),
                    'revoke_cause' => 'removed',
                ]);

            $override = UserPermissionOverride::create([
                'user_id' => $subject->identifier(),
                'permission' => $permission,
                'effect' => $effect,
                'expires_at' => $expiresAt,
                'granted_by' => $actor->identifier(),
                'reason' => $reason,
            ]);

            $this->events->publish('rbac.override.added', [
                'tenant_id' => $override->tenantId(),
                'user_id' => $subject->identifier(),
                'permission' => $permission,
                'effect' => $effect,
                'actor_id' => $actor->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.override.added',
                entity: 'user',
                entityId: $subject->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: [
                    'permission' => $permission,
                    'effect' => $effect,
                    'expires_at' => $expiresAt?->format(DateTimeInterface::ATOM),
                    'reason' => $reason,
                ],
            );

            return $override;
        });

        $this->invalidator->forgetUser($subject);

        return ['override' => $override, 'pending' => false, 'warnings' => $this->warnings($permission)];
    }

    public function remove(User $actor, UserPermissionOverride $override): void
    {
        DB::transaction(function () use ($actor, $override): void {
            $claimed = UserPermissionOverride::query()
                ->whereKey($override->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => DB::raw('now()'),
                    'revoked_by' => $actor->identifier(),
                    'revoke_cause' => 'removed',
                ]);
            if ($claimed !== 1) {
                throw new RuntimeException('This override is no longer live.');
            }

            $this->events->publish('rbac.override.removed', [
                'tenant_id' => $override->tenantId(),
                'user_id' => $override->user_id,
                'permission' => $override->permission,
                'actor_id' => $actor->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.override.removed',
                entity: 'user',
                entityId: $override->user_id,
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: ['permission' => $override->permission],
            );
        });

        $this->invalidator->forgetUserId($override->user_id);
    }

    /**
     * BR-02: overrides are exceptions, not policy — surface the warning when
     * more than rbac.override.warn_threshold users carry an override for the
     * SAME permission (signal to create a role).
     *
     * @return list<string>
     */
    private function warnings(string $permission): array
    {
        $thresholdSetting = $this->settings->get('rbac.override.warn_threshold');
        $threshold = is_numeric($thresholdSetting) ? (int) $thresholdSetting : 10;

        $carriers = UserPermissionOverride::query()
            ->where('permission', $permission)
            ->whereNull('revoked_at')
            ->distinct('user_id')
            ->count('user_id');

        return $carriers > $threshold ? ['rbac.warning.override_sprawl'] : [];
    }
}
