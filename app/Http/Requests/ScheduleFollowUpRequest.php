<?php

namespace App\Http\Requests;

use App\Support\ClinicSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleFollowUpRequest extends FormRequest
{
    /**
     * Authorization is handled by the route-level `permission:consultations.update`
     * middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('time')) {
            $this->merge(['time' => ClinicSchedule::normalize($this->input('time'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'time' => ['required', 'string', Rule::in(ClinicSchedule::TIME_SLOTS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
