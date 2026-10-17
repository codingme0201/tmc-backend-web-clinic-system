<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use App\Models\MedicalRecord;
use App\Models\Prescription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePrescriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:prescriptions.create`
     * middleware on the route; the form request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The medication lines carry the documented 6.2 fields (medicine name,
     * dosage, frequency, duration, instructions); name/dosage/frequency are
     * required on every line and validated here independently of the
     * frontend. Relational integrity for the optional consultation and
     * medical record links is checked in `withValidator`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient' => ['required', 'string', 'max:255'],
            'patient_id' => ['nullable', 'string', 'max:255'],
            'consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
            'medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id'],
            'prescribed_by' => ['nullable', 'string', 'max:255'],
            'prescribed_by_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                $prescriber = \App\Models\User::with(['role', 'staffProfile'])->find($value);
                if ($prescriber?->isCredentialRejected()) {
                    $fail("{$prescriber->name}'s medical staff application was rejected, so they cannot be selected as the prescriber.");
                } elseif (! $prescriber || $prescriber->role?->name !== 'doctor' || $prescriber->status !== 'active') {
                    $fail('Select an active doctor as the prescriber.');
                }
            }],
            'date' => ['nullable', 'date'],
            'medications' => ['required', 'array', 'min:1'],
            'medications.*.medicine_name' => ['required', 'string', 'max:255'],
            'medications.*.dosage' => ['required', 'string', 'max:255'],
            'medications.*.frequency' => ['required', 'string', 'max:255'],
            'medications.*.duration' => ['nullable', 'string', 'max:255'],
            'medications.*.instructions' => ['nullable', 'string'],
        ];
    }

    /**
     * Verify that a linked consultation / medical record actually belongs to
     * the selected patient — a valid id alone is not enough.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            // On update the patient fields may be omitted; fall back to the
            // record being edited so existing links keep validating.
            $prescription = $this->route('prescription');
            $patient = $data['patient'] ?? ($prescription instanceof Prescription ? $prescription->patient : null);
            $patientId = $data['patient_id'] ?? ($prescription instanceof Prescription ? $prescription->patient_id : null);

            if (! empty($data['consultation_id']) && ! $this->belongsToPatient(Consultation::class, $data['consultation_id'], $patient, $patientId)) {
                $validator->errors()->add('consultation_id', 'The selected consultation does not belong to the selected patient.');
            }

            if (! empty($data['medical_record_id']) && ! $this->belongsToPatient(MedicalRecord::class, $data['medical_record_id'], $patient, $patientId)) {
                $validator->errors()->add('medical_record_id', 'The selected medical record does not belong to the selected patient.');
            }
        });
    }

    /**
     * Whether the given linked record belongs to the selected patient.
     *
     * Patient/staff are display values in this architecture, so a record
     * matches when its registry id equals the selected patient id, or its
     * display name equals the selected patient name.
     */
    private function belongsToPatient(string $model, int $id, ?string $patient, ?string $patientId): bool
    {
        $record = $model::find($id);
        if (! $record) {
            return false; // the exists rule reports the invalid id
        }

        if ($patientId !== null && $record->patient_id !== null && $record->patient_id === $patientId) {
            return true;
        }

        $displayName = $record->patient ?? $record->name ?? null;

        return $patient !== null && $displayName === $patient;
    }
}
