<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\StaffProfile;
use App\Support\ClinicSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicStaffResource extends JsonResource
{
    /**
     * A clinic staff member with profile, credentials and (on the detail
     * view) upcoming schedule and assigned appointments.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->staffProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status ?? 'active',
            'role' => $this->role?->name,
            'roleDescription' => $this->role?->description,
            'position' => $profile?->position ?? '',
            'specialization' => $profile?->specialization ?? '',
            'contactNumber' => $profile?->contact_number ?? '',
            'credentials' => [
                'licenseType' => $profile?->license_type ?? '',
                'licenseNumber' => $profile?->license_number ?? '',
                'licenseIssuedAt' => $profile?->license_issued_at?->format('Y-m-d'),
                'licenseExpiresAt' => $profile?->license_expires_at?->format('Y-m-d'),
                'isExpired' => (bool) $profile?->isLicenseExpired(),
                'otherCredentials' => $profile?->other_credentials ?? '',
                'status' => $profile?->credential_status ?? 'Not Submitted',
                'verifiedBy' => $profile?->verifier?->name,
                'verifiedAt' => $profile?->verified_at?->toIso8601String(),
                'verificationNotes' => $profile?->verification_notes ?? '',
                'verificationUrl' => StaffProfile::PRC_VERIFICATION_URL,
            ],
            'todayAppointmentsCount' => (int) ($this->today_appointments_count ?? 0),
            'schedules' => $this->whenLoaded('staffSchedules', fn () => $this->staffSchedules->map(fn ($s) => [
                'id' => $s->id,
                'date' => $s->date?->format('Y-m-d'),
                'startTime' => ClinicSchedule::normalize($s->start_time),
                'endTime' => ClinicSchedule::normalize($s->end_time),
                'status' => $s->status,
            ])->values()),
            'upcomingAppointments' => $this->whenLoaded('assignedAppointments', fn () => Appointment::sortFifo($this->assignedAppointments)
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'patient' => $a->patient,
                    'date' => $a->date?->format('Y-m-d'),
                    'time' => $a->time,
                    'visitType' => $a->visit_type ?? 'New Consultation',
                    'status' => $a->status,
                ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
