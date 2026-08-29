<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient invoice. Line items, discount, and notes are editable only
 * while status is 'draft'; issuing freezes them. Every money figure
 * below 'discount_amount' is DERIVED from the loaded items/payments —
 * nothing is stored. "Paid" is a derived display state (issued +
 * balance <= 0), not a status. See
 * docs/superpowers/specs/2026-08-29-invoicing-payments-design.md.
 */
class Invoice extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'issued', 'void'];

    protected $fillable = [
        'patient_id',
        'discount_amount',
        'notes',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Display-only invoice number, derived from the primary key. */
    public function number(): string
    {
        return 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function subtotal(): float
    {
        return round((float) $this->items->sum('amount'), 2);
    }

    public function total(): float
    {
        return round($this->subtotal() - (float) $this->discount_amount, 2);
    }

    public function amountPaid(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round($this->total() - $this->amountPaid(), 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'issued' && $this->balance() <= 0.0;
    }
}
