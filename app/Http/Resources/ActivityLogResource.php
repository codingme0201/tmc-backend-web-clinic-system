<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    /**
     * Transform the log entry into the shape the audit log page consumes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'time' => $this->time,
            'user' => $this->user,
            'module' => $this->module,
            'action' => $this->action,
            'role' => $this->whenLoaded('author', fn () => $this->author?->role?->name ?? ($this->user === 'System' ? 'System' : null)),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
