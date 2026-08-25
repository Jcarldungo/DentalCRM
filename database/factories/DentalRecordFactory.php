<?php

namespace Database\Factories;

use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentalRecord>
 */
class DentalRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'provider_id' => null,
            'appointment_id' => null,
            'type' => $this->faker->randomElement(DentalRecord::TYPES),
            'examination' => null,
            'diagnosis' => null,
            'procedure' => null,
            'notes' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
