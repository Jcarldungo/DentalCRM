<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'treatment_plan_item_id' => null,
            'provider_id' => null,
            'description' => $this->faker->randomElement([
                'Root Canal Treatment',
                'Dental Filling',
                'Consultation fee',
                'Dental Crown',
                'X-ray',
            ]),
            'amount' => $this->faker->randomElement([500, 1500, 3000, 5000, 8000]),
        ];
    }
}
