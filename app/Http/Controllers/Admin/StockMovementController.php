<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording a stock movement against an item, and nothing else.
 * Movements are append-only — there is deliberately no update() or
 * destroy() here, and no matching route. A movement that would drive
 * on-hand below zero is rejected under a row lock. `unit_cost` is kept
 * only for `received` movements.
 */
class StockMovementController extends Controller
{
    public function store(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(StockMovement::TYPES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'direction' => ['nullable', 'required_if:type,adjustment', Rule::in(['increase', 'decrease'])],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => ['required_if:type,adjustment', 'nullable', 'string', 'max:255'],
            // Same reasoning as PaymentController::store's paid_on — a
            // future-dated movement moves stock outside the period it is
            // reported in. No lower bound.
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $magnitude = (int) $validated['quantity'];
        $signed = match ($validated['type']) {
            'received' => $magnitude,
            'consumed', 'expired' => -$magnitude,
            'adjustment' => $validated['direction'] === 'increase' ? $magnitude : -$magnitude,
        };

        // An archived item is filtered out of the default /inventory view
        // and the dashboard low-stock tile, so stock received onto one
        // lands where nobody looks. Decreases stay allowed: archiving an
        // item that still holds stock is normal, and that stock has to be
        // consumable or write-off-able afterwards or it is stranded.
        if (! $inventoryItem->active && $signed > 0) {
            throw ValidationException::withMessages([
                'type' => 'This item is archived. Restore it before adding stock.',
            ]);
        }

        $userId = $request->user()->id;

        DB::transaction(function () use ($inventoryItem, $validated, $signed, $userId) {
            $locked = InventoryItem::whereKey($inventoryItem->id)->lockForUpdate()->first();
            $locked->load('movements');
            $onHand = $locked->onHand();

            if ($onHand + $signed < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$onHand} {$locked->unit} in stock.",
                ]);
            }

            $movement = $locked->movements()->make([
                'type' => $validated['type'],
                'quantity' => $signed,
                'unit_cost' => $validated['type'] === 'received' ? ($validated['unit_cost'] ?? null) : null,
                'reason' => $validated['reason'] ?? null,
                'occurred_on' => $validated['occurred_on'] ?? now()->toDateString(),
            ]);
            $movement->created_by = $userId;
            $movement->save();
        });

        AuditLog::record('stock.recorded', $inventoryItem, $inventoryItem->name, [
            'type' => $validated['type'],
            'quantity' => $signed,
        ]);

        return back()->with('success', 'Stock movement recorded.');
    }
}
