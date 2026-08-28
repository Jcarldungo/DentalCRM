<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'provider_id' => null,
            'appointment_id' => null,
            'medication' => $this->faker->randomElement([
                'Amoxicillin',
                'Ibuprofen',
                'Paracetamol',
                'Metronidazole',
                'Chlorhexidine mouthwash',
            ]),
            'dosage' => $this->faker->randomElement(['250 mg', '500 mg', '400 mg', '0.2%']),
            'frequency' => $this->faker->randomElement(['Once daily', '2 times daily', '3 times daily', 'Every 8 hours']),
            'duration' => null,
            'quantity' => null,
            'instructions' => null,
            'status' => 'active',
            'discontinued_at' => null,
            'discontinued_reason' => null,
            'created_by' => User::factory(),
        ];
    }

    public function discontinued(): static
    {
        return $this->state(fn () => [
            'status' => 'discontinued',
            'discontinued_at' => now(),
        ]);
    }
}
