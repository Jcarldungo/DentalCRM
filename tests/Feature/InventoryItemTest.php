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
            ->has('items.data', 1)
            ->where('items.data.0.name', 'Nitrile Gloves')
            ->where('items.data.0.on_hand', 3)
            ->where('items.data.0.stock_status', 'low'));
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
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $low->id));
        $this->get(route('inventory.index', ['filter' => 'expiring']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $expiring->id));
        $this->get(route('inventory.index', ['filter' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $archived->id));
        $this->get(route('inventory.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 3));

        Carbon::setTestNow();
    }

    public function test_index_search_matches_name(): void
    {
        $this->actingUser();
        InventoryItem::factory()->create(['name' => 'Nitrile Gloves']);
        InventoryItem::factory()->create(['name' => 'Face Masks']);

        $this->get(route('inventory.index', ['search' => 'glove']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.name', 'Nitrile Gloves'));
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

    public function test_it_creates_an_item_owned_by_the_current_user(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        $this->post(route('inventory.store'), [
            'name' => 'Composite Resin A2',
            'category' => 'consumable',
            'unit' => 'syringe',
            'reorder_threshold' => 3,
            'created_by' => $other->id,
            'active' => false,
        ])->assertRedirect();

        $item = InventoryItem::sole();
        $this->assertSame('Composite Resin A2', $item->name);
        $this->assertSame($user->id, $item->created_by);
        $this->assertTrue($item->active);
    }

    public function test_opening_quantity_creates_an_adjustment_movement(): void
    {
        $this->actingUser();

        $this->post(route('inventory.store'), [
            'name' => 'Cotton Rolls',
            'category' => 'consumable',
            'unit' => 'box',
            'opening_quantity' => 40,
        ])->assertRedirect();

        $item = InventoryItem::sole();
        $movement = $item->movements()->sole();
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame(40, $movement->quantity);
        $this->assertSame('Opening balance', $movement->reason);

        $item->load('movements');
        $this->assertSame(40, $item->onHand());
    }

    public function test_opening_quantity_of_zero_creates_no_movement(): void
    {
        $this->actingUser();

        $this->post(route('inventory.store'), [
            'name' => 'Face Masks',
            'category' => 'ppe',
            'unit' => 'box',
            'opening_quantity' => 0,
        ]);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_create_validation_blocks_bad_input(): void
    {
        $this->actingUser();

        $this->post(route('inventory.store'), ['category' => 'consumable', 'unit' => 'box'])
            ->assertSessionHasErrors('name');
        $this->post(route('inventory.store'), ['name' => 'X', 'category' => 'nope', 'unit' => 'box'])
            ->assertSessionHasErrors('category');
        $this->post(route('inventory.store'), ['name' => 'X', 'category' => 'consumable', 'unit' => 'box', 'reorder_threshold' => -1])
            ->assertSessionHasErrors('reorder_threshold');

        $this->assertSame(0, InventoryItem::count());
    }

    public function test_it_updates_item_fields(): void
    {
        $this->actingUser();
        $item = InventoryItem::factory()->create(['name' => 'Old', 'reorder_threshold' => 2]);

        $this->patch(route('inventory.update', $item), ['name' => 'New', 'reorder_threshold' => 8])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('New', $item->name);
        $this->assertSame(8, $item->reorder_threshold);
    }

    public function test_archiving_hides_an_item_then_restoring_shows_it(): void
    {
        $this->actingUser();
        $item = InventoryItem::factory()->create();

        $this->patch(route('inventory.update', $item), ['active' => false]);
        $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page->has('items.data', 0));
        $this->get(route('inventory.index', ['filter' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1));

        $this->patch(route('inventory.update', $item), ['active' => true]);
        $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page->has('items.data', 1));
    }

    public function test_there_is_no_inventory_destroy_route(): void
    {
        $this->assertFalse(Route::has('inventory.destroy'));
    }

    public function test_dashboard_reports_low_and_expiring_counts(): void
    {
        Carbon::setTestNow('2026-08-30');
        $this->actingUser();

        $low = InventoryItem::factory()->create(['reorder_threshold' => 10]);
        StockMovement::factory()->create(['inventory_item_id' => $low->id, 'quantity' => 3]);

        $expiring = InventoryItem::factory()->create(['expiry_date' => '2026-09-10', 'reorder_threshold' => 0]);
        StockMovement::factory()->create(['inventory_item_id' => $expiring->id, 'quantity' => 40]);

        InventoryItem::factory()->archived()->create(['reorder_threshold' => 10]);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('inventory.low_count', 1)
            ->where('inventory.expiring_count', 1));

        Carbon::setTestNow();
    }
}
