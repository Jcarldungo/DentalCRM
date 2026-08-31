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

    /**
     * Does this patient have anything attached that a delete would take
     * with it? Every one of these tables cascades on
     * `patients.id`, and five of the six are documented append-only, so
     * `DELETE /patients/{patient}` is the one route in the app that can
     * erase clinical and financial history. `PatientController::destroy`
     * refuses when this is true.
     *
     * Six `exists()` queries, but this runs once on a rare action.
     */
    public function hasClinicalOrBillingHistory(): bool
    {
        return $this->appointments()->exists()
            || $this->dentalRecords()->exists()
            || $this->toothConditions()->exists()
            || $this->treatmentPlanItems()->exists()
            || $this->prescriptions()->exists()
            || $this->invoices()->exists();
    }

    /** The recall interval used when a patient has no override. */
    public const DEFAULT_RECALL_MONTHS = 6;

    /**
     * Patients whose last completed cleaning is further back than their
     * recall interval.
     *
     * Done in SQL. This ran on every dashboard load and used to fetch
     * *every* patient plus *every* cleaning appointment they had ever had
     * into memory, then discard almost all of it — the single worst read
     * in the application, and one that got worse with every patient added.
     *
     * The join finds each patient's most recent completed cleaning, and
     * the interval comparison happens in the WHERE clause, so only the
     * overdue rows come back.
     */
    public static function dueForRecall(?\Carbon\Carbon $asOf = null): \Illuminate\Support\Collection
    {
        $asOf = $asOf ?? now();

        $lastCleaning = \Illuminate\Support\Facades\DB::table('appointments')
            ->selectRaw('patient_id, max(start_time) as last_cleaning_at')
            ->where('type', 'cleaning')
            ->where('status', 'completed')
            ->groupBy('patient_id');

        return static::query()
            ->joinSub($lastCleaning, 'recall', 'recall.patient_id', '=', 'patients.id')
            ->select('patients.*', 'recall.last_cleaning_at')
            ->selectRaw(
                'date_add(recall.last_cleaning_at, interval coalesce(patients.recall_interval_months, ?) month) as due_at',
                [self::DEFAULT_RECALL_MONTHS],
            )
            ->whereRaw(
                'date_add(recall.last_cleaning_at, interval coalesce(patients.recall_interval_months, ?) month) <= ?',
                [self::DEFAULT_RECALL_MONTHS, $asOf],
            )
            ->orderBy('due_at')
            ->orderBy('patients.id')
            ->get()
            ->map(function (self $patient) {
                $patient->recall_last_cleaning_at = \Carbon\Carbon::parse($patient->last_cleaning_at);
                $patient->recall_due_date = \Carbon\Carbon::parse($patient->due_at);

                return $patient;
            })
            ->values();
    }
}
