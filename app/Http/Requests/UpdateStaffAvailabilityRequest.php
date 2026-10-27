<?php

namespace App\Http\Requests;

use App\Models\StaffSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffAvailabilityRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(StaffSchedule::STATUSES)],
        ];
    }
}
