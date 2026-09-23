<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'patient_id', 'name', 'first_name', 'middle_name', 'last_name',
    'age', 'type', 'course_dept', 'block', 'address', 'nationality',
    'contact', 'emergency_contact', 'emergency_contact_name', 'emergency_contact_phone',
    'allergies', 'history', 'status',
])]
class Patient extends Model
{
    /** @use HasFactory<\Database\Factories\PatientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'age' => 'integer',
        ];
    }

    public function isProfileComplete(): bool
    {
        return !empty($this->first_name)
            && !empty($this->last_name)
            && !empty($this->course_dept)
            && !empty($this->address)
            && !empty($this->contact)
            && !empty($this->emergency_contact_name)
            && !empty($this->emergency_contact_phone);
    }
    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'patient_id', 'patient_id');
    }
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id', 'patient_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'patient_id', 'patient_id');
    }

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'patient_id', 'patient_id');
    }

    public function medicalCertificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class, 'patient_id', 'patient_id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'patient_id', 'patient_id');
    }
}
