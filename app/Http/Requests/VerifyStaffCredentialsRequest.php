<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyStaffCredentialsRequest extends FormRequest
{
    /**
     * Authorization is handled by the route-level `permission:users.update`
     * middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['Verified', 'Rejected'])],
            'notes' => ['nullable', 'required_if:status,Rejected', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required_if' => 'Please state why the credentials were rejected.',
        ];
    }
}
