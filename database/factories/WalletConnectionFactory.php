<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<WalletConnection>
 */
class WalletConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'type' => 'blink',
            'status' => 'pending',
            'encrypted_secret' => Crypt::encryptString('blink:test@example.com:password'),
            'submitted_by_user_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the connection is for Blink.
     */
    public function blink(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString('blink:test@example.com:password'),
        ]);
    }

    /**
     * Indicate that the connection is for Aqua/Boltz.
     */
    public function aquaBoltz(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'aqua_descriptor',
            'encrypted_secret' => Crypt::encryptString('ct(slip77(...),elsh(wpkh(...))))'),
        ]);
    }

    /**
     * Indicate that the connection status is connected.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connected',
        ]);
    }

    /**
     * Indicate that the connection status needs support.
     */
    public function needsSupport(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'needs_support',
        ]);
    }
}
