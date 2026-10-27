<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use App\Support\AcademicPrograms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by the route-level `permission:users.create`
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
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'patient_id' => ['nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/', 'exists:patients,patient_id'],
            'student_id' => ['nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/'],
            'studentId' => ['nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'firstName' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'sex' => ['nullable', Rule::in(['Female', 'Male', 'Other', 'Others'])],
            'course' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'course_dept' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'courseDept' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'block' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'phone' => PhoneNumber::rules(),
            'contact' => PhoneNumber::rules(),
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergencyContactName' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => PhoneNumber::rules(),
            'emergencyContactPhone' => PhoneNumber::rules(),
        ];
    }
}
