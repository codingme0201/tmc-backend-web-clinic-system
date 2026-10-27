<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AppointmentCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['description' => 'Admin']);
        foreach (['appointments.create', 'appointments.reschedule', 'consultations.update'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => explode('.', $name)[0], 'label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }

    public function test_calendar_requires_authentication_but_not_settings_permission(): void
    {
        $this->getJson('/api/appointment-calendar?year=2026')->assertUnauthorized();
        $this->actingAs($this->staff(), 'sanctum');
        $this->getJson('/api/appointment-calendar?year=bad')->assertUnprocessable();
        $this->getJson('/api/appointment-calendar?year=2026')->assertOk()
            ->assertJsonPath('data.closedDates.2026-10-24', 'Weekend')
            ->assertJsonPath('data.closedDates.2026-10-25', 'Weekend')
            ->assertJsonPath('data.closedDates.2026-11-30', 'Holiday: Bonifacio Day')
            ->assertJsonMissingPath('data.closedDates.2026-10-22')
            ->assertJsonMissingPath('data.closedDates.2026-02-25');
    }

    public function test_admin_doctor_and_staff_receive_the_same_philippine_calendar(): void
    {
        $this->actingAs($this->staff(), 'sanctum');
        $calendar = $this->getJson('/api/appointment-calendar?year=2026')->assertOk()->json('data');
        foreach (['doctor', 'nurse', 'front_desk'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['description' => ucfirst($name)]);
            $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
            $this->actingAs($user, 'sanctum');
            $this->assertSame($calendar, $this->getJson('/api/appointment-calendar?year=2026')->assertOk()->json('data'));
        }
    }

    public function test_announced_holidays_and_movable_holidays_have_exact_names(): void
    {
        $calendar = app(AppointmentCalendar::class);
        foreach ([
            '2026-02-17' => 'Chinese New Year', '2026-03-20' => "Eid'l Fitr",
            '2026-04-02' => 'Maundy Thursday', '2026-04-03' => 'Good Friday',
            '2026-05-27' => "Eid'l Adha", '2026-08-31' => 'National Heroes Day',
            '2027-02-06' => 'Chinese New Year', '2027-03-25' => 'Maundy Thursday',
            '2027-03-26' => 'Good Friday', '2027-08-30' => 'National Heroes Day',
        ] as $date => $name) {
            $this->assertSame('Holiday: '.$name, $calendar->reason($date));
        }
        $this->assertNull($calendar->reason('2027-02-25')); // Special working day.
    }

    public function test_clinic_working_days_and_explicit_closures_use_the_same_calendar(): void
    {
        SystemSetting::getInstance()->update(['clinic_days' => 'Mon-Tue, Thu-Fri']);
        config(['appointment_calendar.closed_dates' => ['2026-10-22' => 'Clinic Closed']]);
        $calendar = app(AppointmentCalendar::class);
        $this->assertSame('Clinic Closed', $calendar->reason('2026-10-21'));
        $this->assertSame('Clinic Closed', $calendar->reason('2026-10-22'));
        $this->assertSame('Weekend', $calendar->reason('2026-10-24'));
        $this->assertNull($calendar->reason('2026-10-23'));
        $this->assertSame([1, 2, 3, 4, 5], $calendar->workingDays('Monday – Friday'));
        $this->assertSame([1, 3, 5], $calendar->workingDays('Monday, Wednesday and Friday'));
    }

    public function test_booking_rescheduling_and_mobile_availability_reject_closed_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        config(['appointment_calendar.closed_dates' => ['2026-10-21' => 'Clinic Closed']]);
        $patient = Patient::factory()->create();
        $this->actingAs($this->staff(), 'sanctum');
        $payload = ['patient' => $patient->name, 'patient_id' => $patient->patient_id, 'type' => 'General Check-up', 'reason' => 'Check-up', 'time' => '09:00 AM'];
        $appointment = Appointment::factory()->create(['patient_id' => $patient->patient_id, 'date' => '2026-10-22', 'status' => 'Pending']);
        foreach (['2026-10-24' => 'Weekend', '2026-10-25' => 'Weekend', '2026-11-30' => 'Holiday: Bonifacio Day', '2026-10-21' => 'Clinic Closed'] as $date => $reason) {
            $this->postJson('/api/appointments', [...$payload, 'date' => $date])->assertUnprocessable()
                ->assertJsonPath('message', "{$reason}. Appointments cannot be booked on {$date}. Please choose a working day.");
            $this->postJson("/api/appointments/{$appointment->id}/reschedule", ['date' => $date, 'time' => '09:00 AM'])->assertUnprocessable();
        }
        $this->assertSame('2026-10-22', $appointment->fresh()->date->toDateString());
        $this->postJson('/api/appointments', [...$payload, 'date' => '2026-10-23'])->assertCreated();

        $role = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $student = User::factory()->create(['role_id' => $role->id, 'patient_id' => $patient->patient_id, 'status' => 'active']);
        $this->actingAs($student, 'sanctum');
        $this->postJson('/api/me/appointments', ['type' => 'General Check-up', 'reason' => 'Check-up', 'date' => '2026-11-30', 'time' => '09:00 AM'])->assertUnprocessable();
        $slots = $this->getJson('/api/me/appointments/availability?date=2026-11-30')->assertOk()->json('data.slots');
        $this->assertNotEmpty($slots);
        foreach ($slots as $slot) {
            $this->assertFalse($slot['available']);
            $this->assertSame('Holiday: Bonifacio Day', $slot['reason']);
        }
    }

    public function test_closed_follow_up_date_does_not_complete_consultation_or_create_appointment(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $patient = Patient::factory()->create();
        $consultation = Consultation::create(['reference' => 'CON-CALENDAR', 'patient' => $patient->name, 'patient_id' => $patient->patient_id, 'date' => '2026-10-20', 'time' => '09:00 AM', 'status' => 'In Progress']);
        $this->actingAs($this->staff(), 'sanctum')->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => 'Check-up', 'diagnosis' => 'Recovering', 'treatment' => 'Rest',
            'followUpRequired' => true, 'followUpDate' => '2026-11-30', 'followUpTime' => '09:00 AM',
        ])->assertUnprocessable();
        $this->assertSame('In Progress', $consultation->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }
}
