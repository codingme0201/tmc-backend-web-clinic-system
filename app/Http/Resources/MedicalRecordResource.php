<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicalRecordResource extends JsonResource
{
    /**
     * Transform the medical record into the shape the existing frontend
     * consumes: camelCase demographic keys plus the clinical child arrays
     * (medicalHistory, conditions, allergies, medications) with their own
     * ids, sorted so the UI lists newest entries first where it matters.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Demographics come from the patient profile (what the student
        // entered in the mobile app); the record's own copy is a fallback.
        $patient = $this->patient;
        $age = $patient?->age ?: ($this->age ?: null);

        return [
            'id' => $this->id,
            'patientId' => $this->patient_id,
            'name' => $patient?->name ?: $this->name,
            'age' => $age,
            'sex' => $this->sex,
            'type' => $patient?->type ?: $this->type,
            'courseDept' => $patient?->course_dept ?: $this->course_dept,
            'contact' => $patient?->contact ?: ($this->contact ?? ''),
            'emergencyContact' => $patient?->emergency_contact ?: ($this->emergency_contact ?? ''),
            // Everything the student entered through the mobile app.
            'studentProfile' => $patient ? [
                'studentId' => $patient->patient_id,
                'firstName' => $patient->first_name ?? '',
                'middleName' => $patient->middle_name ?? '',
                'lastName' => $patient->last_name ?? '',
                'age' => $patient->age,
                'courseDept' => $patient->course_dept ?? '',
                'block' => $patient->block ?? '',
                'address' => $patient->address ?? '',
                'nationality' => $patient->nationality ?? '',
                'contact' => $patient->contact ?? '',
                'email' => $patient->user?->email ?? '',
                'emergencyContactName' => $patient->emergency_contact_name ?? '',
                'emergencyContactPhone' => $patient->emergency_contact_phone ?? '',
                'allergies' => $patient->allergies ?? '',
                'history' => $patient->history ?? '',
                'isProfileComplete' => $patient->isProfileComplete(),
                'updatedAt' => $patient->updated_at?->toIso8601String(),
            ] : null,
            'status' => $this->status,
            'lastUpdated' => $this->last_updated?->format('Y-m-d') ?? ($this->updated_at?->format('Y-m-d') ?? now()->toDateString()),
            'medicalHistory' => $this->histories->map(fn ($h) => [
                'id' => (string) $h->id,
                'date' => $h->date?->format('Y-m-d') ?? '',
                'condition' => $h->condition,
                'notes' => $h->notes ?? '',
            ])->all(),
            'conditions' => $this->conditions->map(fn ($c) => [
                'id' => (string) $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'diagnosedDate' => $c->diagnosed_date?->format('Y-m-d') ?? '',
                'notes' => $c->notes ?? '',
            ])->all(),
            'allergies' => $this->allergies->map(fn ($a) => [
                'id' => (string) $a->id,
                'allergen' => $a->allergen,
                'reaction' => $a->reaction ?? '',
                'severity' => $a->severity,
                'dateRecorded' => $a->date_recorded?->format('Y-m-d') ?? '',
                'notes' => $a->notes ?? '',
            ])->all(),
            'medications' => $this->medications->map(fn ($m) => [
                'id' => (string) $m->id,
                'name' => $m->name,
                'dosage' => $m->dosage ?? '',
                'frequency' => $m->frequency ?? '',
                'route' => $m->route ?? '',
                'prescribedBy' => $m->prescribed_by ?? '',
                'prescribedDate' => $m->prescribed_date?->format('Y-m-d') ?? '',
                'startDate' => $m->start_date?->format('Y-m-d') ?? '',
                'endDate' => $m->end_date?->format('Y-m-d') ?? '',
                'status' => $m->status,
                'instructions' => $m->instructions ?? '',
            ])->all(),
        ];
    }
}
