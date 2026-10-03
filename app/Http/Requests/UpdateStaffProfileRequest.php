<?php

namespace App\Http\Requests;

use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffProfileRequest extends FormRequest
{
    /**
     * Doctors/nurses update their own credentials through /me/staff-profile;
     * administrators through the permission-guarded clinic-staff route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The staff member whose profile is being edited.
     */
    public function targetUser(): User
    {
        return $this->route('user') ?? $this->user();
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('licenseNumber')) {
            $this->merge(['licenseNumber' => preg_replace('/\s+/', '', (string) $this->input('licenseNumber'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $profileId = $this->targetUser()->staffProfile?->id;

        return [
            'position' => ['nullable', 'string', 'max:150'],
            'specialization' => ['nullable', 'string', 'max:150'],
            'contactNumber' => ['nullable', 'string', 'max:50'],
            'licenseType' => ['nullable', 'required_with:licenseNumber', 'string', Rule::in(StaffProfile::LICENSE_TYPES)],
            'licenseNumber' => [
                'nullable', 'required_with:licenseType', 'string', 'max:50',
                Rule::unique('staff_profiles', 'license_number')
                    ->where(fn ($q) => $q->where('license_type', $this->input('licenseType')))
                    ->ignore($profileId),
            ],
            'licenseIssuedAt' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'licenseExpiresAt' => ['nullable', 'date_format:Y-m-d', 'after:licenseIssuedAt'],
            'otherCredentials' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'licenseNumber.unique' => 'This license number is already registered to another staff member.',
            'licenseType.required_with' => 'Select the license type for this license number.',
            'licenseNumber.required_with' => 'Enter the license number for the selected license type.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = $this->input('licenseType');
                $number = $this->input('licenseNumber');
                $role = $this->targetUser()->role?->name;

                if ($number && StaffProfile::isPrcLicense($type) && ! preg_match(StaffProfile::PRC_LICENSE_PATTERN, $number)) {
                    $validator->errors()->add('licenseNumber', 'PRC license numbers must be exactly 7 digits (e.g. 0123456).');
                }

                $allowed = match ($role) {
                    'doctor' => ['PRC Physician License', 'PRC Dentist License', 'Other Professional License'],
                    'nurse' => ['PRC Nurse License', 'PRC Midwife License', 'PRC Medical Technologist License', 'Other Professional License'],
                    default => StaffProfile::LICENSE_TYPES,
                };
                if ($type && ! in_array($type, $allowed, true)) {
                    $validator->errors()->add('licenseType', "A {$role} cannot be registered with a {$type}.");
                }
            },
        ];
    }
}
