<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One parked poison message (Doc 04 FS-05): the full raw payload of an inbound
 * whose normalizer crashed ×3, plus what the ops re-drive view needs to explain
 * and replay it. See the migration for why rows flip to `redriven` instead of
 * ever being deleted, and DeadLetters for the claimant contract.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $channel_ref_id
 * @property string|null $inbound_family
 * @property array<string, mixed> $payload
 * @property string|null $claimant
 * @property string $error_class
 * @property string $error_message
 * @property int $attempts
 * @property string $status
 * @property string|null $redriven_by
 * @property \Carbon\CarbonImmutable|null $redriven_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
#[Fillable([
    'channel',
    'channel_ref_id',
    'inbound_family',
    'payload',
    'claimant',
    'error_class',
    'error_message',
    'attempts',
    'status',
])]
final class MessageDeadLetter extends Model implements TenantScoped
{
    public const STATUS_PARKED = 'parked';

    public const STATUS_REDRIVEN = 'redriven';

    use BelongsToTenant;

    protected $table = 'message_dead_letters';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'int',
            'redriven_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('MessageDeadLetter has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
