<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Binding 3 of 4 — sockets (Doc 01 T-05, FR-03).
 *
 * Reverb invokes a channel authorization callback with the AUTHENTICATED USER of the
 * connection. The tenant comes from that user's record — the same source FR-03a uses for
 * staff HTTP — and NEVER from the channel name. The `{tenantId}` segment in the channel is
 * an address, and addresses are client-supplied input (FR-04): it is compared against the
 * user's tenant, it is not believed.
 *
 * A mismatch answers `false`, indistinguishable from "no such channel" (C-10's 404
 * posture — an existence oracle over websockets is still an existence oracle), and
 * increments the same security metric as every other failed resolution.
 *
 * A suspended tenant's staff get no realtime: FR-05 blocks staff surfaces on suspension,
 * and a socket that outlives the login block would be a staff surface that forgot to
 * check. Doc 07 refines what suspended-state sockets show; refusing is the safe default
 * until it does.
 */
final class ChannelTenantBinding
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * Authorize a user onto a tenant-addressed channel, binding the context on success.
     */
    public function authorize(User $user, string $channelTenantId): bool
    {
        $userTenantId = $user->getAttribute('tenant_id');

        if (! is_string($userTenantId) || $userTenantId === '') {
            return false;
        }

        if (! hash_equals($userTenantId, $channelTenantId)) {
            Log::warning('tenant resolution failed', [
                'source' => ResolutionSource::Socket->value,
                'identifier' => $channelTenantId,
                'security_metric' => 'tenant.resolution_failed',
            ]);

            return false;
        }

        try {
            $resolved = $this->resolver->resolveTrustedTenantId($userTenantId, ResolutionSource::Socket);
        } catch (TenantNotResolved) {
            return false;
        }

        if ($resolved->state !== TenantState::Active) {
            return false;
        }

        $this->context->clear();
        $this->context->bind($resolved->tenantId, ResolutionSource::Socket);
        $this->rls->set($resolved->tenantId);

        return true;
    }
}
