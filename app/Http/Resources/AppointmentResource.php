<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the appointment into the shape the existing frontend consumes
     * (camelCase keys, ISO dates, 'Unassigned' default for empty staff).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'patient' => $this->patient,
            'patientId' => $this->patient_id,
            'staffId' => $this->staff_id,
            'type' => $this->type,
            'reason' => $this->reason,
            'date' => $this->date->format('Y-m-d'),
            'time' => $this->time,
            'staff' => $this->staff ?: 'Unassigned',
            'status' => $this->status,
            'notes' => $this->notes ?? '',
            'requestedOn' => $this->requested_on->format('Y-m-d'),
        ];
    }
}
