<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Nitrile Gloves', 'Composite Resin', 'Prophy Paste', 'Cotton Rolls', 'Face Masks', 'Suction Tips', 'Autoclave Pouches'])
                .' '.$this->faker->randomElement(['(S)', '(M)', '(L)', 'A2', 'Fine', 'Bulk']),
            'category' => $this->faker->randomElement(InventoryItem::CATEGORIES),
            'unit' => $this->faker->randomElement(['box', 'piece', 'pair', 'pack', 'bottle', 'tube']),
            'reorder_threshold' => $this->faker->numberBetween(2, 10),
            'supplier' => $this->faker->company(),
            'expiry_date' => null,
            'notes' => null,
            'active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => [
            'expiry_date' => now()->addDays($this->faker->numberBetween(1, 20))->toDateString(),
        ]);
    }
}
