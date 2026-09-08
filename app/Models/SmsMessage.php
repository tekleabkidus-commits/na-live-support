<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-11/FR-12/T-01: one message row — guard_log, attempts, raw DLRs retained. phone and rendered_body are [D17]-classed PII: $hidden keeps them out of any serialization; the two permissioned UIs read them explicitly (SEC-02). The identity columns and the four clock facts are UPDATE-DENIED by grant; the state stamps are the DB's.
 */
#[Fillable([
    'customer_id',
    'ticket_id',
    'conversation_id',
    'broadcast_id',
    'event',
    'template_id',
    'template_version_id',
    'language',
    'phone',
    'rendered_body',
    'encoding',
    'segments',
    'state',
    'skip_reason',
    'fail_reason',
    'guard_log',
    'attempts',
    'gateway_id',
    'provider_id',
    'raw_dlrs',
    'lane',
    'is_test',
    'cost_amount',
    'cost_currency',
    'cost_corrected_amount',
    'cost_correction_note',
])]
final class SmsMessage extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'sms_messages';

    /** @var list<string> [D17]-classed PII — read explicitly by the two permissioned UIs only (SEC-02). */
    protected $hidden = ['phone', 'rendered_body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'array',
            'delivered_at' => 'immutable_datetime',
            'guard_log' => 'array',
            'is_test' => 'bool',
            'queued_at' => 'immutable_datetime',
            'raw_dlrs' => 'array',
            'sent_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
