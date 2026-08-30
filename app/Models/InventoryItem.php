<?php

namespace App\Models;

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

    public function isExpiringSoon(int $days = 30): bool
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
}
