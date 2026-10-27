<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\WeeklyStaffSchedule;
use App\Services\AppointmentScheduler;
use App\Services\WeeklyScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyStaffScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
    }

    private function staff(string $name = 'doctor'): User
    {
        $role = Role::firstOrCreate(['name' => $name], ['description' => ucfirst($name)]);
        foreach (['schedules.view', 'schedules.create', 'schedules.update', 'schedules.delete'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => 'schedules', 'label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }

    private function weekly(User $user, array $days = [1, 3, 5]): array
    {
        return ['user_id' => $user->id, 'days' => array_map(fn ($weekday) => [
            'weekday' => $weekday, 'start_time' => '08:00 AM', 'end_time' => '05:00 PM',
        ], $days)];
    }

    private function occurrence(User $user, string $date): StaffSchedule
    {
        return StaffSchedule::where('user_id', $user->id)->whereDate('date', $date)->firstOrFail();
    }

    public function test_weekly_schedule_repeats_selected_weekdays_and_populates_existing_calendar(): void
    {
        $doctor = $this->staff();
        $this->actingAs($doctor, 'sanctum');
        $payload = $this->weekly($doctor);
        $payload['days'][1]['start_time'] = '01:00 PM';
        $this->postJson('/api/weekly-staff-schedules', $payload)->assertOk();
        $this->getJson('/api/weekly-staff-schedules')->assertOk()->assertJsonCount(3, 'data');
        $rows = $this->getJson('/api/staff-schedules')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertContains((int) date('N', strtotime($row['date'])), [1, 3, 5]);
        }
        $this->getJson('/api/staff-schedules')->assertOk()->assertJsonCount(count($rows), 'data');
        $this->getJson("/api/appointment-calendar?year=2026&month=10&staff_id={$doctor->id}")->assertOk()
            ->assertJsonPath('data.days.2026-10-21.slots.0.available', false)
            ->assertJsonPath('data.days.2026-10-23.slots.0.available', true)
            ->assertJsonPath('data.days.2026-10-20.available', false);

        $scheduler = app(AppointmentScheduler::class);
        $this->assertTrue($scheduler->hasAvailableShift($doctor->id, '2027-02-01', '09:00 AM'));
        $this->assertFalse($scheduler->hasAvailableShift($doctor->id, '2026-12-25', '09:00 AM'));
    }

    public function test_date_absence_and_adjusted_hours_leave_regular_weekly_schedule_unchanged(): void
    {
        $nurse = $this->staff('nurse');
        $this->actingAs($nurse, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($nurse))->assertOk();
        $wednesday = $this->occurrence($nurse, '2026-10-21');
        $this->putJson("/api/staff-schedules/{$wednesday->id}", [
            'user_id' => $nurse->id, 'date' => '2026-10-21', 'start_time' => '08:00 AM',
            'end_time' => '05:00 PM', 'status' => 'Unavailable', 'notes' => 'Sudden absence',
        ])->assertOk()->assertJsonPath('data.isOverride', true)->assertJsonPath('data.notes', 'Sudden absence');
        $monday = $this->occurrence($nurse, '2026-10-19');
        $this->putJson("/api/staff-schedules/{$monday->id}", ['start_time' => '01:00 PM', 'end_time' => '04:00 PM'])->assertOk();

        $scheduler = app(AppointmentScheduler::class);
        $slots = collect($scheduler->slotAvailability('2026-10-21', $nurse, null));
        $this->assertFalse($slots->contains('available', true));
        $this->assertNotNull($scheduler->conflict('2026-10-21', '09:00 AM', $nurse->id));
        $this->assertFalse($scheduler->hasAvailableShift($nurse->id, '2026-10-19', '09:00 AM'));
        $this->assertTrue($scheduler->hasAvailableShift($nurse->id, '2026-10-19', '02:00 PM'));
        $this->assertTrue($scheduler->hasAvailableShift($nurse->id, '2026-10-26', '09:00 AM'));
        $this->assertTrue($scheduler->hasAvailableShift($nurse->id, '2026-10-28', '09:00 AM'));
        $this->getJson("/api/appointment-calendar?year=2026&month=10&staff_id={$nurse->id}")->assertOk()
            ->assertJsonPath('data.days.2026-10-21.available', false)
            ->assertJsonPath('data.days.2026-10-28.available', true);
        $this->assertSame('08:00 AM', WeeklyStaffSchedule::where('user_id', $nurse->id)->where('weekday', 1)->firstOrFail()->start_time);
    }

    public function test_weekly_changes_preserve_date_overrides_and_existing_explicit_schedules(): void
    {
        $doctor = $this->staff();
        $this->actingAs($doctor, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($doctor))->assertOk();
        $date = $this->occurrence($doctor, '2026-10-21');
        $this->patchJson("/api/staff-schedules/{$date->id}/availability", ['status' => 'Unavailable'])->assertOk();
        $this->postJson('/api/staff-schedules', [
            'user_id' => $doctor->id, 'date' => '2026-10-23', 'start_time' => '10:00 AM',
            'end_time' => '02:00 PM', 'status' => 'Available', 'notes' => 'Temporary hours',
        ])->assertCreated();
        $payload = $this->weekly($doctor);
        foreach ($payload['days'] as &$day) $day['start_time'] = '09:00 AM';
        unset($day);
        $this->postJson('/api/weekly-staff-schedules', $payload)->assertOk();
        $this->assertSame('Unavailable', $date->fresh()->status);
        $this->assertSame('10:00 AM', $this->occurrence($doctor, '2026-10-23')->start_time);
        $this->assertSame('09:00 AM', $this->occurrence($doctor, '2026-10-28')->start_time);
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($doctor, [1]))->assertOk();
        $this->assertSame('Unavailable', $date->fresh()->status);
        $this->assertSame('Temporary hours', $this->occurrence($doctor, '2026-10-23')->notes);
        $this->assertFalse(app(AppointmentScheduler::class)->hasAvailableShift($doctor->id, '2026-10-28', '10:00 AM'));
    }

    public function test_weekly_rules_reject_invalid_days_times_and_changes_to_other_staff(): void
    {
        $doctor = $this->staff();
        $other = $this->staff('nurse');
        $this->actingAs($doctor, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($other))->assertForbidden();
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($doctor, [6]))->assertUnprocessable();
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($doctor, [1, 1]))->assertUnprocessable();
        $badTimes = $this->weekly($doctor, [1]);
        $badTimes['days'][0]['end_time'] = '08:00 AM';
        $this->postJson('/api/weekly-staff-schedules', $badTimes)->assertUnprocessable();
        $badTimes['days'][0]['end_time'] = '06:00 PM';
        $this->postJson('/api/weekly-staff-schedules', $badTimes)->assertUnprocessable();
        $this->assertSame(0, WeeklyStaffSchedule::count());
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($other))->assertForbidden();
        $this->getJson('/api/weekly-staff-schedules')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleted_occurrence_does_not_return_and_other_weeks_continue(): void
    {
        $admin = $this->staff('admin');
        $doctor = $this->staff();
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/weekly-staff-schedules', $this->weekly($doctor, [1]))->assertOk();
        $schedule = $this->occurrence($doctor, '2026-10-26');
        $this->deleteJson("/api/staff-schedules/{$schedule->id}")->assertOk();
        app(WeeklyScheduleService::class)->ensureRange('2026-10-26', '2026-11-09', $doctor->id);
        $this->assertFalse(StaffSchedule::where('user_id', $doctor->id)->whereDate('date', '2026-10-26')->exists());
        $this->assertTrue(app(AppointmentScheduler::class)->hasAvailableShift($doctor->id, '2026-11-09', '09:00 AM'));
    }

    public function test_saving_regular_schedule_reuses_existing_records_and_updates_automatic_availability(): void
    {
        $doctor = $this->staff();
        $this->actingAs($doctor, 'sanctum');
        $payload = $this->weekly($doctor);
        $this->postJson('/api/weekly-staff-schedules', $payload)->assertOk();
        $ids = StaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all();
        $ruleIds = WeeklyStaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all();
        $this->postJson('/api/weekly-staff-schedules', $payload)->assertOk();
        $this->assertSame($ids, StaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($ruleIds, WeeklyStaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all());
        $this->travelTo(now()->setTime(9, 0));
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Available');
        $payload['days'][0]['start_time'] = '10:00 AM';
        $this->postJson('/api/weekly-staff-schedules', $payload)->assertOk();
        $this->assertSame($ids, StaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($ruleIds, WeeklyStaffSchedule::where('user_id', $doctor->id)->orderBy('id')->pluck('id')->all());
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Unavailable');
        $this->assertFalse(app(AppointmentScheduler::class)->hasAvailableShift($doctor->id, '2026-10-19', '09:00 AM'));
        $this->travelTo(now()->setTime(10, 0));
        $this->getJson('/api/staff-schedules/staff')->assertOk()->assertJsonPath('data.0.status', 'Available');
    }
}
