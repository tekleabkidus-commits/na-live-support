<?php

declare(strict_types=1);

use App\Domain\Auth\Widget\WidgetVisitor;
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

// `guards => ['staff']` became LOAD-BEARING with Doc 02 step 10, not
// decorative: a request authenticated by `auth:widget` makes the widget guard
// the request's DEFAULT guard (Authenticate::shouldUse), so without the pin,
// a visitor presenting at any broadcast-auth door had their WidgetVisitor
// handed to this callback — whose User type declaration then blew up with a
// 500 instead of the 403 a refusal owes (found by WidgetPartitionTest's
// staff-channel case). Pinned, the staff guard is consulted explicitly, finds
// no session on a visitor request, and refuses cleanly before the callback.
Broadcast::channel('tenant.{tenantId}', function (User $user, string $tenantId): bool {
    return app(ChannelTenantBinding::class)->authorize($user, $tenantId);
}, ['guards' => ['staff']]);

/*
|------------------------------------------------------------------------------
| Visitor channels (Doc 02 FR-21 — "that visitor's own Reverb channels")
|------------------------------------------------------------------------------
|
| The namespace a visitor may enter, and the ONLY one: their own id under
| their own tenant. Doc 04/13 hang concrete conversation channels beneath
| this prefix; what Doc 02 pins is the authorization boundary.
|
| `guards => ['widget']` is BR-06 at the socket layer, both directions at
| once: this channel authenticates via the widget guard alone, so a staff
| session or bearer token presenting here retrieves no principal and is
| refused before the callback runs — and the staff channel above, which
| authenticates via the default staff guard, is equally unreachable with a
| visitor token. The callback's own checks make the visitor id and tenant in
| the channel NAME agree with the authenticated principal, so visitor A
| subscribing to visitor B's channel dies on comparison, not convention.
|
| The suffix wildcard exists so Doc 04/13's concrete channels (e.g.
| ….visitor.{id}.conversation) inherit this boundary automatically instead of
| re-deriving it per channel.
*/
Broadcast::channel('tenant.{tenantId}.visitor.{visitorId}.{suffix}', function (
    $principal,
    string $tenantId,
    string $visitorId,
): bool {
    return $principal instanceof WidgetVisitor
        && hash_equals($principal->tenantId, $tenantId)
        && hash_equals($principal->visitorId, $visitorId);
}, ['guards' => ['widget']]);

Broadcast::channel('tenant.{tenantId}.visitor.{visitorId}', function (
    $principal,
    string $tenantId,
    string $visitorId,
): bool {
    return $principal instanceof WidgetVisitor
        && hash_equals($principal->tenantId, $tenantId)
        && hash_equals($principal->visitorId, $visitorId);
}, ['guards' => ['widget']]);
