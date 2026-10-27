<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('name', ['doctor', 'nurse'])->pluck('id');
        foreach (['view', 'create', 'update'] as $action) {
            $name = 'schedules.'.$action;
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name, 'module' => 'schedules', 'label' => ucfirst($action).' schedules',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($roles as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        // Preserve existing grants; they may also have been assigned by Admin.
    }
};
