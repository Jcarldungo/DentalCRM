<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /*
     * The same three figures as SQL.
     *
     * The methods above need `items` and `payments` loaded, which is right
     * for one invoice and wrong for a list of them: /invoices, the
     * dashboard tile, the patient billing summary, and the reports A/R
     * figure each used to load every line item and every payment of every
     * invoice just to reach a number, then filter in PHP. Expressed here,
     * the database does the arithmetic and the list can be paginated.
     *
     * Kept beside the PHP versions on purpose — they must agree, and
     * InvoiceTest asserts that they do.
     */
    private const SUM_ITEMS = '(select coalesce(sum(ii.amount), 0) from invoice_items ii where ii.invoice_id = invoices.id)';

    private const SUM_PAYMENTS = '(select coalesce(sum(p.amount), 0) from payments p where p.invoice_id = invoices.id)';

    public static function subtotalSql(): string
    {
        return self::SUM_ITEMS;
    }

    public static function totalSql(): string
    {
        return '('.self::SUM_ITEMS.' - invoices.discount_amount)';
    }

    public static function amountPaidSql(): string
    {
        return self::SUM_PAYMENTS;
    }

    public static function balanceSql(): string
    {
        return '('.self::SUM_ITEMS.' - invoices.discount_amount - '.self::SUM_PAYMENTS.')';
    }

    /** Select subtotal, total, amount_paid, and balance alongside the row. */
    public function scopeWithMoney(Builder $query): Builder
    {
        return $query
            ->select('invoices.*')
            ->selectRaw(self::subtotalSql().' as subtotal_sum')
            ->selectRaw(self::totalSql().' as total_sum')
            ->selectRaw(self::amountPaidSql().' as amount_paid_sum')
            ->selectRaw(self::balanceSql().' as balance_sum');
    }

    /** Issued and not yet settled — the definition of "outstanding" everywhere. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', 'issued')->whereRaw(self::balanceSql().' > 0');
    }

    /** Issued and settled. "Paid" is derived, never stored. */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', 'issued')->whereRaw(self::balanceSql().' <= 0');
    }

    /**
     * The projection a list row needs, from the columns withMoney()
     * selected — no relations loaded.
     *
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        $balance = round((float) $this->balance_sum, 2);

        return [
            'id' => $this->id,
            'number' => $this->number(),
            'patient_id' => $this->patient_id,
            'patient_name' => $this->patient->full_name,
            'status' => $this->status,
            'total' => round((float) $this->total_sum, 2),
            'amount_paid' => round((float) $this->amount_paid_sum, 2),
            'balance' => $balance,
            'is_paid' => $this->status === 'issued' && $balance <= 0.0,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
