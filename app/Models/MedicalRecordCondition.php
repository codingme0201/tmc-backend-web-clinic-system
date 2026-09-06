<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medical_record_id', 'name', 'status', 'diagnosed_date', 'notes'])]
class MedicalRecordCondition extends Model
{
    protected function casts(): array
    {
        return [
            'diagnosed_date' => 'date:Y-m-d',
        ];
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }
}
