<?php

namespace App\Http\Requests;

use App\Models\ClinicEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicEventRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'string', 'max:10'],
            'end_time' => ['nullable', 'string', 'max:10'],
            'all_day' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', Rule::in(ClinicEvent::TYPES)],
            'status' => ['sometimes', 'string', Rule::in(ClinicEvent::STATUSES)],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * If not an all-day event, start_time and end_time are required.
     * End time must be after start time.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $allDay = $this->boolean('all_day');
            $startTime = $this->start_time;
            $endTime = $this->end_time;

            if (!$allDay) {
                if (empty($startTime)) {
                    $validator->errors()->add('start_time', 'Start time is required for timed events.');
                }
                if (empty($endTime)) {
                    $validator->errors()->add('end_time', 'End time is required for timed events.');
                }
            }

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
        $parsed = date_parse($time);
        if (!$parsed || $parsed['error_count'] > 0) {
            return 0;
        }
        $hours = $parsed['hour'];
        $minutes = $parsed['minute'];
        $lower = strtolower($time);
        if (str_contains($lower, 'pm') && $hours !== 12) {
            $hours += 12;
        } elseif (str_contains($lower, 'am') && $hours === 12) {
            $hours = 0;
        }
        return $hours * 60 + $minutes;
    }
}
