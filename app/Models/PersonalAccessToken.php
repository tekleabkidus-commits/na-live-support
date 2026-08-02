<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API token — personal or machine (Doc 02 FR-18, FR-19; T-05; BR-03).
 *
 * One model for both kinds, deliberately: FR-19's machine tokens differ from
 * FR-18's personal ones only in WHO owns them (a service-account user) and who
 * may manage them (apikeys.machine.manage) — the storage, the door, the scope
 * intersection and the revocation physics are identical, and two models would
 * invite them to drift apart.
 *
 * TENANCY. Sanctum's stock model is tenant-blind; this one carries the
 * BelongsToTenant scope like every other tenant-scoped model, which is what
 * makes the stock `findToken()` safe to inherit: by the time the guard calls
 * it, ResolveBearerTenant has already bound the tenant through the
 * resolve_api_token door, so the sha256 lookup runs inside the tenant under
 * both isolation layers. Before that binding, the same lookup returns nothing
 * at all — which the isolation suite proves rather than assumes.
 *
 * @property string $tenant_id
 * @property string $tokenable_id
 * @property string $tokenable_type
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * REVOCATION IS A TIMESTAMP (FR-18 "individually revocable", FR-19's rotation
 * audit trail): the row survives so "which token WAS that" stays answerable,
 * and the app role's missing DELETE grant makes that a database fact. Only
 * FS-05's prune — platform role, expired rows — ever deletes.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken implements TenantScoped
{
    use BelongsToTenant;

    /**
     * How stale `last_used_at` may be before an authenticated request rewrites
     * it. Sanctum saves on EVERY request; at API rates that is pure write
     * amplification for a column FR-18 displays with minute granularity at
     * best. 60 s keeps the display honest and the hot path read-only.
     */
    private const LAST_USED_WRITE_INTERVAL_SECONDS = 60;

    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new \LogicException('PersonalAccessToken has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Merged with Sanctum's $casts (abilities json, last_used_at/expires_at
        // datetime) by Eloquent's getCasts().
        return [
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expires_at;

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * Throttle the guard's per-request `last_used_at` write (see the constant).
     *
     * Every other save passes through untouched — the throttle keys on the
     * dirty set being EXACTLY that one column, which only the guard produces.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            $original = $this->getRawOriginal('last_used_at');

            if (is_string($original)
                && CarbonImmutable::parse($original)->addSeconds(self::LAST_USED_WRITE_INTERVAL_SECONDS)->isFuture()
            ) {
                $this->syncAttributes();

                return true;
            }
        }

        return parent::save($options);
    }

    private function syncAttributes(): void
    {
        // Discard the skipped write so the in-memory model matches the row.
        $this->setRawAttributes($this->getRawOriginal(), true);
    }
}
