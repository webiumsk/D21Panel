<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
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
            'name' => fake()->words(3, true).' App',
            'app_type' => 'PointOfSale',
            'btcpay_app_id' => Str::uuid(),
        ];
    }

    /**
     * Indicate that the app is a Point of Sale.
     */
    public function pointOfSale(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_type' => 'PointOfSale',
        ]);
    }

    /**
     * Indicate that the app is a Crowdfund.
     */
    public function crowdfund(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_type' => 'Crowdfund',
        ]);
    }

    /**
     * Indicate that the app is a Payment Button.
     */
    public function paymentButton(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_type' => 'PaymentButton',
        ]);
    }

    /**
     * Indicate that the app is an LN Address.
     */
    public function lightningAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_type' => 'LightningAddress',
        ]);
    }
}
