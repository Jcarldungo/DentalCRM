<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'type' => 'received',
            'quantity' => $this->faker->numberBetween(10, 60),
            'unit_cost' => $this->faker->randomElement([50, 120, 250, 500]),
            'reason' => null,
            'occurred_on' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'type' => 'consumed',
            'quantity' => -$this->faker->numberBetween(1, 8),
            'unit_cost' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'type' => 'expired',
            'quantity' => -$this->faker->numberBetween(1, 5),
            'unit_cost' => null,
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn () => [
            'type' => 'adjustment',
            'quantity' => $this->faker->numberBetween(1, 3),
            'unit_cost' => null,
            'reason' => 'Stock count correction',
        ]);
    }
}
