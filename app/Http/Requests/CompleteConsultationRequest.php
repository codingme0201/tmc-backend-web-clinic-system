<?php

namespace App\Http\Requests;

use App\Support\ClinicSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteConsultationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:consultations.update`
     * middleware on the route; the form request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the core clinical fields before validation so whitespace-only
     * input ("   ") is treated as missing and fails the `required` rules.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('followUpTime')) {
            $this->merge(['followUpTime' => ClinicSchedule::normalize($this->input('followUpTime'))]);
        }

        foreach (['chiefComplaint', 'diagnosis', 'treatment'] as $field) {
            if (array_key_exists($field, $this->all()) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A consultation is locked once completed, so the business rule that a
     * completed record must carry the core clinical narrative is enforced
     * here (centralized server-side, matching the frontend's required-field
     * checks): chief complaint, assessment/diagnosis, and treatment/advice
     * are mandatory on completion.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'staff' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'chiefComplaint' => ['required', 'string'],
            'vitals' => ['nullable', 'array'],
            'clinicalFindings' => ['nullable', 'string'],
            'diagnosis' => ['required', 'string'],
            'treatment' => ['required', 'string'],
            'disposition' => ['nullable', 'string'],
            'followUpRequired' => ['nullable', 'boolean'],
            'followUpDate' => ['nullable', 'date_format:Y-m-d', 'after:today', 'required_with:followUpTime'],
            'followUpTime' => ['nullable', 'string', Rule::in(ClinicSchedule::TIME_SLOTS), 'required_with:followUpDate'],
            'followUpNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
