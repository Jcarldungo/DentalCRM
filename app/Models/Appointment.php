<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    public const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];

    public const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'no_show', 'declined'];

    public const TIMES_OF_DAY = ['morning', 'afternoon'];

    /**
     * Statuses whose appointment no longer occupies its slot, so it should
     * not block another booking at the same time.
     */
    public const SLOT_FREEING_STATUSES = ['cancelled', 'declined', 'no_show'];

    protected $fillable = [
        'patient_id',
        'provider_id',
        'start_time',
        'end_time',
        'type',
        'status',
        'service_interest',
        'dentist_preference',
        'preferred_date',
        'preferred_time_of_day',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'preferred_date' => 'date:Y-m-d',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Does another appointment for this provider overlap [$start, $end)?
     *
     * Half-open on purpose: one appointment ending at 09:30 and the next
     * starting at 09:30 do not conflict. Pending requests (no start_time)
     * hold no slot, and cancelled/declined/no-show appointments release
     * theirs.
     */
    public static function hasConflict(int $providerId, Carbon $start, Carbon $end, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('provider_id', $providerId)
            ->whereNotNull('start_time')
            ->whereNotIn('status', self::SLOT_FREEING_STATUSES)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }
}
