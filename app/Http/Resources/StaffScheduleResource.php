<?php

namespace App\Http\Resources;

use App\Support\ClinicSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffScheduleResource extends JsonResource
{
    /**
     * Transform the staff schedule into the shape the frontend consumes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'date' => $this->date?->format('Y-m-d'),
            'startTime' => ClinicSchedule::normalize($this->start_time),
            'endTime' => ClinicSchedule::normalize($this->end_time),
            'status' => $this->status,
            'notes' => $this->notes,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role?->name,
            ]),
            'bookedAppointments' => $this->when(isset($this->booked_appointments), fn () => $this->booked_appointments->map(fn ($a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'patient' => $a->patient,
                'time' => $a->time,
                'visitType' => $a->visit_type ?? 'New Consultation',
                'status' => $a->status,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
