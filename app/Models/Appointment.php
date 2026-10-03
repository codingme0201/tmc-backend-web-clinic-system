<?php

namespace App\Models;

use App\Support\ClinicSchedule;
use Database\Factories\AppointmentFactory;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'patient', 'patient_id', 'staff_id', 'type', 'visit_type', 'previous_consultation_id',
    'reason', 'date', 'time', 'staff', 'status', 'notes', 'requested_on', 'queue_number', 'checked_in_at',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * Appointment lifecycle statuses, matching the existing frontend exactly.
     */
    public const STATUSES = [
        'Pending', 'Under Review', 'Approved', 'Rescheduled', 'Rejected', 'Cancelled', 'Completed', 'No-Show',
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
        'Pending' => ['Under Review', 'Approved', 'Rejected', 'Cancelled', 'No-Show'],
        'Under Review' => ['Approved', 'Rejected', 'Cancelled', 'Completed', 'No-Show'],
        'Approved' => ['Cancelled', 'Completed', 'No-Show'],
        'Rescheduled' => ['Approved', 'Rejected', 'Cancelled', 'Completed', 'No-Show'],
        'Rejected' => [],
        'Cancelled' => [],
        'Completed' => [],
        'No-Show' => [],
    ];

    /**
     * Service categories (the reason category of the visit). "Follow-up" is
     * still accepted from older clients and is stored as a Follow-up visit.
     */
    public const TYPES = [
        'Check-up', 'Dental concern', 'Follow-up', 'Fever', 'Vaccination', 'Emergency',
    ];

    public const VISIT_NEW = 'New Consultation';

    public const VISIT_FOLLOW_UP = 'Follow-up Consultation';

    public const VISIT_TYPES = [self::VISIT_NEW, self::VISIT_FOLLOW_UP];

    /**
     * Bookable time slots — shared with consultations (ClinicSchedule).
     */
    public const TIME_SLOTS = ClinicSchedule::TIME_SLOTS;

    /**
     * Statuses that still occupy a slot / belong to the day's queue.
     */
    public const ACTIVE_STATUSES = ['Pending', 'Under Review', 'Approved', 'Rescheduled'];

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
            'queue_number' => 'integer',
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * The doctor/nurse assigned to this appointment.
     */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * Consultations started from this appointment (Module 4 integration).
     */
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'appointment_id');
    }

    /**
     * The consultation this follow-up visit continues from.
     */
    public function previousConsultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'previous_consultation_id');
    }

    public function isFollowUp(): bool
    {
        return $this->visit_type === self::VISIT_FOLLOW_UP;
    }

    /**
     * Resolve the visit type, treating the legacy "Follow-up" type as a
     * follow-up visit.
     */
    public static function resolveVisitType(?string $visitType, ?string $type, ?int $previousConsultationId = null): string
    {
        if (in_array($visitType, self::VISIT_TYPES, true)) {
            return $visitType;
        }

        return ($type === 'Follow-up' || $previousConsultationId) ? self::VISIT_FOLLOW_UP : self::VISIT_NEW;
    }

    /**
     * FIFO order: earliest date, then earliest time slot (compared as
     * minutes, so "01:00 PM" sorts after "11:30 AM"), then check-in order,
     * then whoever booked first.
     */
    public static function sortFifo(Collection $appointments): Collection
    {
        return $appointments->sort(function (self $a, self $b) {
            return [
                $a->date?->format('Y-m-d'),
                ClinicSchedule::toMinutes($a->time) ?? PHP_INT_MAX,
                $a->queue_number ?? PHP_INT_MAX,
                $a->created_at?->getTimestamp() ?? 0,
                $a->id,
            ] <=> [
                $b->date?->format('Y-m-d'),
                ClinicSchedule::toMinutes($b->time) ?? PHP_INT_MAX,
                $b->queue_number ?? PHP_INT_MAX,
                $b->created_at?->getTimestamp() ?? 0,
                $b->id,
            ];
        })->values();
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
