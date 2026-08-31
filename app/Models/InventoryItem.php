<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stocked clinic supply. Item fields are mutable for the life of the
 * item; `active = false` archives it (no hard delete). The current
 * on-hand quantity is DERIVED from the append-only stock_movements
 * ledger — never stored. See
 * docs/superpowers/specs/2026-08-30-inventory-design.md.
 */
class InventoryItem extends Model
{
    use HasFactory;

    public const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];

    protected $fillable = [
        'name',
        'category',
        'unit',
        'reorder_threshold',
        'supplier',
        'expiry_date',
        'notes',
        'active',
    ];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'active' => 'boolean',
        'reorder_threshold' => 'integer',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderBy('occurred_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Signed sum of the loaded movements. Expects `movements` to be loaded. */
    public function onHand(): int
    {
        return (int) $this->movements->sum('quantity');
    }

    public function isLow(): bool
    {
        return self::isLowFor($this->onHand(), $this->reorder_threshold);
    }

    public function isExpiringSoon(int $days = self::EXPIRING_WITHIN_DAYS): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->lessThanOrEqualTo(now()->addDays($days));
    }

    public function stockStatus(): string
    {
        return self::statusFor($this->onHand(), $this->reorder_threshold);
    }

    /** The low/out threshold rule — the single source shared by the model, controllers, and dashboard. */
    public static function statusFor(int $onHand, int $threshold): string
    {
        return match (true) {
            $onHand <= 0 => 'out',
            $onHand <= $threshold => 'low',
            default => 'ok',
        };
    }

    public static function isLowFor(int $onHand, int $threshold): bool
    {
        return $onHand <= $threshold;
    }

    /** How many days ahead counts as "expiring soon", everywhere. */
    public const EXPIRING_WITHIN_DAYS = 30;

    /*
     * On-hand as SQL.
     *
     * onHand() above needs `movements` loaded, which is right for one item
     * and wrong for a list: /inventory and the dashboard tile used to read
     * every item's whole ledger and then filter in PHP. Expressed here,
     * the database does the sum and the list can be paginated.
     */
    private const ON_HAND = '(select coalesce(sum(sm.quantity), 0) from stock_movements sm where sm.inventory_item_id = inventory_items.id)';

    public static function onHandSql(): string
    {
        return self::ON_HAND;
    }

    /** Select on_hand alongside the row. */
    public function scopeWithOnHand(Builder $query): Builder
    {
        return $query
            ->select('inventory_items.*')
            ->selectRaw(self::ON_HAND.' as on_hand');
    }

    /** Active and at or below its reorder threshold. */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereRaw(self::ON_HAND.' <= inventory_items.reorder_threshold');
    }

    /** Active and expiring inside the window (a past date counts — it has expired). */
    public function scopeExpiringSoon(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(self::EXPIRING_WITHIN_DAYS)->toDateString());
    }

    /**
     * The projection a list row needs, from the column withOnHand()
     * selected — no ledger loaded.
     *
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        $onHand = (int) $this->on_hand;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'on_hand' => $onHand,
            'reorder_threshold' => $this->reorder_threshold,
            'stock_status' => self::statusFor($onHand, $this->reorder_threshold),
            'supplier' => $this->supplier,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'active' => $this->active,
        ];
    }
}
