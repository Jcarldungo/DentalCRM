<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment against an invoice. Append-only: there is no update or
 * destroy route, controller method, or UI. A mistaken payment is
 * corrected by a future refund concept, not by editing this row.
 */
class Payment extends Model
{
    use HasFactory;

    public const METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other'];

    protected $fillable = [
        'invoice_id',
        'amount',
        'method',
        'paid_on',
        'reference',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date:Y-m-d',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
