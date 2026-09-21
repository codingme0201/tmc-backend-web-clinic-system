<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentStatusRequest extends FormRequest
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
     * 'Pending' and 'Rescheduled' are not valid targets here: 'Pending' is the
     * entry state and 'Rescheduled' is only produced by the reschedule action,
     * which validates the new date/time as well.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['Under Review', 'Approved', 'Confirmed', 'Rejected', 'Cancelled', 'Completed', 'No-Show'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
