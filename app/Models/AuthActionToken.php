<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One reset/invite token, hashed at rest (Doc 02 FR-16, FR-17, SEC-02, EC-04).
 *
 * ActionTokenIssuer and ActionTokenRedeemer are the only intended writers; the
 * pre-tenant LOOKUP never touches this model at all — it goes through the
 * `resolve_auth_action_token` SECURITY DEFINER door, because the presenter of
 * a token has no session and no tenant yet. This model exists for the writes
 * that happen AFTER the door has answered and a tenant is bound: superseding a
 * live token on resend and stamping `consumed_at` on redemption.
 *
 * The properties SEC-02 names are schema physics, not model discipline: no
 * plaintext column exists, the hash's length and format are CHECK-constrained,
 * and the partial unique index allows at most one live token per
 * (user, purpose). See the migration.
 *
 * @property string $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property string $sent_to_email
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 * @property string|null $requested_ip
 */
#[Fillable(['user_id', 'purpose', 'token_hash', 'sent_to_email', 'expires_at', 'consumed_at', 'requested_ip'])]
#[Hidden(['token_hash'])]
class AuthActionToken extends Model implements TenantScoped
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
