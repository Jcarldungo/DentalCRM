<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentPlanItem extends Model
{
    use HasFactory;

    public const PRIORITIES = ['low', 'medium', 'high'];

    public const STATUSES = ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled'];

    /** Statuses that count as still open — the Active group on the patient page. */
    public const OPEN_STATUSES = ['planned', 'scheduled', 'in_progress'];

    /** Statuses worth putting on a bill, which includes work already done. */
    public const BILLABLE_STATUSES = ['planned', 'scheduled', 'in_progress', 'completed'];

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'tooth_number',
        'treatment',
        'estimated_cost',
        'priority',
        'status',
        'notes',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
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
