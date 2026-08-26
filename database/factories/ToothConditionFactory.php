<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\ToothCondition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToothCondition>
 */
class ToothConditionFactory extends Factory
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
            'tooth_number' => $this->faker->numberBetween(1, 32),
            'condition' => $this->faker->randomElement(ToothCondition::CONDITIONS),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
