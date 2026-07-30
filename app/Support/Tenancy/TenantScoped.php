<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Implemented by every tenant-scoped model (Doc 01 T-01.1, C-02).
 *
 * The BelongsToTenant trait supplies the behaviour; this interface makes the
 * contract visible to the type system and to a reader. Both matter:
 *
 *   - PHPStan at max level analyses a trait independently of the classes that use
 *     it, so a trait method called on `Model` is an undefined-method error. The
 *     interface is what lets the scope and the trait be type-safe rather than
 *     annotated into silence.
 *   - `instanceof TenantScoped` is a real runtime check. TenantScope is applied to
 *     models by the trait, but a global scope can be attached by other means, and
 *     "is this model tenant-scoped" should be answerable without inspecting its
 *     trait list.
 *
 * tests/Isolation/SchemaTenancyContractTest.php walks the live schema and asserts
 * every table carrying a tenant_id has a model implementing this — so a future
 * module cannot add a tenant table whose model quietly skips the scope.
 */
interface TenantScoped
{
    /**
     * The column carrying the tenant id.
     *
     * `tenant_id` for everything except the `tenants` table itself, where the
     * tenant IS the row and the column is `id`.
     */
    public function tenantIdColumn(): string;
}
