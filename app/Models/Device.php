<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A recognisable machine a user has signed in from (Doc 02 FR-04, FR-12, T-03).
 *
 * NOT a session. The migration's docblock carries the full argument: sessions
 * live in Redis and die by cap, timeout or revocation; the device row is the
 * durable "seen this machine before" fact that FR-13's remember-me binding and
 * T-03's new-device notice both need. One row per (user, fingerprint_hash),
 * enforced by a unique index — DeviceRegistrar is the only writer and relies on
 * that index for its race handling, so nothing else should create these rows.
 *
 * @property string $user_id
 * @property string $fingerprint_hash
 * @property string|null $label
 * @property string|null $user_agent
 * @property string|null $last_ip
 * @property string|null $last_city
 * @property string|null $last_country_code
 * @property \Carbon\CarbonImmutable $first_seen_at
 * @property \Carbon\CarbonImmutable $last_seen_at
 * @property \Carbon\CarbonImmutable|null $notified_at
 */
#[Fillable([
    'user_id',
    'fingerprint_hash',
    'label',
    'user_agent',
    'last_ip',
    'last_city',
    'last_country_code',
    'first_seen_at',
    'last_seen_at',
])]
class Device extends Model implements TenantScoped
{
    use BelongsToTenant;

    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new \LogicException('Device has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
        ];
    }
}
