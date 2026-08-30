<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventory items and their derived stock levels. index() and show()
 * project on-hand / status from the append-only stock_movements ledger.
 * store() / update() (Task 3) manage the mutable item record; there is
 * no destroy() — retiring an item means setting active = false.
 */
class InventoryItemController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'low', 'expiring', 'archived'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $filter = $validated['filter'] ?? 'all';
        $search = $validated['search'] ?? null;

        $items = InventoryItem::query()
            ->withSum('movements as on_hand', 'quantity')
            ->orderBy('name')
            ->get()
            ->filter(function (InventoryItem $item) use ($filter) {
                $onHand = (int) $item->on_hand;

                return match ($filter) {
                    'low' => $item->active && $onHand <= $item->reorder_threshold,
                    'expiring' => $item->active && $item->isExpiringSoon(),
                    'archived' => ! $item->active,
                    default => $item->active,
                };
            })
            ->when($search !== null, fn ($collection) => $collection->filter(
                fn (InventoryItem $item) => str_contains(mb_strtolower($item->name), mb_strtolower($search)),
            ))
            ->values()
            ->map(function (InventoryItem $item) {
                $onHand = (int) $item->on_hand;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'on_hand' => $onHand,
                    'reorder_threshold' => $item->reorder_threshold,
                    'stock_status' => $onHand <= 0 ? 'out' : ($onHand <= $item->reorder_threshold ? 'low' : 'ok'),
                    'supplier' => $item->supplier,
                    'expiry_date' => $item->expiry_date?->toDateString(),
                    'is_expiring_soon' => $item->isExpiringSoon(),
                    'active' => $item->active,
                ];
            });

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    public function show(InventoryItem $inventoryItem): Response
    {
        $inventoryItem->load(['movements.creator', 'creator']);

        return Inertia::render('Inventory/Show', [
            'item' => $this->present($inventoryItem),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'unit' => $item->unit,
            'reorder_threshold' => $item->reorder_threshold,
            'supplier' => $item->supplier,
            'notes' => $item->notes,
            'expiry_date' => $item->expiry_date?->toDateString(),
            'is_expiring_soon' => $item->isExpiringSoon(),
            'active' => $item->active,
            'on_hand' => $item->onHand(),
            'stock_status' => $item->stockStatus(),
            'created_at' => $item->created_at->toIso8601String(),
            'creator_name' => $item->creator->name,
            'movements' => $item->movements->reverse()->values()->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'unit_cost' => $movement->unit_cost !== null ? (float) $movement->unit_cost : null,
                'reason' => $movement->reason,
                'occurred_on' => $movement->occurred_on->toDateString(),
                'created_at' => $movement->created_at->toIso8601String(),
                'creator_name' => $movement->creator->name,
            ]),
        ];
    }
}
