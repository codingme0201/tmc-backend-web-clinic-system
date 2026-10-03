<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'position', 'specialization', 'contact_number',
    'license_type', 'license_number', 'license_issued_at', 'license_expires_at',
    'other_credentials', 'credential_status', 'verified_by', 'verified_at', 'verification_notes',
])]
class StaffProfile extends Model
{
    public const LICENSE_TYPES = [
        'PRC Physician License',
        'PRC Dentist License',
        'PRC Nurse License',
        'PRC Midwife License',
        'PRC Medical Technologist License',
        'Other Professional License',
    ];

    public const CREDENTIAL_STATUSES = ['Not Submitted', 'Pending Verification', 'Verified', 'Rejected'];

    /**
     * PRC license numbers are 7 digits (leading zeros allowed).
     */
    public const PRC_LICENSE_PATTERN = '/^\d{7}$/';

    public const PRC_VERIFICATION_URL = 'https://online.prc.gov.ph/Verification';

    protected function casts(): array
    {
        return [
            'license_issued_at' => 'date:Y-m-d',
            'license_expires_at' => 'date:Y-m-d',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isLicenseExpired(): bool
    {
        return $this->license_expires_at !== null && $this->license_expires_at->isPast();
    }

    public static function isPrcLicense(?string $type): bool
    {
        return $type !== null && str_starts_with($type, 'PRC ');
    }
}
