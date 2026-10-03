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
            'visitType' => $this->visit_type ?? 'New Consultation',
            'isFollowUp' => $this->isFollowUp(),
            'previousConsultationId' => $this->previous_consultation_id,
            'previousConsultation' => $this->whenLoaded('previousConsultation', fn () => $this->previousConsultation ? [
                'id' => $this->previousConsultation->id,
                'reference' => $this->previousConsultation->reference,
                'date' => $this->previousConsultation->date?->format('Y-m-d'),
                'staff' => $this->previousConsultation->staff ?? '',
                'chiefComplaint' => $this->previousConsultation->chief_complaint ?? '',
                'diagnosis' => $this->previousConsultation->diagnosis ?? '',
                'treatment' => $this->previousConsultation->treatment ?? '',
                'followUpNotes' => $this->previousConsultation->follow_up_notes ?? '',
            ] : null),
            'chiefComplaint' => $this->chief_complaint ?? '',
            'vitals' => (array) ($this->vitals ?? []),
            'clinicalFindings' => $this->clinical_findings ?? '',
            'diagnosis' => $this->diagnosis ?? '',
            'treatment' => $this->treatment ?? '',
            'disposition' => $this->disposition ?? '',
            'followUpRequired' => (bool) $this->follow_up_required,
            'followUpDate' => $this->follow_up_date?->format('Y-m-d'),
            'followUpNotes' => $this->follow_up_notes ?? '',
            'followUpAppointmentId' => $this->follow_up_appointment_id,
            'followUpAppointment' => $this->whenLoaded('followUpAppointment', fn () => $this->followUpAppointment ? [
                'id' => $this->followUpAppointment->id,
                'reference' => $this->followUpAppointment->reference,
                'date' => $this->followUpAppointment->date?->format('Y-m-d'),
                'time' => $this->followUpAppointment->time,
                'staff' => $this->followUpAppointment->staff ?: 'Unassigned',
                'status' => $this->followUpAppointment->status,
            ] : null),
            'startedAt' => $this->started_at,
            'completedAt' => $this->completed_at,
        ];
    }
}
