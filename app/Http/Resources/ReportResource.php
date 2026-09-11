<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->resource instanceof \App\Models\Appointment ? $this->reference : null,
            'patient' => $this->patient,
            'staff' => $this->staff ?? 'Unassigned',
            'status' => $this->status,
            'date' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('date', array_keys($this->resource->getAttributes())),
                fn () => $this->date instanceof \Illuminate\Support\Carbon
                    ? $this->date->format('Y-m-d')
                    : $this->date
            ),
            'time' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('time', array_keys($this->resource->getAttributes())),
                fn () => $this->time
            ),
            'type' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('type', array_keys($this->resource->getAttributes())),
                fn () => $this->type
            ),
            'reason' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('reason', array_keys($this->resource->getAttributes())),
                fn () => $this->reason
            ),
            'chief_complaint' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('chief_complaint', array_keys($this->resource->getAttributes())),
                fn () => $this->chief_complaint
            ),
            'diagnosis' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('diagnosis', array_keys($this->resource->getAttributes())),
                fn () => $this->diagnosis
            ),
            'treatment' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('treatment', array_keys($this->resource->getAttributes())),
                fn () => $this->treatment
            ),
            'vitals' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('vitals', array_keys($this->resource->getAttributes())),
                fn () => $this->vitals
            ),
            'clinical_findings' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('clinical_findings', array_keys($this->resource->getAttributes())),
                fn () => $this->clinical_findings
            ),
            'disposition' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('disposition', array_keys($this->resource->getAttributes())),
                fn () => $this->disposition
            ),
            'patient_id' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('patient_id', array_keys($this->resource->getAttributes())),
                fn () => $this->patient_id
            ),
            'purpose' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('purpose', array_keys($this->resource->getAttributes())),
                fn () => $this->purpose
            ),
            'issue_date' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('issue_date', array_keys($this->resource->getAttributes())),
                fn () => $this->issue_date instanceof \Illuminate\Support\Carbon
                    ? $this->issue_date->format('Y-m-d')
                    : $this->issue_date
            ),
            'valid_until' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('valid_until', array_keys($this->resource->getAttributes())),
                fn () => $this->valid_until instanceof \Illuminate\Support\Carbon
                    ? $this->valid_until->format('Y-m-d')
                    : $this->valid_until
            ),
            'prescription_date' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('prescription_date', array_keys($this->resource->getAttributes())),
                fn () => $this->prescription_date instanceof \Illuminate\Support\Carbon
                    ? $this->prescription_date->format('Y-m-d')
                    : $this->prescription_date
            ),
            'medications' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('medications', array_keys($this->resource->getAttributes())),
                fn () => $this->medications
            ),
            'course_dept' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('course_dept', array_keys($this->resource->getAttributes())),
                fn () => $this->course_dept
            ),
            'type_patient' => $this->when(
                method_exists($this->resource, 'getAttribute') && in_array('type', array_keys($this->resource->getAttributes())) && $this->resource instanceof \App\Models\Patient,
                fn () => $this->type
            ),
        ];
    }
}
