<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed realistic administrative and clinic personnel accounts.
     * Idempotent — keyed by email so re-seeding never trips unique constraints.
     */
    public function run(): void
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $doctorRoleId = Role::where('name', 'doctor')->value('id');
        $nurseRoleId = Role::where('name', 'nurse')->value('id');
        $frontDeskRoleId = Role::where('name', 'front_desk')->value('id');
        $studentRoleId = Role::where('name', 'student')->value('id') ?? Role::where('name', 'patient')->value('id');

        $users = [
            [
                'name' => 'Dr. R. Mendoza',
                'email' => 'rmendoza@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $doctorRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse C. Villanueva',
                'email' => 'cvillanueva@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse Maria Santos',
                'email' => 'msantos@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Dr. Ana Cruz',
                'email' => 'acruz@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $doctorRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse Juan Reyes',
                'email' => 'jreyes@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'inactive',
            ],
            [
                'name' => 'Dr. S. Lopez',
                'email' => 'slopez@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $doctorRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse J. Santos',
                'email' => 'jsantos@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Liza Fernandez',
                'email' => 'frontdesk@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $frontDeskRoleId,
                'status' => 'active',
            ],
            // Mobile Patient Accounts
            [
                'name' => 'Angela Reyes',
                'email' => 'demo@tmccarelink.com',
                'password' => 'Demo1234',
                'role_id' => $studentRoleId,
                'patient_id' => '24-021128',
                'status' => 'active',
            ],
            [
                'name' => 'Angela Reyes',
                'email' => 'angela.reyes@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $studentRoleId,
                'patient_id' => '24-021128',
                'status' => 'active',
            ],
            [
                'name' => 'Mark Dela Cruz',
                'email' => 'mark.delacruz@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $studentRoleId,
                'patient_id' => '22-010941',
                'status' => 'active',
            ],
            [
                'name' => 'Joanna Lim',
                'email' => 'joanna.lim@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $studentRoleId,
                'patient_id' => '21-011122',
                'status' => 'active',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(['email' => $user['email']], $user);
        }

        $this->seedStaffProfiles();
    }

    /**
     * Sample professional profiles: verified, pending and not-yet-submitted
     * credentials, so the staff directory shows each verification state.
     */
    private function seedStaffProfiles(): void
    {
        $adminId = User::where('email', 'admin@tmc.edu.ph')->value('id');

        $profiles = [
            'rmendoza@tmc.edu.ph' => ['position' => 'School Physician', 'specialization' => 'General Medicine', 'license_type' => 'PRC Physician License', 'license_number' => '0118452', 'license_issued_at' => '2019-06-14', 'license_expires_at' => '2028-06-14', 'credential_status' => 'Verified'],
            'acruz@tmc.edu.ph' => ['position' => 'Attending Physician', 'specialization' => 'Family Medicine', 'license_type' => 'PRC Physician License', 'license_number' => '0124987', 'license_issued_at' => '2021-02-03', 'license_expires_at' => '2027-02-03', 'credential_status' => 'Pending Verification'],
            'slopez@tmc.edu.ph' => ['position' => 'School Dentist', 'specialization' => 'General Dentistry', 'license_type' => 'PRC Dentist License', 'license_number' => '0056231', 'license_issued_at' => '2018-09-20', 'license_expires_at' => '2027-09-20', 'credential_status' => 'Verified'],
            'cvillanueva@tmc.edu.ph' => ['position' => 'Clinic Nurse', 'specialization' => 'Triage and Vital Signs', 'license_type' => 'PRC Nurse License', 'license_number' => '0842315', 'license_issued_at' => '2020-11-10', 'license_expires_at' => '2029-11-10', 'credential_status' => 'Verified'],
            'msantos@tmc.edu.ph' => ['position' => 'Nurse Assistant', 'specialization' => 'First Aid', 'license_type' => 'PRC Nurse License', 'license_number' => '0915574', 'license_issued_at' => '2022-05-18', 'license_expires_at' => '2028-05-18', 'credential_status' => 'Pending Verification'],
            'jsantos@tmc.edu.ph' => ['position' => 'Medical Assistant', 'specialization' => 'Immunization Support', 'credential_status' => 'Not Submitted'],
            'frontdesk@tmc.edu.ph' => ['position' => 'Front Desk Officer', 'specialization' => 'Patient Registration and Scheduling', 'credential_status' => 'Not Submitted'],
        ];

        foreach ($profiles as $email => $profile) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $verified = $profile['credential_status'] === 'Verified';
            StaffProfile::firstOrCreate(['user_id' => $user->id], [
                ...$profile,
                'verified_by' => $verified ? $adminId : null,
                'verified_at' => $verified ? now() : null,
            ]);
        }
    }
}
