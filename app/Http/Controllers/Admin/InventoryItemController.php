<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventory items and their derived stock levels. index() and show()
 * project on-hand / status from the append-only stock_movements ledger.
 * store() / update() manage the mutable item record; there is
 * no destroy() — retiring an item means setting active = false.
 */
class InventoryItemController extends Controller
{
    /**
     * The item list, filtered and paginated in the database.
     *
     * This used to read every item's entire movement ledger and filter the
     * resulting collection in PHP. On-hand is now a correlated subquery
     * (InventoryItem::onHandSql()), so the low-stock and expiring filters
     * are WHERE clauses and the page is bounded.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'low', 'expiring', 'archived'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $filter = $validated['filter'] ?? 'all';
        $search = $validated['search'] ?? null;

        $items = InventoryItem::query()
            ->withOnHand()
            ->when($filter === 'low', fn ($q) => $q->lowStock())
            ->when($filter === 'expiring', fn ($q) => $q->expiringSoon())
            ->when($filter === 'archived', fn ($q) => $q->where('active', false))
            ->when($filter === 'all', fn ($q) => $q->where('active', true))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $q->where('name', 'like', $like);
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Inventory/Index', [
            'items' => $items->through(fn (InventoryItem $item) => $item->toListArray()),
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(InventoryItem::CATEGORIES)],
            'unit' => ['required', 'string', 'max:20'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'opening_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $userId = $request->user()->id;

        $item = DB::transaction(function () use ($validated, $userId) {
            $item = new InventoryItem([
                'name' => $validated['name'],
                'category' => $validated['category'],
                'unit' => $validated['unit'],
                'reorder_threshold' => $validated['reorder_threshold'] ?? 0,
                'supplier' => $validated['supplier'] ?? null,
                'expiry_date' => $validated['expiry_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
            $item->active = true;
            $item->created_by = $userId;
            $item->save();

            $opening = (int) ($validated['opening_quantity'] ?? 0);
            if ($opening > 0) {
                $movement = $item->movements()->make([
                    'type' => 'adjustment',
                    'quantity' => $opening,
                    'reason' => 'Opening balance',
                    'occurred_on' => now()->toDateString(),
                ]);
                $movement->created_by = $userId;
                $movement->save();
            }

            return $item;
        });

        return redirect()->route('inventory.show', $item);
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', Rule::in(InventoryItem::CATEGORIES)],
            'unit' => ['sometimes', 'required', 'string', 'max:20'],
            'reorder_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('reorder_threshold', $validated) && $validated['reorder_threshold'] === null) {
            $validated['reorder_threshold'] = 0;
        }

        $inventoryItem->update($validated);

        return back()->with('success', 'Item saved.');
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
