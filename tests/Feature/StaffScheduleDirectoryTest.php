<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\SyncState;
use App\Services\AppointmentScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffScheduleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
    }

    private function staff(string $name = 'doctor', array $values = []): User
    {
        $role = Role::firstOrCreate(['name' => $name], ['description' => ucfirst($name)]);
        foreach (['schedules.view', 'schedules.create', 'schedules.update', 'schedules.delete'] as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['module' => 'schedules', 'label' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create([...$values, 'role_id' => $role->id, 'status' => 'active']);
    }

    private function shift(User $user, string $date = '2026-10-19', string $start = '08:00 AM', string $end = '12:00 PM'): StaffSchedule
    {
        return StaffSchedule::create(['user_id' => $user->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'status' => 'Available']);
    }

    public function test_directory_displays_one_record_per_staff_id_despite_multiple_days_and_time_slots(): void
    {
        $admin = $this->staff('admin');
        $doctor = $this->staff('doctor', ['name' => 'Alex Santos']);
        $nurse = $this->staff('nurse', ['name' => 'Alex Santos']);
        $this->shift($doctor);
        $this->shift($doctor, '2026-10-19', '01:00 PM', '05:00 PM');
        $this->shift($doctor, '2026-10-21');
        $this->shift($nurse);
        $this->actingAs($admin, 'sanctum');
        $rows = $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$doctor->id, $nurse->id], array_column($rows, 'userId'));
        $this->assertSame(4, StaffSchedule::count());
        $this->assertSame('2026-10-19', $rows[0]['nextDate']);
    }

    public function test_popup_includes_staff_information_weekdays_all_shifts_and_assignments_even_outside_adjusted_hours(): void
    {
        $admin = $this->staff('admin');
        $doctor = $this->staff();
        $doctor->staffProfile()->create(['specialization' => 'Family Medicine']);
        $other = $this->staff();
        $this->shift($doctor);
        $this->shift($doctor, '2026-10-19', '01:00 PM', '05:00 PM');
        $this->shift($doctor, '2026-10-21', '01:00 PM', '05:00 PM');
        $morning = Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-19', 'time' => '09:00 AM', 'status' => 'Approved']);
        $outsideHours = Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-21', 'time' => '09:00 AM', 'status' => 'Approved']);
        Appointment::factory()->create(['staff_id' => $other->id, 'date' => '2026-10-19', 'status' => 'Approved']);
        Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-19', 'status' => 'Cancelled']);
        Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-24', 'status' => 'Approved']);
        Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-26', 'status' => 'Approved']);
        $this->actingAs($admin, 'sanctum');
        $data = $this->getJson("/api/staff-schedules/staff/{$doctor->id}?date=2026-10-22")->assertOk()->json('data');
        $this->assertSame($doctor->name, $data['name']);
        $this->assertArrayNotHasKey('role', $data);
        $this->assertArrayNotHasKey('department', $data);
        $this->assertArrayNotHasKey('specialization', $data);
        $this->assertArrayNotHasKey('email', $data);
        $row = collect($this->getJson('/api/staff-schedules/staff')->assertOk()->json('data'))->firstWhere('userId', $doctor->id);
        $this->assertSame('doctor', $row['user']['role']);
        $this->assertSame($doctor->email, $row['email']);
        $this->assertSame('Family Medicine', $row['specialization']);
        $this->assertNotEmpty($row['department']);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], array_column($data['days'], 'label'));
        $this->assertCount(2, $data['days'][0]['schedules']);
        $this->assertCount(1, $data['days'][2]['schedules']);
        $this->assertSame(['08:00 AM', '01:00 PM'], array_column($data['days'][0]['schedules'], 'startTime'));
        $this->assertSame([$morning->id, $outsideHours->id], array_column($data['assignedPatients'], 'id'));
        $this->getJson("/api/staff-schedules/staff/{$doctor->id}?date=bad")->assertUnprocessable();
    }

    public function test_popup_and_summary_follow_database_updates_and_assignments_without_duplicate_staff(): void
    {
        $admin = $this->staff('admin');
        $doctor = $this->staff();
        $shift = $this->shift($doctor);
        $this->actingAs($admin, 'sanctum');
        $url = "/api/staff-schedules/staff/{$doctor->id}?date=2026-10-19";
        $this->getJson($url)->assertOk()->assertJsonPath('data.days.0.schedules.0.endTime', '12:00 PM');
        $version = SyncState::versions()['staff_schedules'];
        $this->putJson("/api/staff-schedules/{$shift->id}", ['end_time' => '11:00 AM', 'status' => 'Unavailable'])->assertOk();
        $this->assertNotSame($version, SyncState::versions()['staff_schedules']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.days.0.schedules.0.endTime', '11:00 AM')
            ->assertJsonPath('data.days.0.schedules.0.status', 'Unavailable');
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Unavailable');
        $appointment = Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-19', 'status' => 'Approved']);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.assignedPatients');
        $appointment->update(['staff_id' => null]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.assignedPatients');
        $this->deleteJson("/api/staff-schedules/{$shift->id}")->assertOk();
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_detail_requires_schedule_permission_and_clinicians_only_see_their_own_records(): void
    {
        $doctor = $this->staff();
        $other = $this->staff('nurse');
        $this->shift($doctor);
        $this->shift($other);
        $this->getJson('/api/staff-schedules/staff')->assertUnauthorized();
        $this->getJson("/api/staff-schedules/staff/{$doctor->id}")->assertUnauthorized();
        $this->actingAs($doctor, 'sanctum');
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.userId', $doctor->id);
        $this->getJson("/api/staff-schedules/staff/{$other->id}")->assertForbidden();
        $this->actingAs($this->staff('front_desk'), 'sanctum');
        $this->getJson("/api/staff-schedules/staff/{$doctor->id}")->assertOk();
    }

    public function test_popup_keeps_weekly_rules_and_date_exceptions_distinct_after_live_updates(): void
    {
        $doctor = $this->staff();
        $this->actingAs($doctor, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', ['user_id' => $doctor->id, 'days' => [
            ['weekday' => 1, 'start_time' => '08:00 AM', 'end_time' => '12:00 PM'],
            ['weekday' => 3, 'start_time' => '01:00 PM', 'end_time' => '05:00 PM'],
        ]])->assertOk();
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(1, 'data');
        $url = "/api/staff-schedules/staff/{$doctor->id}?date=2026-10-21";
        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame('08:00 AM', $data['days'][0]['schedules'][0]['startTime']);
        $this->assertSame('01:00 PM', $data['days'][2]['regularHours']['startTime']);
        $wednesdayId = $data['days'][2]['schedules'][0]['id'];
        $this->putJson("/api/staff-schedules/{$wednesdayId}", ['status' => 'Unavailable', 'notes' => 'One-day absence'])->assertOk();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.days.2.schedules.0.status', 'Unavailable')
            ->assertJsonPath('data.days.2.schedules.0.isOverride', true)
            ->assertJsonPath('data.days.2.regularHours.startTime', '01:00 PM');
        $this->getJson("/api/staff-schedules/staff/{$doctor->id}?date=2026-10-28")->assertOk()
            ->assertJsonPath('data.days.2.schedules.0.status', 'Available');
    }

    public function test_live_status_follows_working_hours_for_doctors_and_nurses_on_web_and_mobile(): void
    {
        $admin = $this->staff('admin');
        $student = $this->staff('student');
        $student->update(['patient_id' => Patient::factory()->create()->patient_id]);
        $staff = [$this->staff(), $this->staff('nurse')];
        foreach ($staff as $user) {
            $this->shift($user);
            $this->shift($user, '2026-10-19', '01:00 PM', '05:00 PM');
            $this->shift($user, '2026-10-26');
        }
        foreach (['07:59' => 'Unavailable', '08:00' => 'Available', '11:59' => 'Available',
            '12:00' => 'Unavailable', '13:00' => 'Available', '16:59' => 'Available', '17:00' => 'Unavailable'] as $time => $expected) {
            $this->travelTo(now()->setDate(2026, 10, 19)->setTimeFromTimeString($time));
            $this->actingAs($admin, 'sanctum');
            $rows = $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(2, 'data')->json('data');
            $this->assertSame([$expected, $expected], array_column($rows, 'status'));
            $this->actingAs($student, 'sanctum');
            foreach ($staff as $user) {
                $this->getJson("/api/me/clinic-staff/{$user->id}")->assertOk()->assertJsonPath('data.status', $expected);
            }
            $mobileRows = $this->getJson('/api/me/staff-schedules')->assertOk()->json('data');
            $this->assertSame([$expected, $expected], array_column($mobileRows, 'status'));
        }
        foreach (['2026-10-24', '2026-10-25', '2026-11-30'] as $date) {
            foreach ($staff as $user) $this->shift($user, $date); // Legacy shifts cannot reopen weekends or holidays.
            $this->travelTo(now()->setDateFrom($date)->setTime(9, 0));
            $this->actingAs($admin, 'sanctum');
            $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonPath('data.0.status', 'Unavailable');
            $this->actingAs($student, 'sanctum');
            $this->getJson("/api/me/clinic-staff/{$staff[0]->id}")->assertOk()->assertJsonPath('data.status', 'Unavailable');
        }
        $this->assertSame(0, StaffSchedule::where('status', 'Unavailable')->count());
        $this->assertSame('active', $staff[0]->fresh()->status);
    }

    public function test_absence_blocks_status_and_booking_without_changing_the_regular_weekly_schedule(): void
    {
        $doctor = $this->staff();
        $this->actingAs($doctor, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', ['user_id' => $doctor->id, 'days' => [
            ['weekday' => 1, 'start_time' => '08:00 AM', 'end_time' => '05:00 PM'],
        ]])->assertOk();
        $this->travelTo(now()->setTime(9, 0));
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonPath('data.0.status', 'Available');
        $shift = StaffSchedule::where('user_id', $doctor->id)->whereDate('date', '2026-10-19')->firstOrFail();
        $this->putJson("/api/staff-schedules/{$shift->id}", ['status' => 'Unavailable', 'notes' => 'Approved absence'])->assertOk();
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonPath('data.0.status', 'Unavailable');
        $scheduler = app(AppointmentScheduler::class);
        $this->assertFalse($scheduler->hasAvailableShift($doctor->id, '2026-10-19', '09:00 AM'));
        $this->assertNotNull($scheduler->conflict('2026-10-19', '09:00 AM', $doctor->id));
        $this->assertFalse(collect($scheduler->slotAvailability('2026-10-19', $doctor, null))->contains('available', true));
        $this->assertTrue($scheduler->hasAvailableShift($doctor->id, '2026-10-26', '09:00 AM'));
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(9, 0));
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonPath('data.0.status', 'Available');
    }
}
