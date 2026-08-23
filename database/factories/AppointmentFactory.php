<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('now', '+30 days');

        return [
            'patient_id' => Patient::factory(),
            'provider_id' => Provider::factory(),
            'start_time' => $start,
            'end_time' => (clone $start)->modify('+30 minutes'),
            'type' => $this->faker->randomElement(Appointment::TYPES),
            'status' => 'scheduled',
        ];
    }
}
