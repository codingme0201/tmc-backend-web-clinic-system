<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the user into the shape the frontend consumes.
     *
     * Password hashes and tokens are never exposed. The role name and
     * permission list are included so the frontend can display role
     * information and gate UI elements without an extra request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status ?? 'active',
            'role_id' => $this->role_id,
            'patient_id' => $this->patient_id,
            'patientId' => $this->patient_id,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'description' => $this->role->description,
            ]),
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient?->id,
                'patientId' => $this->patient?->patient_id,
                'name' => $this->patient?->name,
                'type' => $this->patient?->type,
                'courseDept' => $this->patient?->course_dept,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
