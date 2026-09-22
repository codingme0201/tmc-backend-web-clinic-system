<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string', 'max:50'],
            'patient' => ['nullable', 'string', 'max:255'],
            'staff' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
