<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'date', 'start_time', 'end_time', 'status', 'notes', 'weekly_staff_schedule_id', 'is_override'])]
class StaffSchedule extends Model
{
    /**
     * Availability statuses for a schedule entry.
     */
    public const STATUSES = ['Available', 'Unavailable'];

    public static function canManageFor(User $actor, int $userId): bool
    {
        return $actor->isAdmin() || ($actor->isClinician() && $actor->id === $userId);
    }

    public function ensureAccessibleBy(User $actor): void
    {
        abort_if($actor->isClinician() && $actor->id !== $this->user_id, 403, 'You can only access your own schedules.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_override' => 'boolean',
        ];
    }

    /**
     * The user (doctor/nurse) this schedule belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
