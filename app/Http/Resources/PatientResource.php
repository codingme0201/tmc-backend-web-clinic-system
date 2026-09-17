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
            'name' => $this->name,
            'type' => $this->type,
            'courseDept' => $this->course_dept,
            'block' => 'Block 1',
            'contact' => $this->contact ?? '',
            'telephone' => '+63 (02) 8123-4567',
            'emergencyContact' => $this->emergency_contact ?? '',
            'allergies' => $this->allergies,
            'history' => $this->history,
            'status' => $this->status,
            'appointmentsCount' => $this->whenCounted('appointments'),
            'consultationsCount' => $this->whenCounted('consultations'),
            'medicalCertificatesCount' => $this->whenCounted('medicalCertificates'),
            'prescriptionsCount' => $this->whenCounted('prescriptions'),
        ];
    }
}
