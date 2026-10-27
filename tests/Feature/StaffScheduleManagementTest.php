<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'status' => 'active']);
    }

    private function payload(User $staff, string $date = '2026-10-26'): array
    {
        return ['user_id' => $staff->id, 'date' => $date, 'start_time' => '09:00 AM', 'end_time' => '11:00 AM', 'status' => 'Available'];
    }

    public function test_doctors_and_nurses_can_plan_own_future_shifts_but_cannot_access_or_change_others(): void
    {
        foreach (['doctor', 'nurse'] as $role) {
            $staff = $this->staff($role);
            $other = $this->staff($role);
            $otherShift = StaffSchedule::create($this->payload($other));
            $this->actingAs($staff, 'sanctum');
            $id = $this->postJson('/api/staff-schedules', $this->payload($staff))->assertCreated()->json('data.id');
            $this->getJson('/api/staff-schedules')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
            $this->getJson('/api/staff-schedules?date_from=2026-10-26&date_to=2026-10-30')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson('/api/staff-schedules?user_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/staff-schedules/'.$id)->assertOk();
            $this->putJson('/api/staff-schedules/'.$id, ['end_time' => '10:00 AM'])->assertOk();
            $this->patchJson('/api/staff-schedules/'.$id.'/availability', ['status' => 'Unavailable'])->assertOk();
            $this->getJson('/api/staff-schedules/'.$otherShift->id)->assertForbidden();
            $this->postJson('/api/staff-schedules', $this->payload($other, '2026-10-27'))->assertForbidden();
            $this->putJson('/api/staff-schedules/'.$otherShift->id, ['notes' => 'Changed'])->assertForbidden();
            $this->putJson('/api/staff-schedules/'.$id, ['user_id' => $other->id])->assertForbidden();
            $this->patchJson('/api/staff-schedules/'.$otherShift->id.'/availability', ['status' => 'Unavailable'])->assertForbidden();
            // Even a stale/custom delete grant cannot allow deletion of someone else's shift.
            $permission = Permission::where('name', 'schedules.delete')->firstOrFail();
            $staff->role->permissions()->syncWithoutDetaching([$permission->id]);
            $this->deleteJson('/api/staff-schedules/'.$otherShift->id)->assertForbidden();
            $this->assertDatabaseHas('staff_schedules', ['id' => $otherShift->id, 'status' => 'Available', 'notes' => null]);
        }
    }

    public function test_admin_can_manage_all_staff_and_appointment_availability_reflects_every_change(): void
    {
        $admin = $this->staff('admin');
        foreach (['doctor', 'nurse'] as $role) {
            $staff = $this->staff($role);
            $this->actingAs($staff, 'sanctum');
            $id = $this->postJson('/api/staff-schedules', $this->payload($staff))->assertCreated()->json('data.id');
            $this->actingAs($admin, 'sanctum');
            $url = '/api/appointment-calendar?year=2026&month=10&staff_id='.$staff->id;
            $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-26.available', true);
            $this->putJson('/api/staff-schedules/'.$id, ['date' => '2026-10-27', 'end_time' => '10:00 AM'])->assertOk();
            $days = $this->getJson($url)->assertOk()->json('data.days');
            $this->assertFalse($days['2026-10-26']['available']);
            $slots = collect($days['2026-10-27']['slots'])->keyBy('time');
            $this->assertTrue($slots['09:30 AM']['available']);
            $this->assertFalse($slots['10:00 AM']['available']);
            $this->patchJson('/api/staff-schedules/'.$id.'/availability', ['status' => 'Unavailable'])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-27.available', false);
            $this->patchJson('/api/staff-schedules/'.$id.'/availability', ['status' => 'Available'])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-27.available', true);
            $this->deleteJson('/api/staff-schedules/'.$id)->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-27.available', false);
            $this->postJson('/api/staff-schedules', $this->payload($staff, '2026-10-28'))->assertCreated();
        }
        $this->getJson('/api/staff-schedules')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_closed_days_are_rejected_for_creation_edits_and_reopening_legacy_shifts(): void
    {
        $staff = $this->staff('doctor');
        $this->actingAs($staff, 'sanctum');
        config(['appointment_calendar.closed_dates' => ['2026-10-28' => 'Maintenance']]);
        $id = $this->postJson('/api/staff-schedules', $this->payload($staff))->assertCreated()->json('data.id');
        foreach (['2026-10-24' => 'Weekend', '2026-10-25' => 'Weekend', '2026-11-30' => 'Holiday: Bonifacio Day', '2026-10-28' => 'Clinic Closed'] as $date => $reason) {
            $this->postJson('/api/staff-schedules', $this->payload($staff, $date))->assertUnprocessable()->assertJsonPath('errors.date.0', $reason);
            $this->putJson('/api/staff-schedules/'.$id, ['date' => $date])->assertUnprocessable()->assertJsonPath('errors.date.0', $reason);
        }
        SystemSetting::getInstance()->update(['clinic_days' => 'Tue-Fri']);
        $this->postJson('/api/staff-schedules', $this->payload($staff, '2026-11-09'))->assertUnprocessable()->assertJsonPath('errors.date.0', 'Clinic Closed');
        $legacy = StaffSchedule::create($this->payload($staff, '2026-10-24'));
        $this->patchJson('/api/staff-schedules/'.$legacy->id.'/availability', ['status' => 'Available'])->assertUnprocessable();
        $this->patchJson('/api/staff-schedules/'.$legacy->id.'/availability', ['status' => 'Unavailable'])->assertOk();
        $this->postJson('/api/staff-schedules', $this->payload($staff, 'invalid'))->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_migration_grants_schedule_access_to_existing_clinician_roles(): void
    {
        $permissionIds = Permission::whereIn('name', ['schedules.create', 'schedules.update'])->pluck('id');
        foreach (Role::whereIn('name', ['doctor', 'nurse'])->get() as $role) $role->permissions()->detach($permissionIds);
        $migration = require database_path('migrations/2026_10_24_100000_allow_clinicians_to_manage_own_schedules.php');
        $migration->up();
        $migration->up(); // Safe on already upgraded installations.
        foreach (['doctor', 'nurse'] as $role) {
            $staff = $this->staff($role);
            $this->assertTrue($staff->hasPermission('schedules.create'));
            $this->assertTrue($staff->hasPermission('schedules.update'));
        }
        $this->assertFalse($this->staff('front_desk')->hasPermission('schedules.create'));
    }
}
