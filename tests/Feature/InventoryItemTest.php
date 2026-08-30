<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_on_hand_is_the_signed_sum_of_movements(): void
    {
        $item = InventoryItem::factory()->create(['reorder_threshold' => 5]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 20]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -5]);

        $item->load('movements');

        $this->assertSame(15, $item->onHand());
        $this->assertFalse($item->isLow());
        $this->assertSame('ok', $item->stockStatus());
    }

    public function test_stock_status_moves_from_ok_to_low_to_out(): void
    {
        $item = InventoryItem::factory()->create(['reorder_threshold' => 5]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 5]);
        $item->load('movements');
        $this->assertSame('low', $item->stockStatus());

        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -5]);
        $item->load('movements');
        $this->assertSame('out', $item->stockStatus());
    }

    public function test_is_expiring_soon_uses_a_30_day_window(): void
    {
        Carbon::setTestNow('2026-08-30');

        $this->assertTrue((new InventoryItem(['expiry_date' => '2026-09-10']))->isExpiringSoon());
        $this->assertTrue((new InventoryItem(['expiry_date' => '2026-08-01']))->isExpiringSoon());
        $this->assertFalse((new InventoryItem(['expiry_date' => '2026-10-15']))->isExpiringSoon());
        $this->assertFalse((new InventoryItem(['expiry_date' => null]))->isExpiringSoon());

        Carbon::setTestNow();
    }

    public function test_guest_cannot_view_inventory(): void
    {
        $item = InventoryItem::factory()->create();

        $this->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->get(route('inventory.show', $item))->assertRedirect(route('login'));
    }

    public function test_index_lists_active_items_with_computed_stock(): void
    {
        $this->actingUser();
        $item = InventoryItem::factory()->create(['name' => 'Nitrile Gloves', 'reorder_threshold' => 5]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 3]);

        $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('items', 1)
            ->where('items.0.name', 'Nitrile Gloves')
            ->where('items.0.on_hand', 3)
            ->where('items.0.stock_status', 'low'));
    }

    public function test_index_filters_low_expiring_and_archived(): void
    {
        Carbon::setTestNow('2026-08-30');
        $this->actingUser();

        $healthy = InventoryItem::factory()->create(['reorder_threshold' => 1]);
        StockMovement::factory()->create(['inventory_item_id' => $healthy->id, 'quantity' => 50]);

        $low = InventoryItem::factory()->create(['reorder_threshold' => 10]);
        StockMovement::factory()->create(['inventory_item_id' => $low->id, 'quantity' => 2]);

        $expiring = InventoryItem::factory()->create(['expiry_date' => '2026-09-05', 'reorder_threshold' => 0]);
        StockMovement::factory()->create(['inventory_item_id' => $expiring->id, 'quantity' => 30]);

        $archived = InventoryItem::factory()->archived()->create();

        $this->get(route('inventory.index', ['filter' => 'low']))
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $low->id));
        $this->get(route('inventory.index', ['filter' => 'expiring']))
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $expiring->id));
        $this->get(route('inventory.index', ['filter' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $archived->id));
        $this->get(route('inventory.index'))
            ->assertInertia(fn ($page) => $page->has('items', 3));

        Carbon::setTestNow();
    }

    public function test_index_search_matches_name(): void
    {
        $this->actingUser();
        InventoryItem::factory()->create(['name' => 'Nitrile Gloves']);
        InventoryItem::factory()->create(['name' => 'Face Masks']);

        $this->get(route('inventory.index', ['search' => 'glove']))
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.name', 'Nitrile Gloves'));
    }

    public function test_index_rejects_an_unknown_filter(): void
    {
        $this->actingUser();
        $this->get(route('inventory.index', ['filter' => 'bogus']))->assertSessionHasErrors('filter');
    }

    public function test_show_projects_the_item_with_movements_newest_first(): void
    {
        $this->actingUser();
        $item = InventoryItem::factory()->create();
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 20, 'occurred_on' => '2026-08-01']);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -4, 'occurred_on' => '2026-08-20']);

        $this->get(route('inventory.show', $item))->assertInertia(fn ($page) => $page
            ->component('Inventory/Show')
            ->where('item.on_hand', 16)
            ->has('item.movements', 2)
            ->where('item.movements.0.quantity', -4)
            ->where('item.movements.1.quantity', 20));
    }
}
