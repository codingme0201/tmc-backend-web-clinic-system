<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'date', 'time', 'patient', 'patient_id', 'appointment_id', 'staff_id', 'staff', 'status',
    'visit_type', 'type', 'previous_consultation_id',
    'chief_complaint', 'vitals', 'clinical_findings', 'diagnosis', 'treatment',
    'disposition', 'follow_up_required', 'follow_up_date', 'follow_up_notes', 'follow_up_appointment_id',
    'started_at', 'completed_at',
])]
class Consultation extends Model
{
    /** Clinical services, including those on existing consultations. */
    public const TYPES = [
        'General Check-up', 'Follow-up Consultation', 'Other', 'Check-up', 'Dental concern', 'Follow-up', 'Fever', 'Vaccination', 'Emergency',
    ];

    /**
     * Consultation lifecycle statuses, matching the existing frontend exactly.
     */
    public const STATUSES = ['Scheduled', 'In Progress', 'Completed'];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'vitals' => 'array',
            'follow_up_required' => 'boolean',
            'follow_up_date' => 'date:Y-m-d',
        ];
    }

    /**
     * The earlier consultation this follow-up visit continues from.
     */
    public function previousConsultation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_consultation_id');
    }

    /**
     * The follow-up appointment scheduled from this consultation.
     */
    public function followUpAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'follow_up_appointment_id');
    }

    public function isFollowUp(): bool
    {
        return $this->visit_type === Appointment::VISIT_FOLLOW_UP;
    }

    /**
     * The appointment this consultation was started from, when applicable.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /** A clinical visit must belong to the same patient and be checked in. */
    public function hasCheckedInVisit(): bool
    {
        $appointment = $this->appointment;

        return $appointment && $appointment->checked_in_at !== null
            && $this->patient_id && $appointment->patient_id === $this->patient_id
            && in_array($appointment->status, ['Approved', 'Completed'], true);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function medicalCertificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * Next sequential reference for the given consultation date, e.g.
     * CONS-2026-011. Mirrors Appointment::nextReference.
     */
    public static function nextReference(string $date, bool $lock = false): string
    {
        $year = date('Y', strtotime($date));
        $prefix = "CONS-{$year}-";
        $query = static::query()->where('reference', 'like', $prefix.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $max = $query->max('reference');
        $next = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
