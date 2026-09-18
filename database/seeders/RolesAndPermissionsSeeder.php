<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * The full TMC CareLink permission catalog, grouped by module.
     * Future modules consume these via `$user->hasPermission('module.action')`
     * or the `permission:` middleware.
     *
     * @return array<string, array<int, array{name: string, label: string}>>
     */
    private function catalog(): array
    {
        return [
            'Dashboard' => [
                ['name' => 'dashboard.view', 'label' => 'View Dashboard'],
            ],
            'Appointments' => [
                ['name' => 'appointments.view', 'label' => 'View Appointments'],
                ['name' => 'appointments.create', 'label' => 'Create Appointment'],
                ['name' => 'appointments.update', 'label' => 'Update Appointment'],
                ['name' => 'appointments.approve', 'label' => 'Approve Appointment'],
                ['name' => 'appointments.reject', 'label' => 'Reject Appointment'],
                ['name' => 'appointments.reschedule', 'label' => 'Reschedule Appointment'],
                ['name' => 'appointments.delete', 'label' => 'Delete Appointment'],
            ],
            'Consultations' => [
                ['name' => 'consultations.view', 'label' => 'View Consultations'],
                ['name' => 'consultations.create', 'label' => 'Start Consultation'],
                ['name' => 'consultations.update', 'label' => 'Update Consultation'],
                ['name' => 'consultations.delete', 'label' => 'Delete Consultation'],
            ],
            'Medical Records' => [
                ['name' => 'medical_records.view', 'label' => 'View Medical Records'],
                ['name' => 'medical_records.create', 'label' => 'Create Medical Record'],
                ['name' => 'medical_records.update', 'label' => 'Update Medical Record'],
                ['name' => 'medical_records.delete', 'label' => 'Delete Medical Record'],
            ],
            'Medical Certificates' => [
                ['name' => 'medical_certificates.view', 'label' => 'View Medical Certificates'],
                ['name' => 'medical_certificates.create', 'label' => 'Create Medical Certificate'],
                ['name' => 'medical_certificates.update', 'label' => 'Update Medical Certificate'],
                ['name' => 'medical_certificates.approve', 'label' => 'Approve Medical Certificate'],
                ['name' => 'medical_certificates.delete', 'label' => 'Delete Medical Certificate'],
            ],
            'Prescriptions' => [
                ['name' => 'prescriptions.view', 'label' => 'View Prescriptions'],
                ['name' => 'prescriptions.create', 'label' => 'Create Prescription'],
                ['name' => 'prescriptions.update', 'label' => 'Update Prescription'],
                ['name' => 'prescriptions.delete', 'label' => 'Delete Prescription'],
            ],
            'Patients' => [
                ['name' => 'patients.view', 'label' => 'View Patients'],
                ['name' => 'patients.create', 'label' => 'Create Patient'],
                ['name' => 'patients.update', 'label' => 'Update Patient'],
                ['name' => 'patients.delete', 'label' => 'Delete Patient'],
            ],
            'Schedules' => [
                ['name' => 'schedules.view', 'label' => 'View Doctor/Nurse Schedule'],
                ['name' => 'schedules.create', 'label' => 'Create Schedule'],
                ['name' => 'schedules.update', 'label' => 'Update Schedule'],
                ['name' => 'schedules.delete', 'label' => 'Delete Schedule'],
            ],
            'Clinic Calendar' => [
                ['name' => 'calendar.view', 'label' => 'View Clinic Calendar'],
                ['name' => 'calendar.create', 'label' => 'Create Clinic Event'],
                ['name' => 'calendar.update', 'label' => 'Update Clinic Event'],
                ['name' => 'calendar.delete', 'label' => 'Delete Clinic Event'],
                ['name' => 'calendar.block', 'label' => 'Block Unavailable Schedule'],
            ],
            'Reports' => [
                ['name' => 'reports.view', 'label' => 'View Reports'],
                ['name' => 'reports.export', 'label' => 'Export Reports'],
            ],
            'Notifications' => [
                ['name' => 'notifications.view', 'label' => 'View Notifications'],
                ['name' => 'notifications.send', 'label' => 'Send Notification'],
                ['name' => 'notifications.manage', 'label' => 'Manage Notifications'],
            ],
            'User Management' => [
                ['name' => 'users.view', 'label' => 'View Users'],
                ['name' => 'users.create', 'label' => 'Create User'],
                ['name' => 'users.update', 'label' => 'Update User'],
                ['name' => 'users.delete', 'label' => 'Delete User'],
            ],
            'System Settings' => [
                ['name' => 'settings.view', 'label' => 'View Settings'],
                ['name' => 'settings.update', 'label' => 'Update Settings'],
            ],
            'Audit Logs' => [
                ['name' => 'audit_logs.view', 'label' => 'View Audit Logs'],
            ],
            'Roles & Permissions' => [
                ['name' => 'roles.view', 'label' => 'View Roles & Permissions'],
                ['name' => 'roles.create', 'label' => 'Create Role'],
                ['name' => 'roles.update', 'label' => 'Update Role'],
                ['name' => 'roles.delete', 'label' => 'Delete Role'],
                ['name' => 'roles.assign_permissions', 'label' => 'Assign Permissions'],
            ],
        ];
    }

    /**
     * Seed the permission catalog and the base roles' permission sets.
     */
    public function run(): void
    {
        // Create/refresh the permission catalog.
        $allNames = [];
        foreach ($this->catalog() as $module => $permissions) {
            foreach ($permissions as $permission) {
                Permission::updateOrCreate(
                    ['name' => $permission['name']],
                    ['module' => $module, 'label' => $permission['label']],
                );
                $allNames[] = $permission['name'];
            }
        }

        // Administrator: every permission.
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator — full system access', 'is_system' => true],
        );
        $admin->permissions()->sync(Permission::whereIn('name', $allNames)->pluck('id'));

        // Doctor: clinical care + records.
        $doctor = Role::firstOrCreate(
            ['name' => 'doctor'],
            ['description' => 'Doctor — clinical care and records', 'is_system' => true],
        );
        $doctor->update(['is_system' => true]);
        $doctor->permissions()->sync(Permission::whereIn('name', [
            'dashboard.view',
            'appointments.view',
            'consultations.view', 'consultations.create', 'consultations.update',
            'medical_records.view', 'medical_records.create', 'medical_records.update',
            'medical_certificates.view', 'medical_certificates.create', 'medical_certificates.update',
            'medical_certificates.approve',
            'prescriptions.view', 'prescriptions.create',
            'patients.view', 'patients.create',
            'schedules.view',
            'calendar.view', 'calendar.create', 'calendar.update', 'calendar.delete', 'calendar.block',
            'reports.view',
            'audit_logs.view',
            'notifications.view', 'notifications.send',
        ])->pluck('id'));

        // Nurse: clinic care support.
        $nurse = Role::firstOrCreate(
            ['name' => 'nurse'],
            ['description' => 'Nurse — clinic care support', 'is_system' => true],
        );
        $nurse->update(['is_system' => true]);
        $nurse->permissions()->sync(Permission::whereIn('name', [
            'dashboard.view',
            'appointments.view', 'appointments.create',
            'consultations.view', 'consultations.create', 'consultations.update',
            'medical_records.view', 'medical_records.create', 'medical_records.update',
            'medical_certificates.view', 'medical_certificates.create',
            'prescriptions.view', 'prescriptions.create',
            'patients.view', 'patients.create',
            'schedules.view',
            'calendar.view', 'calendar.create', 'calendar.update', 'calendar.delete', 'calendar.block',
            'audit_logs.view',
            'notifications.view',
        ])->pluck('id'));

        // Patient: mobile app self-service.
        $patientRole = Role::firstOrCreate(
            ['name' => 'patient'],
            ['description' => 'Patient — student or faculty mobile access', 'is_system' => true],
        );
        $patientRole->update(['is_system' => true]);

        // Clean up legacy staff role if present.
        $legacyStaff = Role::where('name', 'staff')->first();
        if ($legacyStaff) {
            User::where('role_id', $legacyStaff->id)->update(['role_id' => $nurse->id]);
            $legacyStaff->permissions()->detach();
            $legacyStaff->delete();
        }

        // Any user still without a role defaults to patient.
        User::whereNull('role_id')->update(['role_id' => $patientRole->id]);
    }
}
