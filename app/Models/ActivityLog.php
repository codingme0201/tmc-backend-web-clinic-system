<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'time', 'user', 'module', 'action'])]
class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
