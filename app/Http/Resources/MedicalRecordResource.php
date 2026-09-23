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
        return [
            'id' => $this->id,
            'patientId' => $this->patient_id,
            'name' => $this->name,
            'age' => $this->age,
            'sex' => $this->sex,
            'type' => $this->type,
            'courseDept' => $this->course_dept,
            'contact' => $this->contact ?? '',
            'emergencyContact' => $this->emergency_contact ?? '',
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
