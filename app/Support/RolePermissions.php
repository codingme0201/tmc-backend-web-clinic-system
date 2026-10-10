<?php

namespace App\Support;

/**
 * Responsibilities of the built-in clinic roles.
 *
 * - Doctor: clinical decisions — consultations, diagnosis, prescriptions,
 *   medical certificate approval and follow-up scheduling.
 * - Nurse: clinical assistant — triage/vitals, consultation support,
 *   medical records and certificate preparation (no prescribing).
 * - Front Desk: patient registration, appointment requests, doctor
 *   assignment, check-in and the waiting queue (no clinical records).
 * - Admin: every permission (granted separately). Audit logs are
 *   admin-only, and there is exactly one admin account.
 */
class RolePermissions
{
    public const ROLES = [
        'doctor' => [
            'description' => 'Doctor — consultations, diagnosis, prescriptions and follow-ups',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.update',
                'consultations.view', 'consultations.create', 'consultations.update', 'consultations.delete',
                'medical_records.view', 'medical_records.create', 'medical_records.update',
                'medical_certificates.view', 'medical_certificates.create', 'medical_certificates.update',
                'medical_certificates.approve',
                'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
                'patients.view', 'patients.create',
                'schedules.view',
                'reports.view', 'reports.export',
                'notifications.view', 'notifications.send',
            ],
        ],
        'nurse' => [
            'description' => 'Nurse — clinical assistant: triage, vital signs and consultation support',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'consultations.view', 'consultations.create', 'consultations.update',
                'medical_records.view', 'medical_records.create', 'medical_records.update',
                'medical_certificates.view', 'medical_certificates.create',
                'prescriptions.view',
                'patients.view', 'patients.create',
                'schedules.view',
                'reports.view', 'reports.export',
                'notifications.view',
            ],
        ],
        'front_desk' => [
            'description' => 'Front Desk — patient registration, appointments, doctor assignment and queue',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'appointments.approve', 'appointments.reject', 'appointments.reschedule',
                'patients.view', 'patients.create', 'patients.update',
                'schedules.view',
                'notifications.view',
            ],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function for(string $role): array
    {
        return self::ROLES[$role]['permissions'] ?? [];
    }

    public static function description(string $role): ?string
    {
        return self::ROLES[$role]['description'] ?? null;
    }
}
