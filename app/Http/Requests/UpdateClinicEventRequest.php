<?php

namespace App\Http\Requests;

use App\Models\ClinicEvent;
use App\Support\ClinicSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'string', 'max:10'],
            'end_time' => ['nullable', 'string', 'max:10'],
            'all_day' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', Rule::in(ClinicEvent::TYPES)],
            'status' => ['sometimes', 'string', Rule::in(ClinicEvent::STATUSES)],
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

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $startTime = $this->start_time;
            $endTime = $this->end_time;

            if ($startTime && $endTime) {
                $startMinutes = $this->timeToMinutes($startTime);
                $endMinutes = $this->timeToMinutes($endTime);
                if ($endMinutes <= $startMinutes) {
                    $validator->errors()->add('end_time', 'End time must be after start time.');
                }
            }
        });
    }

    private function timeToMinutes(string $time): int
    {
        return ClinicSchedule::toMinutes($time) ?? 0;
    }
}
