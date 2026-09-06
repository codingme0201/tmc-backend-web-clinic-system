<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'role', 'shift', 'status', 'user_id'])]
class Staff extends Model
{
    public const STATUSES = ['On duty', 'Break', 'Off duty'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
