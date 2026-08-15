<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Store',
            'btcpay_store_id' => Str::random(32),
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'preferred_exchange' => 'kraken',
            'wallet_type' => null,
        ];
    }

    /**
     * Indicate that the store uses Blink wallet.
     */
    public function withBlink(): static
    {
        return $this->state(fn (array $attributes) => [
            'wallet_type' => 'blink',
        ]);
    }

    /**
     * Indicate that the store uses Aqua/Boltz wallet.
     */
    public function withAquaBoltz(): static
    {
        return $this->state(fn (array $attributes) => [
            'wallet_type' => 'aqua_boltz',
        ]);
    }
}
