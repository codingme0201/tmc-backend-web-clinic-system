<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'weekday', 'effective_from', 'start_time', 'end_time', 'excluded_dates'])]
class WeeklyStaffSchedule extends Model
{
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'effective_from' => 'date:Y-m-d', 'excluded_dates' => 'array'];
    }
}
