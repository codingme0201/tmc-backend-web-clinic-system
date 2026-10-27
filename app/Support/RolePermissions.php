<?php

namespace App\Support;

use App\Models\Role;
use Closure;

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
                'schedules.view', 'schedules.create', 'schedules.update',
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
                'schedules.view', 'schedules.create', 'schedules.update',
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
     * Administrative permissions only the single administrator role may
     * hold; no other predefined or custom role can be granted them.
     */
    public const ADMIN_LEVEL_PERMISSIONS = [
        'audit_logs.view',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign_permissions',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'settings.view', 'settings.update',
    ];

    /**
     * Names that would impersonate the administrator role.
     */
    private const ADMIN_LIKE_NAMES = ['root', 'superuser', 'system', 'owner', 'sysad'];

    /**
     * Validation rule for a role name: rejects administrator look-alikes and
     * names that duplicate an existing role once case, spaces, dashes and
     * underscores are ignored (e.g. "Front-Desk" vs "front_desk").
     */
    public static function nameRule(?Role $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore) {
            $normalized = self::normalize((string) $value);
            if ($ignore && self::normalize($ignore->name) === $normalized) {
                return;
            }

            if (str_contains($normalized, 'admin') || in_array($normalized, self::ADMIN_LIKE_NAMES, true)) {
                $fail('The Administrator role is predefined and unique. You cannot create or rename a role into another administrator role.');

                return;
            }

            $duplicate = Role::query()
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                ->pluck('name')
                ->first(fn (string $name) => self::normalize($name) === $normalized);

            if ($duplicate !== null) {
                $fail("A role named \"{$duplicate}\" already exists. Use the existing predefined role instead of creating a duplicate.");
            }
        };
    }

    private static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

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
