<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
    /**
     * Generate a placeholder student ID for accounts registered without one.
     */
    public static function generatePlaceholderId(): string
    {
        do {
            $id = 'STU-'.date('y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::where('patient_id', $id)->exists());

        return $id;
    }

    /**
     * Whether the ID is an auto-generated placeholder (see generatePlaceholderId).
     */
    public static function isPlaceholderId(?string $id): bool
    {
        return (bool) preg_match('/^STU-\d{2}-\d{6}$/', (string) $id);
    }

    /**
     * Whether any clinical record (appointment, consultation, certificate,
     * prescription) references the given patient ID. patient_id is a plain
     * string column, so these rows would be orphaned or adopted if the ID
     * changed.
     */
    public static function idHasClinicalRecords(string $patientId): bool
    {
        return Appointment::where('patient_id', $patientId)->exists()
            || Consultation::where('patient_id', $patientId)->exists()
            || MedicalCertificate::where('patient_id', $patientId)->exists()
            || Prescription::where('patient_id', $patientId)->exists();
    }

    /**
     * Change the patient ID and move every record that references it
     * (clinical tables, the medical record and linked user accounts), since
     * patient_id is a plain string key with no foreign-key cascade.
     */
    public function renamePatientId(string $newId): void
    {
        $oldId = $this->patient_id;
        if ($oldId === $newId) {
            return;
        }

        DB::transaction(function () use ($oldId, $newId) {
            foreach ([Appointment::class, Consultation::class, MedicalCertificate::class, Prescription::class, MedicalRecord::class, User::class] as $model) {
                $model::where('patient_id', $oldId)->update(['patient_id' => $newId]);
            }
            $this->update(['patient_id' => $newId]);
        });
    }

    /**
     * Return the patient's medical record, creating an empty one if missing,
     * so staff can always add conditions, allergies, medications and history.
     */
    public function ensureMedicalRecord(): MedicalRecord
    {
        return MedicalRecord::firstOrCreate(
            ['patient_id' => $this->patient_id],
            [
                'name' => $this->name,
                'age' => $this->age ?? 0,
                'sex' => 'Unspecified',
                'type' => $this->type ?: 'Student',
                'course_dept' => $this->course_dept ?? '',
                'contact' => $this->contact,
                'emergency_contact' => $this->emergency_contact,
                'status' => 'Active',
                'last_updated' => now()->toDateString(),
            ],
        );
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
