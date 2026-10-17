<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform a duty-roster entry into the shape the Dashboard roster
     * widget consumes ({ id, userId, name, role, shift, status, ... }).
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
            'department' => $this->department,
            'shift' => $this->shift,
            'status' => $this->status,
            'scheduledToday' => (bool) $this->scheduled_today,
            'onShiftNow' => (bool) $this->on_shift_now,
            'statusSource' => $this->status_source ?? 'schedule',
        ];
    }
}
