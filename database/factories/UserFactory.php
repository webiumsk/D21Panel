<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'btcpay_user_id' => fake()->uuid(),
            'btcpay_api_key' => 'test_api_key_'.Str::random(40),
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

    /**
     * Indicate that the user has a support role.
     */
    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'support',
        ]);
    }

    /**
     * Indicate that the user has an admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Guest (trial) user for tests and seeding.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_guest' => true,
            'role' => 'free',
            'name' => 'Guest',
        ]);
    }
}
