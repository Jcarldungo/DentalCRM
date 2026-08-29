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
}
