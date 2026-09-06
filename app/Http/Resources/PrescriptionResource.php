<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    /**
     * Transform the prescription into the shape the frontend consumes
     * (camelCase keys, ISO dates, nested medication lines, and the related
     * consultation summary when loaded).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'patient' => $this->patient,
            'patientId' => $this->patient_id ?? '',
            'consultationId' => $this->consultation_id,
            'medicalRecordId' => $this->medical_record_id,
            'prescribedById' => $this->prescribed_by_id,
            'prescribedBy' => $this->prescribed_by ?? '',
            'date' => $this->prescription_date->format('Y-m-d'),
            'medications' => $this->medications->map(fn ($medication) => [
                'id' => (string) $medication->id,
                'medicineName' => $medication->medicine_name,
                'dosage' => $medication->dosage ?? '',
                'frequency' => $medication->frequency ?? '',
                'duration' => $medication->duration ?? '',
                'instructions' => $medication->instructions ?? '',
            ])->all(),
            'consultation' => $this->whenLoaded('consultation', function () {
                return $this->consultation ? [
                    'id' => $this->consultation->id,
                    'reference' => $this->consultation->reference,
                    'date' => $this->consultation->date->format('Y-m-d'),
                    'diagnosis' => $this->consultation->diagnosis ?? '',
                    'staff' => $this->consultation->staff ?? '',
                ] : null;
            }),
        ];
    }
}
