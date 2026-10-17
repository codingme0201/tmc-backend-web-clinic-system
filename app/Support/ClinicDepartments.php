<?php

namespace App\Support;

use App\Models\StaffProfile;

/**
 * Clinical departments used to match a visit with the right doctor.
 *
 * A visit's department comes from its service type (appointment or
 * consultation type): "Dental concern" is handled by the Dental department,
 * every other service by General Medicine. A doctor/nurse belongs to the
 * Dental department when they hold a PRC Dentist License or their
 * specialization is dental; everyone else serves General Medicine.
 */
class ClinicDepartments
{
    public const MEDICAL = 'General Medicine';

    public const DENTAL = 'Dental';

    public const ALL = [self::MEDICAL, self::DENTAL];

    /**
     * Service types handled by the Dental department.
     */
    public const DENTAL_TYPES = ['Dental concern'];

    private const DENTAL_SPECIALIZATION = '/dent|orthodont|endodont|periodont|prosthodont|oral/i';

    public static function forType(?string $type): string
    {
        return in_array($type, self::DENTAL_TYPES, true) ? self::DENTAL : self::MEDICAL;
    }

    public static function forStaff(?StaffProfile $profile): string
    {
        if ($profile && ($profile->license_type === 'PRC Dentist License'
            || preg_match(self::DENTAL_SPECIALIZATION, (string) $profile->specialization))) {
            return self::DENTAL;
        }

        return self::MEDICAL;
    }
}
