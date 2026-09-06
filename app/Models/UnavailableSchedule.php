<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['start_date', 'end_date', 'start_time', 'end_time', 'all_day', 'reason', 'created_by'])]
class UnavailableSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'all_day' => 'boolean',
        ];
    }

    /**
     * The admin who created this block.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
