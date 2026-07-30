<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Layer 1 of two: the global Eloquent scope (Doc 01 T-01.1).
 *
 * Adds `where tenant_id = <bound tenant>` to every query on a tenant-scoped
 * model, so that US-04 holds — "I get tenant scoping automatically from the
 * framework layer, so I cannot accidentally query across tenants".
 *
 * IT THROWS RATHER THAN RETURNING NOTHING when no context is bound. The tempting
 * alternative, `where tenant_id = null`, yields an empty result set that reads
 * exactly like a tenant with no data. Every such bug would then present as a
 * support ticket about missing records, and the actual defect — an entry point
 * that skipped resolution — would be invisible. T-05 specifies the throw.
 *
 * WHAT THIS LAYER CANNOT DO, WHICH IS WHY LAYER 2 EXISTS. It only sees Eloquent.
 * A `DB::table('tickets')` query, a raw join, a `whereRaw` subquery, a future
 * reporting aggregate written in SQL for speed — none pass through here. NFR-01
 * assumes that omission will eventually happen and requires RLS to contain it.
 * The isolation suite proves each layer independently by disabling the other.
 */
/**
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * Marker so a query may opt out deliberately and visibly.
     *
     * `Model::withoutGlobalScope(TenantScope::class)` is greppable and shows up in
     * review, which is the point: unscoped access should be a decision someone
     * made, in one line, that a reviewer can find. It is not a bypass — RLS still
     * applies underneath, so an unscoped query still returns only the bound
     * tenant's rows unless it also runs on the platform connection.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof TenantScoped) {
            // Reached only if this scope were attached to a model that does not use
            // BelongsToTenant. Silently doing nothing would leave an unscoped model
            // looking scoped, which is the worst of both worlds.
            throw new \LogicException(sprintf(
                '%s has TenantScope applied but does not implement %s, so there is no way to know which '
                .'column carries its tenant.',
                $model::class,
                TenantScoped::class,
            ));
        }

        $context = app(TenantContext::class);

        if ($context->isPlatformOperation()) {
            // A platform-wide read, already gated and logged by
            // PlatformConnection::run(), which refuses to start while a tenant is
            // bound. Skipping the filter here is what makes the platform console
            // possible without giving tenant code the same door.
            return;
        }

        if (! $context->isBound()) {
            throw MissingTenantContext::forModel($model::class);
        }

        $builder->where(
            $model->qualifyColumn($model->tenantIdColumn()),
            $context->tenantId(),
        );
    }
}
