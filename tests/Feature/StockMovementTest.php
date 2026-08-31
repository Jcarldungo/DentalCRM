<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** An item with $onHand received into stock. */
    protected function stockedItem(int $onHand = 20, string $unit = 'box'): InventoryItem
    {
        $item = InventoryItem::factory()->create(['unit' => $unit]);

        if ($onHand !== 0) {
            StockMovement::factory()->create([
                'inventory_item_id' => $item->id,
                'type' => 'received',
                'quantity' => $onHand,
            ]);
        }

        return $item;
    }

    public function test_guest_cannot_record_a_movement(): void
    {
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), ['type' => 'consumed', 'quantity' => 1])
            ->assertRedirect(route('login'));
    }

    public function test_received_adds_stock_with_a_unit_cost(): void
    {
        $user = $this->actingUser();
        $item = $this->stockedItem(0);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 25,
            'unit_cost' => 12.50,
        ])->assertRedirect();

        $movement = StockMovement::sole();
        $this->assertSame(25, $movement->quantity);
        $this->assertSame('12.50', $movement->unit_cost);
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame(now()->toDateString(), $movement->occurred_on->toDateString());
    }

    public function test_occurred_on_is_respected_when_supplied(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(0);

        // Relative to the item, not a hardcoded date — occurred_on is now
        // floored at the item's creation.
        $occurredOn = $item->created_at->toDateString();

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 5,
            'occurred_on' => $occurredOn,
        ])->assertSessionHasNoErrors();

        $this->assertSame($occurredOn, StockMovement::sole()->occurred_on->toDateString());
    }

    public function test_consumed_subtracts_stock_and_ignores_unit_cost(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'consumed',
            'quantity' => 4,
            'unit_cost' => 99,
        ])->assertRedirect();

        $movement = StockMovement::where('type', 'consumed')->sole();
        $this->assertSame(-4, $movement->quantity);
        $this->assertNull($movement->unit_cost);

        $item->load('movements');
        $this->assertSame(6, $item->onHand());
    }

    public function test_expired_subtracts_stock(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), ['type' => 'expired', 'quantity' => 3]);

        $this->assertSame(-3, StockMovement::where('type', 'expired')->sole()->quantity);
    }

    public function test_a_movement_cannot_drive_stock_negative(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(5, 'pair');

        $this->post(route('inventory-movements.store', $item), ['type' => 'consumed', 'quantity' => 8])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1, $item->movements()->count());
    }

    public function test_adjustment_requires_direction_and_reason(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), ['type' => 'adjustment', 'quantity' => 2])
            ->assertSessionHasErrors(['direction', 'reason']);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'adjustment',
            'quantity' => 2,
            'direction' => 'decrease',
            'reason' => 'Miscount',
        ])->assertRedirect();

        $this->assertSame(-2, StockMovement::where('type', 'adjustment')->sole()->quantity);
    }

    public function test_adjustment_increase_adds_stock(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'adjustment',
            'quantity' => 2,
            'direction' => 'increase',
            'reason' => 'Recount',
        ])->assertRedirect();

        $movement = StockMovement::where('type', 'adjustment')->sole();
        $this->assertSame(2, $movement->quantity);

        $item->load('movements');
        $this->assertSame(12, $item->onHand());
    }

    public function test_movements_are_allowed_against_an_archived_item(): void
    {
        $this->actingUser();
        $item = InventoryItem::factory()->archived()->create();
        StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
            'type' => 'received',
            'quantity' => 20,
        ]);

        $this->post(route('inventory-movements.store', $item), ['type' => 'consumed', 'quantity' => 5])
            ->assertRedirect();

        $item->load('movements');
        $this->assertSame(15, $item->onHand());
    }

    public function test_type_and_quantity_are_validated(): void
    {
        $this->actingUser();
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), ['type' => 'bogus', 'quantity' => 1])
            ->assertSessionHasErrors('type');
        $this->post(route('inventory-movements.store', $item), ['type' => 'received', 'quantity' => 0])
            ->assertSessionHasErrors('quantity');
    }

    /**
     * Decreases against an archived item stay allowed (see the test above —
     * stock held when the item was archived has to be runnable-down), but
     * an increase would land stock in a place the default /inventory view
     * and the dashboard tile both filter out.
     */
    public function test_stock_cannot_be_added_to_an_archived_item(): void
    {
        $this->actingUser();
        $item = $this->stockedItem();
        $item->update(['active' => false]);

        $this->post(route('inventory-movements.store', $item), ['type' => 'received', 'quantity' => 5])
            ->assertSessionHasErrors('type');

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'adjustment',
            'direction' => 'increase',
            'quantity' => 5,
            'reason' => 'Recount',
        ])->assertSessionHasErrors('type');

        $this->assertSame(20, $item->fresh()->load('movements')->onHand());
    }

    public function test_a_future_occurred_on_date_is_rejected(): void
    {
        $this->actingUser();
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 5,
            'occurred_on' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('occurred_on');

        $this->assertSame(20, $item->fresh()->load('movements')->onHand());
    }

    public function test_movements_are_append_only(): void
    {
        $this->assertFalse(Route::has('inventory-movements.update'));
        $this->assertFalse(Route::has('inventory-movements.destroy'));
    }

    public function test_a_movement_cannot_predate_the_item_it_moves(): void
    {
        $this->actingUser();
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 5,
            'occurred_on' => $item->created_at->clone()->subDay()->toDateString(),
        ])->assertSessionHasErrors('occurred_on');
    }
}
