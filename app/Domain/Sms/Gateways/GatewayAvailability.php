<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Models\SmsGateway;

/**
 * Doc 14 §8 — "SMS is off until a gateway exists": the ONE predicate the
 * A-67 pre-guard (`no_gateway`), the expired-window card's degrade
 * (P-EXPIRED-CARD `sms_available`) and the setup checklist read. An active,
 * non-deleted gateway with a credential ref counts; a FIXTURE gateway counts
 * in the dev stack (it IS the stack's gateway) and never exists in production
 * (the seeder refuses there).
 */
final class GatewayAvailability
{
    public function exists(): bool
    {
        return SmsGateway::query()->where('active', true)->whereNull('deleted_at')->whereNotNull('credential_ref')->exists();
    }
}
