<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use RuntimeException;

/**
 * Binding 4 of 4 — the console (Doc 01 T-05).
 *
 * A command whose signature carries `{--tenant=}` calls bindTenantFromOption() before
 * touching tenant data. The option value is an operator's explicit statement of WHICH
 * tenant, typed at a shell — server-controlled in FR-04's sense — and it still goes
 * through TenantResolver, so the tenant's CURRENT state is read and an unknown id gets
 * the same TenantNotResolved as every other surface.
 *
 * A command that skips this and reads a tenant-scoped model anyway does not silently
 * see everything: the context is unbound, so the model layer throws MissingTenantContext
 * and RLS returns nothing. The trait is how a console command opts IN to a tenant; there
 * is no way to opt in to all of them — cross-tenant console work is platform work and
 * belongs behind PlatformConnection (SEC-02), as the outbox relay already demonstrates.
 */
trait ResolvesTenantOption
{
    protected function bindTenantFromOption(): void
    {
        $tenantId = $this->option('tenant');

        if (! is_string($tenantId) || trim($tenantId) === '') {
            throw new RuntimeException(
                'This command operates on one tenant and needs --tenant=<uuid> (Doc 01 T-05). '
                .'There is deliberately no all-tenants mode on tenant-scoped commands.',
            );
        }

        $resolved = app(TenantResolver::class)
            ->resolveTrustedTenantId(trim($tenantId), ResolutionSource::Console);

        $context = app(TenantContext::class);
        $context->clear();
        $context->bind($resolved->tenantId, ResolutionSource::Console);
        app(RlsSession::class)->set($resolved->tenantId);
    }
}
