<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * An append-only record of the actions worth being able to ask about
 * afterwards.
 *
 * Deliberately *not* a model observer over every save. Two reasons:
 *
 *  - A blind dump of changed attributes would copy patient names, emails,
 *    phone numbers, and clinical free text into a second table that
 *    nothing else guards or thinks about. Every call site here passes the
 *    context it wants recorded, so what lands in the log is a decision.
 *  - An observer logs everything equally, which makes the log unreadable.
 *    These are the actions a clinic would actually go looking for: money
 *    moving, records being destroyed or refused, and clinical state
 *    changing.
 *
 * There is no update or destroy path. `Auth::user()` is read here rather
 * than passed in, so a caller cannot attribute an action to someone else.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Actions the app records, and how to phrase them in the log. */
    public const ACTIONS = [
        'patient.deleted' => 'Patient deleted',
        'patient.delete_refused' => 'Patient delete refused',
        'appointment.status_changed' => 'Appointment status changed',
        'invoice.issued' => 'Invoice issued',
        'invoice.voided' => 'Invoice voided',
        'payment.recorded' => 'Payment recorded',
        'prescription.discontinued' => 'Prescription discontinued',
        'inventory.archived' => 'Inventory item archived',
        'inventory.restored' => 'Inventory item restored',
        'stock.recorded' => 'Stock movement recorded',
        'provider.deleted' => 'Provider deleted',
        'provider.delete_refused' => 'Provider delete refused',
        'account.deleted' => 'Staff account deleted',
    ];

    protected $table = 'audit_log';

    protected $fillable = [
        'user_id',
        'actor_name',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'context',
        'ip',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record one action.
     *
     * @param  string  $action  One of self::ACTIONS.
     * @param  Model|null  $subject  What it happened to.
     * @param  string|null  $label  How to name that subject in the log —
     *                              passed in because only the caller knows
     *                              which field reads well.
     * @param  array<string, mixed>  $context  Chosen by the caller. Keep it
     *                                         to statuses, amounts, and
     *                                         reasons; not free text or PII.
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?string $label = null,
        array $context = [],
    ): self {
        $user = Auth::user();

        return self::create([
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label,
            'context' => $context ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
