<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlockScheduleRequest extends FormRequest
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
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'string', 'max:10'],
            'end_time' => ['nullable', 'string', 'max:10'],
            'all_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
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
