<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;
use App\Models\UserPermissionOverride;
use DateTimeImmutable;

/**
 * Builds a user's CapabilityMap from role + overrides + expiries — FR-05's
 * normative algorithm as an EXPLICIT decision sequence, in precedence order
 * (FR-04): User Deny > User Allow > Role grant > implicit deny.
 *
 * Falsifiability, established by running the sabotages (S19 step 3): hoisting
 * the ROLE rung above an override fails AC-02's matrix (2 cells — the
 * deny-beats-role verdict and the override-allow source), and removing the
 * SQL expiry filter fails all 4 EXPIRED cells. The deny-vs-allow PAIR order,
 * by contrast, is enforced by schema physics rather than code order: the
 * partial unique index allows one live override per (user, permission), so
 * both effects can never coexist for a key (RbacStorageTest proves the
 * index; FR-05's `override(user, permission)` is singular for the same
 * reason).
 *
 * Expiry is decided by POSTGRES' clock in the override query itself
 * (FR-09/EC-09, Doc 01 EC-08 — never an app-server wall clock): an override
 * whose `expires_at <= now()` at read time is dropped before the sequence
 * runs, exactly as if it never existed. The cleanup job only tidies rows;
 * nothing about enforcement waits for it.
 *
 * FS-03 both directions: role keys are intersected with the catalog (an
 * orphan DB key is dropped here — never enforced, whatever the boot check
 * did or didn't log), and every catalog key missing from role + overrides
 * lands as an implicit deny.
 *
 * The reserved override `scope` column (§19) is invisible to this class on
 * purpose — FR-04: an override grants/denies the VERB; scope comes from the
 * role alone. The scope map passes through only known scoped modules with
 * legal levels; a scoped module the role's map omits stays ABSENT (NFR-04:
 * consumers read null and fail closed).
 */
final class CapabilityMapBuilder implements CapabilityMapProvider
{
    private const SCOPE_LEVELS = ['Own', 'Team', 'Dept', 'All'];

    public function __construct(private readonly PermissionCatalog $catalog) {}

    /**
     * The CapabilityMapProvider seam: today a fresh build per call; step 5's
     * cache decorates this binding without touching any consumer.
     */
    public function mapFor(User $user): CapabilityMap
    {
        return $this->build($user);
    }

    public function build(User $user): CapabilityMap
    {
        $role = $user->role()->first();

        /** @var array<string, true> $granted */
        $granted = [];
        /** @var array<string, string> $scopes */
        $scopes = [];

        if ($role !== null) {
            foreach ($role->permissions as $key) {
                if ($this->catalog->has($key)) {
                    $granted[$key] = true;
                }
            }
            foreach ($role->scopes as $module => $level) {
                if (in_array($level, self::SCOPE_LEVELS, true)) {
                    $scopes[$module] = $level;
                }
            }
        }

        // Live, unexpired overrides. Both filters are SQL: `live` is the
        // not-revoked set, and the expiry comparison is whereRaw against the
        // DB's now() so no app clock can disagree with the database about
        // "expired" (EC-09). The model query also keeps the tenant scope on.
        /** @var array<string, UserPermissionOverride> $overrides */
        $overrides = [];
        $rows = UserPermissionOverride::query()
            ->where('user_id', $user->identifier())
            ->live()
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhereRaw('expires_at > now()');
            })
            ->get();
        foreach ($rows as $row) {
            if ($this->catalog->has($row->permission)) {
                $overrides[$row->permission] = $row;
            }
        }

        $verdicts = [];
        foreach ($this->catalog->all() as $key => $definition) {
            $verdicts[$key] = $this->decide($key, $granted, $overrides);
        }

        return new CapabilityMap($verdicts, $scopes);
    }

    /**
     * FR-05, one rung at a time. The ORDER of these returns is the contract.
     *
     * @param  array<string, true>  $granted
     * @param  array<string, UserPermissionOverride>  $overrides
     */
    private function decide(string $key, array $granted, array $overrides): Verdict
    {
        $override = $overrides[$key] ?? null;

        // 1. User Deny — beats everything.
        if ($override !== null && $override->effect === 'deny') {
            return new Verdict(false, VerdictSource::OverrideDeny, $override->granted_by, $this->expiry($override));
        }

        // 2. User Allow — the verb is granted; scope still comes from the
        //    role at object-check time (FR-04's limitation).
        if ($override !== null && $override->effect === 'allow') {
            return new Verdict(true, VerdictSource::OverrideAllow, $override->granted_by, $this->expiry($override));
        }

        // 3. Role grant.
        if (isset($granted[$key])) {
            return new Verdict(true, VerdictSource::RoleGrant);
        }

        // 4. Implicit deny — the floor.
        return new Verdict(false, VerdictSource::ImplicitDeny);
    }

    private function expiry(UserPermissionOverride $override): ?DateTimeImmutable
    {
        return $override->expires_at?->toDateTimeImmutable();
    }
}
