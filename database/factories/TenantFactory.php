<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 *
 * Fixtures are synthetic and self-identifying (CLAUDE.md §8): slugs are
 * `fixture-*` so that a leak into a log, a screenshot or a production database is
 * immediately recognisable as test data rather than an operator's real brand.
 *
 * State is always `provisioning`, and there is no `->active()` state here on
 * purpose: the only way to reach `active` is TenantLifecycle, because the database
 * trigger rejects any other writer. A factory state that set it directly would
 * have to defeat that trigger, and a test fixture that can do something production
 * code cannot is a test that proves the wrong thing. Use the `activateTenant()`
 * helper in tests/Pest.php, which goes through the real service.
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = 'fixture-'.$this->faker->unique()->lexify('??????');

        return [
            'name' => 'Fixture Operator '.strtoupper(substr($slug, -4)),
            'slug' => $slug,
            'state' => 'provisioning',
            'region' => 'et-central',
            'default_language_code' => 'en',
            'timezone_name' => 'Africa/Addis_Ababa',
            'currency_code' => 'ETB',
            'plan_flags' => [],
            'quota_overrides' => [],
        ];
    }

    /** Amharic-default tenant, for the AC-06 localisation proofs. */
    public function amharic(): self
    {
        return $this->state(fn (): array => ['default_language_code' => 'am']);
    }
}
