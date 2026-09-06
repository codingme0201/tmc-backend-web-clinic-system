<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform the staff member into the shape the Dashboard roster widget
     * consumes ({ id, name, role, shift, status }).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'name' => $this->name,
            'role' => $this->role,
            'shift' => $this->shift,
            'status' => $this->status,
        ];
    }
}
