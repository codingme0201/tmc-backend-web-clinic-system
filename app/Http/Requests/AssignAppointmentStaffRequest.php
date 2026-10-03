<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignAppointmentStaffRequest extends FormRequest
{
    /**
     * Authorization is handled by the route-level `permission:appointments.update`
     * middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A null staff_id unassigns the appointment.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'staff_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
