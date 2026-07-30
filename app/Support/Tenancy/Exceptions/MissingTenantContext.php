<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped data is touched with no bound context (Doc 01 T-05).
 *
 * This is a BUG, always — never a user error, never something to catch and
 * recover from. It means a code path reached a tenant-scoped model without
 * passing through any of the six resolution paths, which is the precondition
 * every isolation guarantee in this platform rests on.
 *
 * It is deliberately loud. The alternative — treating "no context" as "no rows"
 * — would turn a wiring bug into a silent empty list, and an operator would
 * report "my tickets vanished" instead of the platform reporting its own defect.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            'No tenant context is bound, so %s cannot be queried (Doc 01 T-05). Every entry point must '
            .'resolve a tenant first: HTTP through the tenant middleware, jobs through the tenant job '
            .'middleware, sockets through the Reverb channel callback, CLI through --tenant. If this is '
            .'genuinely a platform-wide operation, it belongs behind PlatformConnection::run() and must '
            .'name its actor (SEC-02).',
            $model,
        ));
    }

    public static function forRead(): self
    {
        return new self(
            'No tenant context is bound (Doc 01 T-05). Resolution happens once per request, job or '
            .'socket connection, from server-controlled context only — never from request input (FR-04).',
        );
    }
}
