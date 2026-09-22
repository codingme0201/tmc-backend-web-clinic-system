<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce standard system roles (admin, doctor, nurse, student)
     * and ensure all patients are classified as Student.
     * Removes legacy staff, instructor, faculty roles.
     */
    public function up(): void
    {
        // 1. Create or ensure 'student' role exists
        $studentRole = DB::table('roles')->where('name', 'student')->first();
        if (! $studentRole) {
            $studentRoleId = DB::table('roles')->insertGetId([
                'name' => 'student',
                'description' => 'Student — mobile app self-service',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $studentRoleId = $studentRole->id;
            DB::table('roles')->where('id', $studentRoleId)->update([
                'is_system' => true,
                'description' => 'Student — mobile app self-service',
            ]);
        }

        // 2. Migrate any users from legacy 'patient' role to 'student'
        $patientRole = DB::table('roles')->where('name', 'patient')->first();
        if ($patientRole) {
            DB::table('users')->where('role_id', $patientRole->id)->update(['role_id' => $studentRoleId]);
            DB::table('permission_role')->where('role_id', $patientRole->id)->delete();
            DB::table('roles')->where('id', $patientRole->id)->delete();
        }

        // 3. Remove prohibited roles: staff, faculty, instructor
        $nurseRole = DB::table('roles')->where('name', 'nurse')->first();
        $nurseRoleId = $nurseRole?->id;

        $prohibitedRoles = DB::table('roles')->whereIn('name', ['staff', 'faculty', 'instructor'])->get();
        foreach ($prohibitedRoles as $proRole) {
            if ($nurseRoleId) {
                DB::table('users')->where('role_id', $proRole->id)->update(['role_id' => $nurseRoleId]);
            }
            DB::table('permission_role')->where('role_id', $proRole->id)->delete();
            DB::table('roles')->where('id', $proRole->id)->delete();
        }

        // 4. Protect the 4 standard system roles
        DB::table('roles')->whereIn('name', ['admin', 'doctor', 'nurse', 'student'])->update(['is_system' => true]);

        // 5. Ensure all patients are classified as Student
        DB::table('patients')->whereIn('type', ['Faculty', 'Staff', 'Visitor', 'faculty', 'staff', 'visitor'])->update([
            'type' => 'Student',
        ]);
    }

    public function down(): void
    {
        // One-way migration ensuring role integrity
    }
};
