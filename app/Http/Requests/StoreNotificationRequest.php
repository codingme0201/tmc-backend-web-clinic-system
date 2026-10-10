<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('status', 'active')],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'type' => ['sometimes', 'string', 'in:system,appointment,patient,clinic'],
            'category' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Select the user who should receive this notification.',
            'user_id.exists' => 'The selected recipient is not an active user.',
        ];
    }
}
