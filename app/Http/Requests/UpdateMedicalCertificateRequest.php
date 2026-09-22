<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMedicalCertificateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:medical_certificates.update`
     * middleware on the route; the form request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation by normalizing camelCase keys.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('patientId')) $merge['patient_id'] = $this->patientId;
        if ($this->has('consultationId')) $merge['consultation_id'] = $this->consultationId;
        if ($this->has('medicalRecordId')) $merge['medical_record_id'] = $this->medicalRecordId;
        if ($this->has('issuedBy')) $merge['issued_by'] = $this->issuedBy;
        if ($this->has('issueDate')) $merge['issue_date'] = $this->issueDate;
        if ($this->has('validUntil')) $merge['valid_until'] = $this->validUntil;

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Partial updates are allowed — only the provided fields are patched.
     * `status` is deliberately restricted to `Void`: all other lifecycle
     * moves must go through the dedicated workflow endpoints (approve,
     * reject, issue) so the state machine and audit trail cannot be bypassed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient' => ['sometimes', 'string', 'max:255'],
            'patient_id' => ['nullable', 'string', 'max:255'],
            'consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
            'medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id'],
            'issued_by' => ['nullable', 'string', 'max:255'],
            'purpose' => ['sometimes', 'string', 'max:255'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'recommendation' => ['nullable', 'string'],
            // `required_with` keeps partial updates sane: if `valid_until` is
            // sent without `issue_date`, Laravel's after_or_equal would
            // silently compare against today instead of the stored date.
            'issue_date' => ['sometimes', 'date', 'required_with:valid_until'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'status' => ['sometimes', 'string', 'in:Void'],
        ];
    }
}
