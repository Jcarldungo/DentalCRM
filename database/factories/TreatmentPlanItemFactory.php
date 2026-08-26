<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreatmentPlanItem>
 */
class TreatmentPlanItemFactory extends Factory
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
            'tooth_number' => null,
            'treatment' => $this->faker->randomElement([
                'Root Canal Treatment',
                'Dental Filling',
                'Tooth Extraction',
                'Teeth Whitening',
                'Dental Crown',
            ]),
            'estimated_cost' => $this->faker->randomElement([1500, 3000, 5000, 8000, 15000]),
            'priority' => $this->faker->randomElement(TreatmentPlanItem::PRIORITIES),
            'status' => 'planned',
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
