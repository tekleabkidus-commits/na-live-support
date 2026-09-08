<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-17/FR-18: a broadcast with its PINNED template version (EC-06), the audience filter (RG is the query's WHERE, never a filter key — SEC-03), the monotone progress counters, and the launch/cancel/completion stamps the DB writes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $template_id
 * @property string $template_version_id
 * @property array<string, mixed> $audience_filter
 * @property int $audience_count_preview
 * @property \Carbon\CarbonImmutable|null $scheduled_at
 * @property string $state
 * @property int|null $throughput_cap_per_minute
 * @property int $total
 * @property int $sent
 * @property int $delivered
 * @property int $skipped
 * @property int $failed
 * @property string $cost_accrued
 * @property string|null $cost_currency
 * @property string|null $launched_by
 * @property \Carbon\CarbonImmutable|null $launched_at
 * @property \Carbon\CarbonImmutable|null $canceled_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property string|null $monthly_bucket
 * @property \Carbon\CarbonImmutable|null $cancel_requested_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
#[Fillable([
    'name',
    'template_id',
    'template_version_id',
    'audience_filter',
    'audience_count_preview',
    'scheduled_at',
    'state',
    'throughput_cap_per_minute',
    'total',
    'sent',
    'delivered',
    'skipped',
    'failed',
    'cost_accrued',
    'cost_currency',
    'launched_by',
    'cancel_requested_at',
])]
final class SmsBroadcast extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

    protected $table = 'sms_broadcasts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience_filter' => 'array',
            'cancel_requested_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'launched_at' => 'immutable_datetime',
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    /** The string primary key (the house accessor every tenant model exposes). */
    public function identifier(): string
    {
        $key = $this->getKey();
        if (! is_string($key)) {
            throw new \LogicException(self::class.' was loaded without a string id; HasUuids should have set one.');
        }

        return $key;
    }
}
