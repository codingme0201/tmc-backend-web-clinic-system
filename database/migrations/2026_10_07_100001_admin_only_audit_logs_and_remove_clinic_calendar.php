<?php

use App\Support\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * - Audit logs become admin-only (removed from doctor/nurse).
     * - Doctors can delete consultations.
     * - The Clinic Calendar module is removed (its permissions go away).
     */
    public function up(): void
    {
        $calendarIds = DB::table('permissions')->where('name', 'like', 'calendar.%')->pluck('id');
        if ($calendarIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $calendarIds)->delete();
            DB::table('permissions')->whereIn('id', $calendarIds)->delete();
        }

        foreach (array_keys(RolePermissions::ROLES) as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('name', RolePermissions::for($roleName))->pluck('id');
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            if ($permissionIds->isNotEmpty()) {
                DB::table('permission_role')->insert(
                    $permissionIds->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id])->all(),
                );
            }
        }

        // Any other custom role loses audit log access too — it is admin-only.
        $auditId = DB::table('permissions')->where('name', 'audit_logs.view')->value('id');
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($auditId) {
            DB::table('permission_role')
                ->where('permission_id', $auditId)
                ->when($adminId, fn ($q) => $q->where('role_id', '!=', $adminId))
                ->delete();
        }
    }

    public function down(): void
    {
        // Permission sets are owned by RolePermissions / the seeder.
    }
};
