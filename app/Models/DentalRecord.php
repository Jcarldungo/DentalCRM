<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DentalRecord extends Model
{
    use HasFactory;

    public const TYPES = ['consultation', 'procedure', 'follow_up', 'other'];

    /**
     * Append-only: there is no updated_at column, and this tells Eloquent
     * to never try to write one.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'type',
        'examination',
        'diagnosis',
        'procedure',
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
