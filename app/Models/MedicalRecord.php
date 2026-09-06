<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'patient_id', 'name', 'age', 'sex', 'type', 'course_dept', 'contact',
    'emergency_contact', 'status', 'last_updated',
])]
class MedicalRecord extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_updated' => 'date:Y-m-d',
        ];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MedicalRecordHistory::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(MedicalRecordCondition::class);
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(MedicalRecordAllergy::class);
    }

    public function medications(): HasMany
    {
        return $this->hasMany(MedicalRecordMedication::class);
    }

    public function medicalCertificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }
}
