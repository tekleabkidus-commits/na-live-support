<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One hashed 2FA recovery code (Doc 02 FR-06/FR-07, SEC-03; rulings Q6, R-4).
 *
 * RecoveryCodeStore is the only intended reader and writer; the schema's
 * physics (no DELETE grant, no plaintext column) are asserted by
 * GrantPhysicsTest, not by discipline here.
 *
 * @property string $user_id
 * @property string $code_hash
 * @property string $batch_id
 * @property \Carbon\CarbonImmutable|null $used_at
 */
#[Fillable(['user_id', 'code_hash', 'batch_id', 'used_at'])]
#[Hidden(['code_hash'])]
class UserRecoveryCode extends Model implements TenantScoped
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
        ];
    }
}
