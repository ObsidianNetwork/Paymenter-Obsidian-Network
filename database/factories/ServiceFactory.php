<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price' => $this->faker->randomFloat(2, 1),
            'period_base_price' => fn (array $attributes) => $attributes['price'],
            'current_period_price' => fn (array $attributes) => $attributes['price'],
            'pricing_ledger_started_at' => now(),
            'pricing_ledger_verified_at' => now(),
            'billing_cycles_completed' => 1,
            'currency_code' => 'USD',
            'status' => $this->faker->randomElement([
                Service::STATUS_PENDING,
                Service::STATUS_ACTIVE,
                Service::STATUS_CANCELLED,
                Service::STATUS_SUSPENDED,
            ]),
        ];
    }
}
