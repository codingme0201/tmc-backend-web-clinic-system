<?php

namespace App\Models;

use Database\Factories\ClinicEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'date', 'title', 'description',
    'start_date', 'end_date', 'start_time', 'end_time',
    'all_day', 'type', 'status', 'created_by',
])]
class ClinicEvent extends Model
{
    /** @use HasFactory<ClinicEventFactory> */
    use HasFactory;

    public const TYPES = [
        'Event', 'Holiday', 'Non-Working Day', 'Clinic Closure', 'Health Campaign', 'Vaccination Drive',
        'Medical Mission', 'Dental Mission', 'Activity', 'Seminar', 'Training', 'Meeting', 'Other',
    ];
    public const STATUSES = ['Scheduled', 'Ongoing', 'Completed', 'Cancelled'];

    /**
     * Event types that close the clinic for the whole date range, so no
     * appointments can be booked on those days.
     */
    public const NON_WORKING_TYPES = ['Holiday', 'Non-Working Day', 'Clinic Closure'];

    /**
     * Active non-working events (holidays, closures) covering the date.
     */
    public function scopeNonWorkingOn(Builder $query, string $date): Builder
    {
        return $query->whereIn('type', self::NON_WORKING_TYPES)
            ->where('status', '!=', 'Cancelled')
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereDate('end_date', '>=', $date)
                    ->orWhere(fn ($q2) => $q2->whereNull('end_date')->whereDate('start_date', $date));
            });
    }

    public function isNonWorking(): bool
    {
        return in_array($this->type, self::NON_WORKING_TYPES, true);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'all_day' => 'boolean',
        ];
    }

    /**
     * The admin/user who created this event.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
