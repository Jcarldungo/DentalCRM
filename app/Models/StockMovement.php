<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable stock movement. `quantity` is SIGNED — positive for
 * `received` and increasing `adjustment`, negative for `consumed`,
 * `expired`, and decreasing `adjustment`. Append-only: there is no
 * update or destroy path anywhere. See
 * docs/superpowers/specs/2026-08-30-inventory-design.md.
 */
class StockMovement extends Model
{
    use HasFactory;

    public const TYPES = ['received', 'consumed', 'adjustment', 'expired'];

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'unit_cost',
        'reason',
        'occurred_on',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'occurred_on' => 'date:Y-m-d',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
