<?php

namespace App\Http\Requests;

use App\Support\RolePermissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->ignore($this->route('role')),
                Rule::notIn(['staff', 'instructor', 'faculty', 'patient']),
                RolePermissions::nameRule($this->route('role')),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists. Use the existing predefined role instead of creating a duplicate.',
            'name.not_in' => 'This role name is not allowed. Students use the Student role, and clinic staff use the Doctor, Nurse or Front Desk roles.',
        ];
    }
}
