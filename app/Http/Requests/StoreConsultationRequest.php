<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:consultations.create`
     * middleware on the route; the form request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Accepts both the full consultation shape used by the Consultations
     * module and the legacy shape the Dashboard "Log Consultation" form
     * sends (symptoms / vitals.bp / vitals.temp / vitals.pulse).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient' => ['required', 'string', 'max:255'],
            'patient_id' => ['nullable', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'status' => ['nullable', 'string', Rule::in(['Scheduled', 'In Progress', 'Completed'])],
            'date' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:50'],
            'staff' => ['nullable', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string'],
            'vitals' => ['nullable', 'array'],
            'chiefComplaint' => ['nullable', 'string'],
            'clinicalFindings' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'disposition' => ['nullable', 'string'],
            'startedAt' => ['nullable', 'string', 'max:255'],
            'completedAt' => ['nullable', 'string', 'max:255'],
        ];
    }
}
