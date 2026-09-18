<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove the legacy staff role and ensure base system roles are protected.
     */
    public function up(): void
    {
        $staffRole = DB::table('roles')->where('name', 'staff')->first();
        if ($staffRole) {
            $nurseRole = DB::table('roles')->where('name', 'nurse')->first();
            if ($nurseRole) {
                DB::table('users')->where('role_id', $staffRole->id)->update(['role_id' => $nurseRole->id]);
            }
            DB::table('permission_role')->where('role_id', $staffRole->id)->delete();
            DB::table('roles')->where('id', $staffRole->id)->delete();
        }

        // Set is_system to true on the base roles: admin, doctor, nurse, patient.
        DB::table('roles')->whereIn('name', ['admin', 'doctor', 'nurse', 'patient'])->update(['is_system' => true]);
    }

    public function down(): void
    {
        // Staff role is discontinued; down does not restore it.
    }
};
