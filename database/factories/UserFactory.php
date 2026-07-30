<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * `tenant_id` is deliberately absent from definition(): BelongsToTenant fills it
 * from the bound context on create (Doc 01 FR-04 — the value is never chosen by a
 * caller, and a factory is a caller). Creating a user with no tenant bound throws
 * MissingTenantContext, which is correct: tests must say which tenant they mean.
 *
 * Emails use the `@fixture…` convention from CLAUDE.md §8 so that synthetic data
 * is self-identifying if it ever escapes.
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => 'fixture-'.fake()->unique()->lexify('??????').'@fixture.invalid',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
