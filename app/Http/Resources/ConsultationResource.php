<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationResource extends JsonResource
{
    /**
     * Transform the consultation into the shape the existing frontend consumes
     * (camelCase keys, ISO dates, raw vitals object, nullable workflow times).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'date' => $this->date->format('Y-m-d'),
            'time' => $this->time,
            'patient' => $this->patient,
            'patientId' => $this->patient_id ?? '',
            'appointmentId' => $this->appointment_id,
            'staffId' => $this->staff_id,
            'staff' => $this->staff ?? '',
            'status' => $this->status,
            'chiefComplaint' => $this->chief_complaint ?? '',
            'vitals' => (array) ($this->vitals ?? []),
            'clinicalFindings' => $this->clinical_findings ?? '',
            'diagnosis' => $this->diagnosis ?? '',
            'treatment' => $this->treatment ?? '',
            'disposition' => $this->disposition ?? '',
            'startedAt' => $this->started_at,
            'completedAt' => $this->completed_at,
        ];
    }
}
