<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-17/FR-18: a broadcast with its PINNED template version (EC-06), the audience filter (RG is the query's WHERE, never a filter key — SEC-03), the monotone progress counters, and the launch/cancel/completion stamps the DB writes.
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
}
