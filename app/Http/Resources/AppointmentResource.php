<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\GatesClinicalDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    use GatesClinicalDetails;

    /**
     * Transform the appointment into the shape the existing frontend consumes
     * (camelCase keys, ISO dates, 'Unassigned' default for empty staff).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $consultation = $this->relationLoaded('consultations')
            ? $this->consultations->sortByDesc('id')->first()
            : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'patient' => $this->patient,
            'patientId' => $this->patient_id,
            'staffId' => $this->staff_id,
            'type' => $this->type,
            'visitType' => $this->visit_type ?? 'New Consultation',
            'isFollowUp' => $this->isFollowUp(),
            'previousConsultationId' => $this->previous_consultation_id,
            'previousConsultation' => $this->whenLoaded('previousConsultation', fn () => $this->previousConsultation ? [
                'id' => $this->previousConsultation->id,
                'reference' => $this->previousConsultation->reference,
                'date' => $this->previousConsultation->date?->format('Y-m-d'),
                'staff' => $this->previousConsultation->staff ?? '',
                ...($this->canSeeClinicalDetails($request) ? [
                    'chiefComplaint' => $this->previousConsultation->chief_complaint ?? '',
                    'diagnosis' => $this->previousConsultation->diagnosis ?? '',
                    'treatment' => $this->previousConsultation->treatment ?? '',
                    'followUpNotes' => $this->previousConsultation->follow_up_notes ?? '',
                ] : []),
            ] : null),
            'reason' => $this->reason,
            'date' => $this->date->format('Y-m-d'),
            'time' => $this->time,
            'staff' => $this->staff ?: 'Unassigned',
            'staffRole' => $this->whenLoaded('staffUser', fn () => $this->staffUser?->role?->name),
            'status' => $this->status,
            'notes' => $this->notes ?? '',
            'requestedOn' => $this->requested_on->format('Y-m-d'),
            'queueNumber' => $this->queue_number,
            'checkedInAt' => $this->checked_in_at?->toIso8601String(),
            'consultation' => $consultation ? [
                'id' => $consultation->id,
                'reference' => $consultation->reference,
                'status' => $consultation->status,
            ] : null,
        ];
    }
}
