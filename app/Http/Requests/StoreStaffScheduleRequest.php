<?php

namespace App\Http\Requests;

use App\Models\StaffSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by the route-level `permission:schedules.create`
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
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'string', 'max:10'],
            'end_time' => ['required', 'string', 'max:10'],
            'status' => ['sometimes', 'string', Rule::in(StaffSchedule::STATUSES)],
            'notes' => ['nullable', 'string', 'max:500'],
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
            $start = $this->start_time;
            $end = $this->end_time;

            if ($start && $end) {
                $startMinutes = $this->timeToMinutes($start);
                $endMinutes = $this->timeToMinutes($end);

                if ($endMinutes <= $startMinutes) {
                    $validator->errors()->add('end_time', 'End time must be after start time.');
                }
            }
        });
    }

    /**
     * Convert a time string (e.g. "08:00 AM") to minutes for comparison.
     */
    private function timeToMinutes(string $time): int
    {
        $parsed = date_parse($time);
        if (!$parsed || $parsed['error_count'] > 0) {
            return 0;
        }

        $hours = $parsed['hour'];
        $minutes = $parsed['minute'];

        // Handle AM/PM
        $lower = strtolower($time);
        if (str_contains($lower, 'pm') && $hours !== 12) {
            $hours += 12;
        } elseif (str_contains($lower, 'am') && $hours === 12) {
            $hours = 0;
        }

        return $hours * 60 + $minutes;
    }
}
