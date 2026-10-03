<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConsultationRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * Mirrors the field set the Consultations workspace form sends on
     * "Save Progress". Every field is optional — a partial draft is valid —
     * but present fields are persisted as-is.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'staff' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'chiefComplaint' => ['nullable', 'string'],
            'vitals' => ['nullable', 'array'],
            'clinicalFindings' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'disposition' => ['nullable', 'string'],
            'followUpRequired' => ['nullable', 'boolean'],
            'followUpNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
