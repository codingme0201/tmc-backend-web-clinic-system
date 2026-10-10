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

    /**
     * Append an entry to the audit trail on behalf of a user.
     */
    public static function record(?User $user, string $action, ?string $module = null): self
    {
        return self::create([
            'user_id' => $user?->id,
            'time' => now()->format('h:i A'),
            'user' => $user?->name ?? 'System',
            'module' => $module,
            'action' => $action,
        ]);
    }
}
