<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role?->name !== 'doctor';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient' => ['required', 'string', 'max:255'],
            'patient_id' => ['required', 'string', 'max:50', Rule::exists('patients', 'patient_id')],
            'type' => ['required', Rule::in(Appointment::TYPES)],
            'visit_type' => ['nullable', Rule::in(Appointment::VISIT_TYPES)],
            'previous_consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'date' => ['required', 'date'],
            'time' => ['required', Rule::in(Appointment::TIME_SLOTS)],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'staff' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Select a registered patient from the search results. Add new patients through the Patients Module first.',
            'patient_id.exists' => 'This patient is not registered. Add the patient through the Patients Module before booking an appointment.',
        ];
    }
}
