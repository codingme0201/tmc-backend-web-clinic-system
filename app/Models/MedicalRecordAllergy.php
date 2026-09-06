<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medical_record_id', 'allergen', 'reaction', 'severity', 'date_recorded', 'notes'])]
class MedicalRecordAllergy extends Model
{
    protected function casts(): array
    {
        return [
            'date_recorded' => 'date:Y-m-d',
        ];
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }
}
