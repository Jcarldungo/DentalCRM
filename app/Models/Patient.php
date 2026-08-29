<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<\Database\Factories\PatientFactory> */
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'phone',
        'email',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
        'recall_interval_months',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function dentalRecords(): HasMany
    {
        return $this->hasMany(DentalRecord::class)->latest('created_at');
    }

    public function toothConditions(): HasMany
    {
        return $this->hasMany(ToothCondition::class)->latest('created_at')->latest('id');
    }

    public function treatmentPlanItems(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class)->oldest('created_at')->oldest('id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->latest('created_at')->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('created_at')->latest('id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public static function dueForRecall(?\Carbon\Carbon $asOf = null): \Illuminate\Support\Collection
    {
        $asOf = $asOf ?? now();

        return static::with(['appointments' => function ($query) {
                $query->where('type', 'cleaning')
                    ->where('status', 'completed')
                    ->orderByDesc('start_time');
            }])
            ->get()
            ->map(function (self $patient) {
                $lastCleaning = $patient->appointments->first();

                if (! $lastCleaning) {
                    return null;
                }

                $interval = $patient->recall_interval_months ?? 6;
                $patient->recall_last_cleaning_at = $lastCleaning->start_time;
                $patient->recall_due_date = $lastCleaning->start_time->copy()->addMonths($interval);

                return $patient;
            })
            ->filter()
            ->filter(fn (self $patient) => $patient->recall_due_date->lessThanOrEqualTo($asOf))
            ->sortBy('recall_due_date')
            ->values();
    }
}
