<?php

namespace Database\Factories;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inquiry>
 */
class InquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('09#########'),
            'service_interest' => $this->faker->randomElement(['Dental Cleaning', 'Braces', 'Teeth Whitening', null]),
            'message' => $this->faker->sentence(12),
            'read_at' => null,
        ];
    }
}
