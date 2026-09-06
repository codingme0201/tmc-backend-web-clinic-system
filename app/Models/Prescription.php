<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'patient', 'patient_id', 'consultation_id', 'medical_record_id',
    'prescribed_by_id', 'prescribed_by', 'prescription_date',
])]
class Prescription extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prescription_date' => 'date:Y-m-d',
        ];
    }

    /**
     * The medication lines written on this prescription, in entry order.
     */
    public function medications(): HasMany
    {
        return $this->hasMany(PrescriptionMedication::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The consultation this prescription was written during or after, when
     * applicable.
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    /**
     * The patient's medical record this prescription composes into, when one
     * exists.
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function prescribedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by_id');
    }

    /**
     * Next sequential reference for the given prescription date, e.g.
     * RX-2026-011. Mirrors Consultation::nextReference.
     */
    public static function nextReference(string $date, bool $lock = false): string
    {
        $year = date('Y', strtotime($date));
        $prefix = "RX-{$year}-";
        $query = static::query()->where('reference', 'like', $prefix.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $max = $query->max('reference');
        $next = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
