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
     * `patientId` is the human-readable registry id (e.g. "2023-0104").
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
            'type' => $this->type,
            'course' => $this->course_dept,
            'courseDept' => $this->course_dept,
            'block' => $this->block ?? 'Block 1',
            'address' => $this->address ?? 'Tagum Norte, Trinidad, Bohol, Philippines',
            'nationality' => $this->nationality ?? 'Filipino',
            'contact' => $this->contact ?? '',
            'phone' => $this->contact ?? '',
            'telephone' => '+63 (02) 8123-4567',
            'emergencyContactName' => $this->emergency_contact_name,
            'emergencyContactPhone' => $this->emergency_contact_phone,
            'emergencyContact' => $this->emergency_contact ?? ($this->emergency_contact_name ? ($this->emergency_contact_name . ($this->emergency_contact_phone ? ' (' . $this->emergency_contact_phone . ')' : '')) : ''),
            'allergies' => $this->allergies,
            'history' => $this->history,
            'status' => $this->status,
            'isProfileComplete' => $this->isProfileComplete(),
            'appointmentsCount' => $this->whenCounted('appointments'),
            'consultationsCount' => $this->whenCounted('consultations'),
            'medicalCertificatesCount' => $this->whenCounted('medicalCertificates'),
            'prescriptionsCount' => $this->whenCounted('prescriptions'),
        ];
    }
}
