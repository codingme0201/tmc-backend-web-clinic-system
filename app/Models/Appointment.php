<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'patient', 'patient_id', 'staff_id', 'type', 'reason', 'date', 'time',
    'staff', 'status', 'notes', 'requested_on',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * Appointment lifecycle statuses, matching the existing frontend exactly.
     */
    public const STATUSES = [
        'Pending', 'Under Review', 'Approved', 'Rescheduled', 'Rejected', 'Cancelled', 'Completed',
    ];

    /**
     * Allowed status transitions (state machine — the frontend must not be
     * able to jump to an arbitrary status, and terminal states stay terminal).
     *
     * 'Rescheduled' is intentionally not reachable through the generic status
     * endpoint; it is set only by the reschedule action, which additionally
     * validates the new date/time.
     */
    public const TRANSITIONS = [
        'Pending' => ['Under Review', 'Approved', 'Rejected', 'Cancelled'],
        'Under Review' => ['Approved', 'Rejected', 'Cancelled', 'Completed'],
        'Approved' => ['Cancelled', 'Completed'],
        'Rescheduled' => ['Approved', 'Rejected', 'Cancelled'],
        'Rejected' => [],
        'Cancelled' => [],
        'Completed' => [],
    ];

    /**
     * Appointment types offered by the existing Book Appointment form.
     */
    public const TYPES = [
        'Check-up', 'Dental concern', 'Follow-up', 'Fever', 'Vaccination', 'Emergency',
    ];

    /**
     * Time slots offered by the existing Book/Reschedule forms. Kept in sync
     * with the frontend TIME_SLOTS constant.
     */
    public const TIME_SLOTS = [
        '08:00 AM', '08:30 AM', '09:00 AM', '09:15 AM', '10:00 AM', '10:30 AM',
        '11:00 AM', '01:00 PM', '01:30 PM', '02:30 PM', '03:00 PM', '03:30 PM', '04:30 PM',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'requested_on' => 'date:Y-m-d',
        ];
    }

    /**
     * Consultations started from this appointment (Module 4 integration).
     */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'appointment_id');
    }

    /**
     * Whether this appointment may move to the given status.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Next sequential reference for the given appointment date, e.g.
     * APT-2026-011. The numeric part is zero-padded and derived from the
     * highest existing reference for the same year so it never collides.
     *
     * Pass `$lock = true` inside a DB transaction to lock the scanned range
     * so concurrent bookings cannot generate the same reference.
     */
    public static function nextReference(string $date, bool $lock = false): string
    {
        $year = date('Y', strtotime($date));
        $prefix = "APT-{$year}-";
        $query = static::query()->where('reference', 'like', $prefix.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $max = $query->max('reference');
        $next = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
