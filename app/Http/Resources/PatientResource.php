<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the patient into the shape the frontend consumes.
     *
     * `id` is the auto-increment database key (used for API routing).
     * `patientId` and `studentId` are the same exact TMC Student ID (00-000000).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patientId' => $this->patient_id,
            'studentId' => $this->patient_id,
            'name' => $this->name,
            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'lastName' => $this->last_name,
            'age' => $this->age,
            'sex' => $this->sex,
            'type' => $this->type,
            'course' => $this->course_dept,
            'courseDept' => $this->course_dept,
            'block' => $this->block ?? '',
            'address' => $this->address ?? '',
            'nationality' => $this->nationality ?? 'Filipino',
            'contact' => $this->contact ?? '',
            'phone' => $this->contact ?? '',
            // No telephone column exists; kept for client compatibility.
            'telephone' => '',
            'emergencyContactName' => $this->emergency_contact_name,
            'emergencyContactPhone' => $this->emergency_contact_phone,
            'emergencyContact' => $this->emergency_contact ?? ($this->emergency_contact_name ? ($this->emergency_contact_name . ($this->emergency_contact_phone ? ' (' . $this->emergency_contact_phone . ')' : '')) : ''),
            'allergies' => $this->allergies,
            'history' => $this->history,
            'status' => $this->status,
            'registeredOn' => $this->created_at?->copy()->timezone(config('app.timezone'))->toDateString(),
            'lastVisitOn' => $this->whenHas('last_appointment_visit', fn () => max($this->last_appointment_visit ?? '', $this->last_consultation_visit ?? '') ?: null),
            'isProfileComplete' => $this->isProfileComplete(),
            'appointmentsCount' => $this->whenCounted('appointments'),
            'consultationsCount' => $this->whenCounted('consultations'),
            'medicalCertificatesCount' => $this->whenCounted('medicalCertificates'),
            'prescriptionsCount' => $this->whenCounted('prescriptions'),
        ];
    }
}
