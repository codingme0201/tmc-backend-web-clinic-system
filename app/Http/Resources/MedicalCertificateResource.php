<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicalCertificateResource extends JsonResource
{
    /**
     * Transform the certificate into the shape the frontend consumes
     * (camelCase keys, ISO dates, nullable validity window).
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
            'issuedById' => $this->issued_by_id,
            'requestedById' => $this->requested_by_id,
            'approvedById' => $this->approved_by_id,
            'rejectedById' => $this->rejected_by_id,
            'issuedBy' => $this->issued_by ?? '',
            'requestedBy' => $this->requested_by ?? '',
            'approvedBy' => $this->approved_by ?? '',
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedBy' => $this->rejected_by ?? '',
            'rejectedAt' => $this->rejected_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason ?? '',
            'purpose' => $this->purpose,
            'diagnosis' => $this->diagnosis ?? '',
            'recommendation' => $this->recommendation ?? '',
            'issueDate' => $this->issue_date->format('Y-m-d'),
            'validUntil' => $this->valid_until ? $this->valid_until->format('Y-m-d') : null,
            'status' => $this->status,
            'issuedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
