<?php

namespace App\Http\Requests;

use App\Models\StaffSchedule;
use App\Support\ClinicSchedule;
use App\Support\AppointmentCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $schedule = $this->route('schedule');
        if ($schedule && ! StaffSchedule::canManageFor($this->user(), $schedule->user_id)) {
            return false;
        }

        return StaffSchedule::canManageFor($this->user(), (int) $this->input('user_id', $schedule?->user_id ?? 0));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'string', Rule::in(ClinicSchedule::shiftTimes())],
            'end_time' => ['required', 'string', Rule::in(ClinicSchedule::shiftTimes())],
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
            $date = $this->input('date', $this->route('schedule')?->date?->format('Y-m-d'));
            if ($date && ! $validator->errors()->has('date') && ($reason = app(AppointmentCalendar::class)->reason($date))) {
                $validator->errors()->add('date', $reason);
            }

            $start = ClinicSchedule::toMinutes($this->start_time ?? $this->route('schedule')?->start_time);
            $end = ClinicSchedule::toMinutes($this->end_time ?? $this->route('schedule')?->end_time);

            if ($start !== null && $end !== null && $end <= $start) {
                $validator->errors()->add('end_time', 'End time must be after start time.');
            }
        });
    }
}
