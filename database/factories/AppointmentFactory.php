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

    /**
     * A pending guest request: no real schedule, no provider yet.
     */
    public function requested(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => now()->addWeek()->toDateString(),
            'preferred_time_of_day' => 'morning',
            'notes' => null,
        ]);
    }
}
