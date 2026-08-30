<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'date_of_birth' => $this->faker->dateTimeBetween('-70 years', '-5 years')->format('Y-m-d'),
            'phone' => $this->faker->numerify('09#########'),
            // unique(): patients.email carries a unique index.
            'email' => $this->faker->unique()->safeEmail(),
            'emergency_contact_name' => $this->faker->name(),
            'emergency_contact_phone' => $this->faker->numerify('09#########'),
            'notes' => null,
            'recall_interval_months' => null,
        ];
    }
}
