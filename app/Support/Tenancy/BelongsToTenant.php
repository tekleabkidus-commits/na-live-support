<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\CrossTenantWrite;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-scoped model (Doc 01 T-01.1, C-01, C-02).
 *
 * Three jobs, and the third is the one that catches bugs:
 *
 *   1. Adds TenantScope, so reads are filtered.
 *   2. Fills tenant_id on create from the bound context — never from caller
 *      input, which is FR-04 made structural rather than validated. There is no
 *      parameter to forge because the value is not accepted from anywhere.
 *   3. Refuses to save a record whose tenant_id is not the bound tenant.
 *
 * (3) covers the case (1) and (2) miss: a model LOADED under one tenant and saved
 * under another. That happens for real — an object cached in a static, a queued
 * job carrying a serialised model, a test helper reused across tenants — and
 * without this check the write would reach the database and be refused there by
 * RLS's WITH CHECK, with a stack trace pointing at the save rather than at the
 * code that mixed the tenants up.
 *
 * Also brings HasUuids, which in Laravel 13 generates UUIDv7 (C-01/C-11). That
 * matters beyond convention: the outbox relay cursor and every ordering decision
 * in the platform depend on primary keys being time-ordered (EC-08), so a model
 * using this trait is automatically safe to order by id.
 */
trait BelongsToTenant
{
    use HasUuids;

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model&TenantScoped $model): void {
            $context = app(TenantContext::class);
            $column = $model->tenantIdColumn();

            if ($model->getAttribute($column) === null) {
                if (! $context->isBound()) {
                    throw MissingTenantContext::forModel($model::class);
                }

                $model->setAttribute($column, $context->tenantId());
            }
        });

        static::saving(function (Model&TenantScoped $model): void {
            $context = app(TenantContext::class);

            // A platform operation legitimately writes across tenants (retention,
            // provisioning bookkeeping from the console). It is gated and logged
            // by PlatformConnection::run(); RLS is bypassed by role there, not by
            // this check being absent.
            if ($context->isPlatformOperation()) {
                return;
            }

            $attempted = $model->getAttribute($model->tenantIdColumn());

            if (is_string($attempted) && $attempted !== $context->tenantIdOrNull()) {
                throw CrossTenantWrite::forModel(
                    $model::class,
                    $attempted,
                    $context->tenantIdOrNull() ?? '(none bound)',
                );
            }
        });
    }

    /**
     * The column carrying the tenant.
     *
     * Overridden by the Tenant model itself, where the tenant IS the row and the
     * column is `id` — which is how one tenant is prevented from observing that
     * another exists at all (SEC-06).
     */
    public function tenantIdColumn(): string
    {
        return 'tenant_id';
    }

    /** @return BelongsTo<\App\Models\Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class, $this->tenantIdColumn());
    }
}
