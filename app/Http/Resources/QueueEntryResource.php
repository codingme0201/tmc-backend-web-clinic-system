<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\GatesClinicalDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueEntryResource extends JsonResource
{
    use GatesClinicalDetails;

    /**
     * A queue entry: the appointment plus its queue status and FIFO
     * position (overall and within the assigned doctor's line).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $consultation = $this->consultations->sortByDesc('id')->first();

        return [
            'appointmentId' => $this->id,
            'reference' => $this->reference,
            'patient' => $this->patient,
            'patientId' => $this->patient_id,
            'type' => $this->type,
            'visitType' => $this->visit_type ?? 'New Consultation',
            'isFollowUp' => $this->isFollowUp(),
            'previousConsultation' => $this->previousConsultation ? [
                'id' => $this->previousConsultation->id,
                'reference' => $this->previousConsultation->reference,
                'date' => $this->previousConsultation->date?->format('Y-m-d'),
                ...($this->canSeeClinicalDetails($request) ? ['diagnosis' => $this->previousConsultation->diagnosis ?? ''] : []),
            ] : null,
            'reason' => $this->reason,
            'date' => $this->date->format('Y-m-d'),
            'time' => $this->time,
            'staffId' => $this->staff_id,
            'staff' => $this->staff ?: 'Unassigned',
            'staffRole' => $this->staffUser?->role?->name,
            'appointmentStatus' => $this->status,
            'queueStatus' => $this->queue_status,
            'queueNumber' => $this->queue_number,
            'position' => $this->queue_position,
            'linePosition' => $this->lane_position,
            'checkedInAt' => $this->checked_in_at?->toIso8601String(),
            'consultation' => $consultation ? [
                'id' => $consultation->id,
                'reference' => $consultation->reference,
                'status' => $consultation->status,
            ] : null,
        ];
    }
}
