<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-11/FR-12/T-01: one message row — guard_log, attempts, raw DLRs retained. phone and rendered_body are [D17]-classed PII: $hidden keeps them out of any serialization; the two permissioned UIs read them explicitly (SEC-02). The identity columns and the four clock facts are UPDATE-DENIED by grant; the state stamps are the DB's.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $customer_id
 * @property string|null $ticket_id
 * @property string|null $conversation_id
 * @property string|null $broadcast_id
 * @property string $event
 * @property string|null $template_id
 * @property string|null $template_version_id
 * @property string $language
 * @property string $phone
 * @property string|null $rendered_body
 * @property string|null $encoding
 * @property int|null $segments
 * @property string $state
 * @property string|null $skip_reason
 * @property string|null $fail_reason
 * @property list<array<string, mixed>> $guard_log
 * @property list<array<string, mixed>> $attempts
 * @property string|null $gateway_id
 * @property string|null $provider_id
 * @property list<array<string, mixed>> $raw_dlrs
 * @property string $lane
 * @property bool $is_test
 * @property string|null $cost_amount
 * @property string|null $cost_currency
 * @property string|null $cost_corrected_amount
 * @property string|null $cost_correction_note
 * @property \Carbon\CarbonImmutable $queued_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property \Carbon\CarbonImmutable|null $terminal_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
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

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

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
