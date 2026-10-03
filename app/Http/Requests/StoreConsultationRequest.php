<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use App\Support\ClinicSchedule;
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
    public function messages(): array
    {
        return [
            'time.in' => 'Please choose one of the clinic time slots (the same slots used for appointments).',
        ];
    }

    /**
     * Accept "2:30 PM" / "14:30" and store the canonical slot format.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('time')) {
            $this->merge(['time' => ClinicSchedule::normalize($this->input('time'))]);
        }
    }

    public function rules(): array
    {
        return [
            'patient' => ['required', 'string', 'max:255'],
            'patient_id' => ['nullable', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'status' => ['nullable', 'string', Rule::in(['Scheduled', 'In Progress', 'Completed'])],
            'date' => ['nullable', 'date'],
            'time' => ['nullable', 'string', Rule::in(ClinicSchedule::TIME_SLOTS)],
            'staff' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'visit_type' => ['nullable', 'string', Rule::in(Appointment::VISIT_TYPES)],
            'previous_consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
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
