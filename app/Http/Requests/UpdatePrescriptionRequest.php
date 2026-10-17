<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePrescriptionRequest extends StorePrescriptionRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:prescriptions.update`
     * middleware on the route; the form request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * PATCH semantics: every top-level field is optional (a partial update is
     * valid), but when a field or medication line is present it must satisfy
     * the same rules as creation. `medications` replaces the list wholesale,
     * so it still requires at least one complete line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient' => ['sometimes', 'required', 'string', 'max:255'],
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
            'medications' => ['sometimes', 'array', 'min:1'],
            'medications.*.medicine_name' => ['required', 'string', 'max:255'],
            'medications.*.dosage' => ['required', 'string', 'max:255'],
            'medications.*.frequency' => ['required', 'string', 'max:255'],
            'medications.*.duration' => ['nullable', 'string', 'max:255'],
            'medications.*.instructions' => ['nullable', 'string'],
        ];
    }
}
