<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\ClinicInsight;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordAllergy;
use App\Models\MedicalRecordCondition;
use App\Models\MedicalRecordHistory;
use App\Models\MedicalRecordMedication;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionMedication;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Live data sync between the web clinic system and the mobile app.
 *
 * Every create/update/delete of a model stamps a new opaque version on its
 * module. Clients poll `GET /api/sync` (one tiny request) and re-fetch only
 * the modules whose version changed, so changes made on one side appear on
 * the other within seconds without reloading.
 */
class SyncState
{
    /**
     * Model class => sync module it belongs to.
     */
    public const MODULES = [
        Appointment::class => 'appointments',
        Consultation::class => 'consultations',
        MedicalRecord::class => 'medical_records',
        MedicalRecordAllergy::class => 'medical_records',
        MedicalRecordCondition::class => 'medical_records',
        MedicalRecordHistory::class => 'medical_records',
        MedicalRecordMedication::class => 'medical_records',
        MedicalCertificate::class => 'medical_certificates',
        Prescription::class => 'prescriptions',
        PrescriptionMedication::class => 'prescriptions',
        Patient::class => 'patients',
        User::class => 'users',
        Notification::class => 'notifications',
        StaffSchedule::class => 'staff_schedules',
        \App\Models\WeeklyStaffSchedule::class => 'staff_schedules',
        StaffProfile::class => 'staff_profiles',
        SystemSetting::class => 'settings',
        ActivityLog::class => 'activity_logs',
        Role::class => 'roles',
        Staff::class => 'staff',
        ClinicInsight::class => 'clinic_insights',
    ];

    public static function bumpModel(object $model): void
    {
        if ($module = self::MODULES[$model::class] ?? null) {
            self::bump($module);
        }
    }

    public static function bump(string ...$modules): void
    {
        foreach ($modules as $module) {
            Cache::forever(self::key($module), (string) Str::ulid());
        }
    }

    /**
     * Current version of every module (null until it first changes).
     *
     * @return array<string, string|null>
     */
    public static function versions(): array
    {
        $modules = array_values(array_unique(self::MODULES));
        $values = Cache::many(array_map(self::key(...), $modules));

        return array_combine($modules, array_values($values));
    }

    private static function key(string $module): string
    {
        return "sync:{$module}";
    }
}
