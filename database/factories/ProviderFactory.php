<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Dr. ' . $this->faker->lastName(),
            'specialty' => $this->faker->randomElement(['General', 'Orthodontics', 'Endodontics', null]),
            'active' => true,
        ];
    }
}
