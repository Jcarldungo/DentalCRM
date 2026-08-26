<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToothCondition extends Model
{
    use HasFactory;

    public const CONDITIONS = [
        'healthy',
        'caries',
        'filling',
        'crown',
        'missing',
        'extraction',
        'root_canal',
        'implant',
        'other',
    ];

    /**
     * Append-only: there is no updated_at column, and this tells Eloquent
     * to never try to write one.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'tooth_number',
        'condition',
        'notes',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
