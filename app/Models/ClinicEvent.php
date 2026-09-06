<?php

namespace App\Models;

use Database\Factories\ClinicEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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

    public const TYPES = ['Event', 'Holiday', 'Activity', 'Seminar', 'Meeting', 'Other'];
    public const STATUSES = ['Scheduled', 'Ongoing', 'Completed', 'Cancelled'];

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
