<?php

namespace App\Http\Requests;

use App\Models\StaffSchedule;
use App\Support\ClinicSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by the route-level `permission:schedules.update`
     * middleware — this method always returns true following the project
     * convention.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $scheduleId = $this->route('schedule')?->id;

        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'string', Rule::in(ClinicSchedule::shiftTimes())],
            'end_time' => ['sometimes', 'string', Rule::in(ClinicSchedule::shiftTimes())],
            'status' => ['sometimes', 'string', Rule::in(StaffSchedule::STATUSES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Store times in the shared clinic format ("08:00 AM").
     */
    protected function prepareForValidation(): void
    {
        foreach (['start_time', 'end_time'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => ClinicSchedule::normalize($this->input($field))]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'start_time.in' => 'Shifts must fall within clinic working hours (8:00 AM to 5:00 PM).',
            'end_time.in' => 'Shifts must fall within clinic working hours (8:00 AM to 5:00 PM).',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * Adds cross-field validation: end_time must be after start_time.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $start = ClinicSchedule::toMinutes($this->start_time ?? $this->route('schedule')?->start_time);
            $end = ClinicSchedule::toMinutes($this->end_time ?? $this->route('schedule')?->end_time);

            if ($start !== null && $end !== null && $end <= $start) {
                $validator->errors()->add('end_time', 'End time must be after start time.');
            }
        });
    }
}
