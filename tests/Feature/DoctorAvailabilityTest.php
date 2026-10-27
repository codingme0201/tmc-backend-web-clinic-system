<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);
        if ($roleName === 'admin') {
            foreach (['appointments.create', 'appointments.reschedule', 'appointments.update'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], ['module' => 'appointments', 'label' => $name]);
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }

    private function shift(User $staff, string $date, string $start = '09:00 AM', string $end = '11:00 AM', string $status = 'Available'): StaffSchedule
    {
        return StaffSchedule::create(['user_id' => $staff->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'status' => $status]);
    }

    public function test_calendar_disables_no_shift_days_fully_booked_days_and_gaps_in_split_shifts(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $doctor = $this->user('doctor');
        $this->shift($doctor, '2026-10-22');
        $this->shift($doctor, '2026-10-22', '01:00 PM', '02:00 PM');
        $this->shift($doctor, '2026-10-26', '09:00 AM', '10:00 AM');
        $this->shift($doctor, '2026-10-27', '08:00 AM', '05:00 PM', 'Unavailable');
        foreach (['2026-10-22' => ['09:30 AM'], '2026-10-26' => ['09:00 AM', '09:30 AM']] as $date => $times) {
            foreach ($times as $time) {
                Appointment::factory()->create(['staff_id' => $doctor->id, 'staff' => $doctor->name, 'date' => $date, 'time' => $time, 'status' => 'Approved']);
            }
        }
        $this->actingAs($this->user('admin'), 'sanctum');
        $data = $this->getJson("/api/appointment-calendar?year=2026&month=10&staff_id={$doctor->id}")->assertOk()->json('data.days');
        $this->assertTrue($data['2026-10-22']['available']);
        foreach (['2026-10-23', '2026-10-26', '2026-10-27'] as $date) {
            $this->assertFalse($data[$date]['available']);
            $this->assertSame('Doctor not available', $data[$date]['reason']);
        }
        $this->assertSame('Weekend', $data['2026-10-24']['reason']);
        $slots = collect($data['2026-10-22']['slots'])->keyBy('time');
        foreach (['09:00 AM', '10:00 AM', '10:30 AM', '01:00 PM', '01:30 PM'] as $time) $this->assertTrue($slots[$time]['available']);
        foreach (['08:30 AM', '09:30 AM', '11:00 AM', '02:00 PM', '05:00 PM'] as $time) $this->assertFalse($slots[$time]['available']);
        $this->assertArrayNotHasKey('12:00 PM', $slots->all()); // Clinic lunch break.

        Appointment::where('staff_id', $doctor->id)->whereDate('date', '2026-10-26')->where('time', '09:30 AM')->update(['status' => 'Cancelled']);
        $this->getJson("/api/appointment-calendar?year=2026&month=10&staff_id={$doctor->id}")->assertOk()->assertJsonPath('data.days.2026-10-26.available', true);
    }

    public function test_availability_is_specific_to_doctor_and_preserves_buffer_and_shift_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $doctor = $this->user('doctor');
        $other = $this->user('doctor');
        $other->update(['name' => $doctor->name]);
        $this->shift($doctor, '2026-10-22', '09:00 AM', '11:00 AM');
        Appointment::factory()->create(['staff_id' => $other->id, 'staff' => $doctor->name, 'date' => '2026-10-22', 'time' => '10:00 AM', 'status' => 'Approved']);
        $scheduler = app(AppointmentScheduler::class);
        $slots = collect($scheduler->slotAvailability('2026-10-22', $doctor, null))->keyBy('time');
        $this->assertTrue($slots['10:00 AM']['available']);
        $this->assertFalse($slots['11:00 AM']['available']);
        SystemSetting::getInstance()->update(['appointment_buffer_minutes' => 45]);
        Appointment::factory()->create(['staff_id' => $doctor->id, 'date' => '2026-10-22', 'time' => '09:00 AM', 'status' => 'Pending']);
        $slots = collect($scheduler->slotAvailability('2026-10-22', $doctor, null))->keyBy('time');
        $this->assertFalse($slots['09:30 AM']['available']);
        $this->assertTrue($slots['10:00 AM']['available']);
    }

    public function test_web_and_mobile_cannot_book_outside_the_selected_doctors_shift(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $doctor = $this->user('doctor');
        $patient = Patient::factory()->create();
        $this->shift($doctor, '2026-10-22', '09:00 AM', '10:00 AM');
        $payload = ['patient' => $patient->name, 'patient_id' => $patient->patient_id, 'type' => 'General Check-up', 'reason' => 'Check-up', 'date' => '2026-10-22', 'time' => '08:00 AM', 'staff_id' => $doctor->id];
        $this->actingAs($this->user('admin'), 'sanctum');
        $this->postJson('/api/appointments', $payload)->assertUnprocessable()->assertJsonPath('message', 'Doctor not available');
        $this->postJson('/api/appointments', [...$payload, 'date' => '2026-10-23', 'time' => '09:00 AM'])->assertUnprocessable()->assertJsonPath('message', 'Doctor not available');
        $id = $this->postJson('/api/appointments', [...$payload, 'time' => '09:00 AM'])->assertCreated()->json('data.id');
        $this->postJson("/api/appointments/{$id}/reschedule", ['date' => '2026-10-22', 'time' => '10:00 AM'])->assertUnprocessable();
        $this->postJson('/api/appointments', [...$payload, 'time' => '09:00 AM'])->assertUnprocessable();
        $this->postJson("/api/appointments/{$id}/reschedule", ['date' => '2026-10-22', 'time' => '09:00 AM'])->assertOk(); // Ignore its own slot.

        $student = $this->user('student');
        $student->update(['patient_id' => $patient->patient_id]);
        $this->actingAs($student, 'sanctum');
        $this->postJson('/api/me/appointments', $payload)->assertUnprocessable()->assertJsonPath('message', 'Doctor not available');
        $this->postJson('/api/me/appointments', [...$payload, 'time' => '09:30 AM'])->assertCreated();
        $this->getJson("/api/me/appointments/availability?date=2026-10-23&staff_id={$doctor->id}")->assertOk()->assertJsonPath('data.slots.0.available', false);
        $this->getJson('/api/me/appointments/availability?date=2026-10-22&staff_id=999999')->assertUnprocessable();
    }

    public function test_schedule_changes_and_full_clinic_day_close_the_doctor_calendar(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $doctor = $this->user('doctor');
        $shift = $this->shift($doctor, '2026-10-22');
        $this->actingAs($this->user('admin'), 'sanctum');
        $url = "/api/appointment-calendar?year=2026&month=10&staff_id={$doctor->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-22.available', true);
        $shift->update(['status' => 'Unavailable']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-22.available', false);
        $shift->update(['status' => 'Available']);
        SystemSetting::getInstance()->update(['max_daily_appointments' => 1]);
        Appointment::factory()->create(['date' => '2026-10-22', 'status' => 'Approved']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.days.2026-10-22.reason', 'Doctor not available');
        $doctor->update(['status' => 'inactive']);
        $this->getJson($url)->assertUnprocessable();
    }
}
