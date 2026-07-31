<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\ChannelTenantBinding;
use Illuminate\Support\Facades\Broadcast;

/*
|------------------------------------------------------------------------------
| Channel authorization (Doc 01 T-05 — the socket binding)
|------------------------------------------------------------------------------
|
| Foundation owns the NAMESPACE ROOT and the binding; Doc 07 registers the
| concrete channels underneath it. Every tenant-addressed channel authorizes
| through ChannelTenantBinding: tenant from the authenticated user, never from
| the channel name, context + RLS bound on success (see the class docblock).
|
| The scaffolded App.Models.User.{id} channel is gone rather than fixed: it
| strict-compared a UUIDv7 auth identifier with (int) $id, which can never be
| true for this schema, and no frontend subscribes to it. Doc 07 re-registers
| per-user notification channels under the tenant namespace when it builds the
| agent workspace that listens to them.
|
*/

Broadcast::channel('tenant.{tenantId}', function (User $user, string $tenantId): bool {
    return app(ChannelTenantBinding::class)->authorize($user, $tenantId);
});
