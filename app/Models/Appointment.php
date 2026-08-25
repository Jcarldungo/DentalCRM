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
        'reminder_sent_at' => 'datetime',
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
     * How many appointments already occupy this date + time-of-day slot,
     * clinic-wide: still-pending requests for it, plus already-scheduled
     * appointments whose real start_time falls in that half of the day.
     * Declined/cancelled/no-show appointments free their slot and don't
     * count.
     *
     * A confirmed request keeps its original preferred_date/
     * preferred_time_of_day (confirming doesn't clear them), so the pending
     * branch is scoped to status 'requested' — otherwise a request
     * confirmed into a different time-of-day than it originally asked for
     * would occupy both its old slot and its real one forever.
     */
    public static function countBookedForSlot(Carbon $date, string $timeOfDay): int
    {
        $afternoonStartsAt = config('clinic.afternoon_starts_at');
        $dayStart = $date->clone()->startOfDay();
        $boundary = $date->clone()->setTimeFromTimeString($afternoonStartsAt);
        $dayEnd = $date->clone()->endOfDay();

        // Half-open at the boundary so a start_time of exactly
        // afternoon_starts_at counts once, as afternoon, not twice.
        [$rangeStart, $rangeEnd] = $timeOfDay === 'afternoon'
            ? [$boundary, $dayEnd]
            : [$dayStart, $boundary->clone()->subSecond()];

        return static::query()
            ->whereNotIn('status', self::SLOT_FREEING_STATUSES)
            ->where(function ($query) use ($date, $timeOfDay, $rangeStart, $rangeEnd) {
                $query
                    ->where(function ($requested) use ($date, $timeOfDay) {
                        $requested
                            ->where('status', 'requested')
                            ->whereDate('preferred_date', $date)
                            ->where('preferred_time_of_day', $timeOfDay);
                    })
                    ->orWhere(function ($scheduled) use ($rangeStart, $rangeEnd) {
                        $scheduled
                            ->whereNotNull('start_time')
                            ->whereBetween('start_time', [$rangeStart, $rangeEnd]);
                    });
            })
            ->count();
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
