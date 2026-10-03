<?php

use App\Support\ClinicSchedule;
use App\Support\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(RolePermissions::ROLES) as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();
            $roleId = $role?->id ?? DB::table('roles')->insertGetId([
                'name' => $roleName,
                'description' => RolePermissions::description($roleName),
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('roles')->where('id', $roleId)->update([
                'description' => RolePermissions::description($roleName),
                'is_system' => true,
            ]);

            // Only existing permission rows are linked; the seeder owns the catalog.
            $permissionIds = DB::table('permissions')->whereIn('name', RolePermissions::for($roleName))->pluck('id');
            if ($permissionIds->isNotEmpty()) {
                DB::table('permission_role')->where('role_id', $roleId)->delete();
                DB::table('permission_role')->insert(
                    $permissionIds->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id])->all(),
                );
            }
        }

        $start = ClinicSchedule::toMinutes(ClinicSchedule::WORK_START);
        $end = ClinicSchedule::toMinutes(ClinicSchedule::WORK_END);
        $clamp = fn (?string $time) => ClinicSchedule::fromMinutes(
            min(max(ClinicSchedule::toMinutes($time) ?? $start, $start), $end),
        );

        DB::table('staff_schedules')->orderBy('id')->each(function ($schedule) use ($clamp) {
            DB::table('staff_schedules')->where('id', $schedule->id)->update([
                'start_time' => $clamp($schedule->start_time),
                'end_time' => $clamp($schedule->end_time),
            ]);
        });

        DB::table('staff')->orderBy('id')->each(function ($member) use ($clamp) {
            $parts = array_map('trim', explode('-', (string) $member->shift));
            if (count($parts) === 2) {
                DB::table('staff')->where('id', $member->id)->update([
                    'shift' => ltrim($clamp($parts[0]), '0').' - '.ltrim($clamp($parts[1]), '0'),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Role and schedule alignment is one-way.
    }
};
