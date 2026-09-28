<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClinicEvent;
use App\Models\Consultation;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\UnavailableSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers the security fixes and new endpoints for the student self-service
 * API, medical records, patients, users and password reset.
 */
class EndpointHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function roleWith(string $name, array $permissions = []): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['description' => ucfirst($name)]);
        $ids = collect($permissions)->map(function (string $permission) {
            [$module] = explode('.', $permission, 2);

            return Permission::firstOrCreate(['name' => $permission], ['module' => $module, 'label' => $permission])->id;
        })->all();
        $role->permissions()->syncWithoutDetaching($ids);

        return $role;
    }

    private function staff(array $permissions = [], string $role = 'admin'): User
    {
        return User::factory()->create(['role_id' => $this->roleWith($role, $permissions)->id, 'status' => 'active']);
    }

    private function student(?Patient $patient = null): User
    {
        $patient ??= Patient::factory()->create();

        return User::factory()->create([
            'role_id' => $this->roleWith('student')->id,
            'patient_id' => $patient->patient_id,
            'status' => 'active',
        ]);
    }

    private function futureWeekday(): string
    {
        $date = now()->addDays(7);
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    // --- Registration & profile ----------------------------------------------

    public function test_register_rejects_student_id_that_already_has_a_record(): void
    {
        $existing = Patient::factory()->create(['patient_id' => '24-000111']);

        $this->postJson('/api/register', [
            'email' => 'attacker@example.com',
            'password' => 'password123',
            'studentId' => $existing->patient_id,
            'firstName' => 'Mal',
            'lastName' => 'Lory',
        ])->assertUnprocessable()->assertJsonValidationErrors(['studentId']);

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_register_creates_patient_and_empty_medical_record(): void
    {
        $this->postJson('/api/register', [
            'email' => 'new@example.com',
            'password' => 'password123',
            'studentId' => '24-999001',
            'firstName' => 'New',
            'lastName' => 'Student',
        ])->assertCreated();

        $this->assertDatabaseHas('patients', ['patient_id' => '24-999001']);
        $this->assertDatabaseHas('medical_records', ['patient_id' => '24-999001']);
    }

    public function test_register_requires_eight_character_password(): void
    {
        $this->postJson('/api/register', [
            'email' => 'short@example.com',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_student_cannot_change_a_real_student_id(): void
    {
        $user = $this->student(Patient::factory()->create(['patient_id' => '24-000222']));
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/me/profile', ['studentId' => '24-000333'])
            ->assertUnprocessable()->assertJsonValidationErrors(['studentId']);

        $this->assertDatabaseHas('patients', ['patient_id' => '24-000222']);
    }

    public function test_student_can_replace_placeholder_id_and_record_follows(): void
    {
        $patient = Patient::factory()->create(['patient_id' => 'STU-26-000123']);
        $patient->ensureMedicalRecord();
        $user = $this->student($patient);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/me/profile', ['studentId' => '24-555555'])
            ->assertOk()->assertJsonPath('data.patientId', '24-555555');

        $this->assertSame('24-555555', $user->fresh()->patient_id);
        $this->assertDatabaseHas('medical_records', ['patient_id' => '24-555555']);
    }

    // --- Role boundaries -------------------------------------------------------

    public function test_staff_cannot_use_student_self_service_routes(): void
    {
        $this->actingAs($this->staff(), 'sanctum');

        $this->getJson('/api/me/profile')->assertForbidden();
        $this->putJson('/api/me/profile', ['firstName' => 'X'])->assertForbidden();
    }

    public function test_change_password_is_available_to_staff_and_revokes_other_tokens(): void
    {
        $user = $this->staff();
        $user->update(['password' => 'oldpassword1']);
        $other = $user->createToken('other-device');
        $current = $user->createToken('this-device')->plainTextToken;

        $this->withToken($current)->postJson('/api/me/change-password', [
            'currentPassword' => 'oldpassword1',
            'newPassword' => 'newpassword1',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_students_cannot_write_audit_log_entries(): void
    {
        $this->actingAs($this->student(), 'sanctum');
        $this->postJson('/api/activity-logs', ['action' => 'Fake entry'])->assertForbidden();

        $this->actingAs($this->staff(), 'sanctum');
        $this->postJson('/api/activity-logs', ['action' => 'Real entry'])->assertCreated();
    }

    // --- Certificates ------------------------------------------------------------

    public function test_certificate_request_ignores_consultations_matched_only_by_name(): void
    {
        $patient = Patient::factory()->create(['name' => 'Juan Cruz']);
        $other = Patient::factory()->create(['name' => 'Juan Cruz']);
        Consultation::create([
            'reference' => 'CON-2026-900', 'date' => now()->toDateString(), 'time' => '09:00 AM',
            'patient' => 'Juan Cruz', 'patient_id' => $other->patient_id, 'status' => 'Completed',
        ]);

        $this->actingAs($this->student($patient), 'sanctum');
        $this->postJson('/api/me/medical-certificates', ['purpose' => 'School excuse'])
            ->assertUnprocessable()->assertJsonValidationErrors(['consultation']);
    }

    // --- Appointments --------------------------------------------------------------

    public function test_timed_afternoon_block_is_enforced(): void
    {
        $date = $this->futureWeekday();
        UnavailableSchedule::create([
            'start_date' => $date, 'end_date' => $date, 'all_day' => false,
            'start_time' => '13:00', 'end_time' => '15:00', 'reason' => 'Seminar',
            'created_by' => $this->staff()->id,
        ]);
        $this->actingAs($this->student(), 'sanctum');

        $this->postJson('/api/me/appointments', [
            'type' => 'Check-up', 'reason' => 'Headache', 'date' => $date, 'time' => '01:30 PM',
        ])->assertUnprocessable();

        $this->postJson('/api/me/appointments', [
            'type' => 'Check-up', 'reason' => 'Headache', 'date' => $date, 'time' => '08:00 AM',
        ])->assertCreated();
    }

    public function test_online_booking_toggle_and_daily_cap_are_enforced(): void
    {
        $date = $this->futureWeekday();
        $this->actingAs($this->student(), 'sanctum');
        $payload = ['type' => 'Check-up', 'reason' => 'Fever', 'date' => $date, 'time' => '09:00 AM'];

        SystemSetting::getInstance()->update(['online_appointments_enabled' => false]);
        $this->postJson('/api/me/appointments', $payload)->assertUnprocessable();

        SystemSetting::getInstance()->update(['online_appointments_enabled' => true, 'max_daily_appointments' => 1]);
        Appointment::create([
            'reference' => 'APT-2026-500', 'patient' => 'Someone', 'patient_id' => 'X-1', 'type' => 'Check-up',
            'reason' => 'x', 'date' => $date, 'time' => '10:00 AM', 'staff' => '', 'status' => 'Pending', 'requested_on' => now()->toDateString(),
        ]);
        $this->postJson('/api/me/appointments', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', "The clinic has reached its maximum number of appointments for {$date}. Please choose another date.");
    }

    public function test_appointment_options_and_availability(): void
    {
        $doctor = User::factory()->create(['role_id' => $this->roleWith('doctor')->id, 'status' => 'active', 'name' => 'Dr. Real']);
        $date = $this->futureWeekday();
        Appointment::create([
            'reference' => 'APT-2026-600', 'patient' => 'Other', 'patient_id' => 'X-2', 'type' => 'Check-up',
            'reason' => 'x', 'date' => $date, 'time' => '09:00 AM', 'staff' => 'Dr. Real', 'staff_id' => $doctor->id,
            'status' => 'Approved', 'requested_on' => now()->toDateString(),
        ]);
        $this->actingAs($this->student(), 'sanctum');

        $this->getJson('/api/me/appointment-options')
            ->assertOk()
            ->assertJsonPath('data.doctors.0.id', $doctor->id)
            ->assertJsonPath('data.timeSlots', Appointment::TIME_SLOTS);

        $slots = collect($this->getJson("/api/me/appointments/availability?date={$date}&staff_id={$doctor->id}")
            ->assertOk()->json('data.slots'))->keyBy('time');

        $this->assertFalse($slots['09:00 AM']['available']);
        $this->assertSame('booked', $slots['09:00 AM']['reason']);
        $this->assertTrue($slots['08:00 AM']['available']);
    }

    public function test_booking_with_staff_id_records_the_doctor(): void
    {
        $doctor = User::factory()->create(['role_id' => $this->roleWith('doctor')->id, 'status' => 'active']);
        $this->actingAs($this->student(), 'sanctum');

        $this->postJson('/api/me/appointments', [
            'type' => 'Check-up', 'reason' => 'Cough', 'date' => $this->futureWeekday(),
            'time' => '10:00 AM', 'staff_id' => $doctor->id,
        ])->assertCreated()->assertJsonPath('data.staff', $doctor->name);

        $this->assertDatabaseHas('appointments', ['staff_id' => $doctor->id]);
    }

    // --- Public student endpoints ----------------------------------------------------

    public function test_student_clinic_endpoints_return_public_data_only(): void
    {
        ClinicEvent::create(['title' => 'Blood drive', 'start_date' => now()->addDay()->toDateString(), 'status' => 'Scheduled', 'date' => '']);
        ClinicEvent::create(['title' => 'Cancelled talk', 'start_date' => now()->addDay()->toDateString(), 'status' => 'Cancelled', 'date' => '']);
        $this->actingAs($this->student(), 'sanctum');

        $info = $this->getJson('/api/me/clinic-information')->assertOk()->json('data');
        $this->assertArrayNotHasKey('notificationSettings', $info);
        $this->assertArrayNotHasKey('maxDailyAppointments', $info);

        $titles = collect($this->getJson('/api/me/clinic-activities')->assertOk()->json('data'))->pluck('title');
        $this->assertContains('Blood drive', $titles);
        $this->assertNotContains('Cancelled talk', $titles);

        $this->getJson('/api/me/notifications/unread-count')->assertOk()->assertJsonPath('count', 0);
    }

    // --- Password reset ---------------------------------------------------------------

    public function test_password_reset_with_emailed_code(): void
    {
        $user = $this->student();
        $user->createToken('old');

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        // Replace the random code with a known one to complete the flow.
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['token' => Hash::make('123456')]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'code' => '000000',
            'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
        ])->assertUnprocessable();

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'code' => '123456',
            'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_response_does_not_reveal_unknown_emails(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists with this email address, a password reset code has been sent.');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    // --- Medical records -----------------------------------------------------------------

    public function test_staff_can_create_record_and_manage_medications_and_history(): void
    {
        $patient = Patient::factory()->create();
        $this->actingAs($this->staff(['medical_records.view', 'medical_records.create', 'medical_records.update']), 'sanctum');

        $recordId = $this->postJson('/api/medical-records', ['patientId' => $patient->patient_id])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/medical-records', ['patientId' => $patient->patient_id])->assertStatus(409);

        $medId = $this->postJson("/api/medical-records/{$recordId}/medications", ['name' => 'Paracetamol', 'dosage' => '500 mg'])
            ->assertOk()->json('data.medications.0.id');
        $this->patchJson("/api/medical-records/{$recordId}/medications/{$medId}", ['status' => 'Completed'])
            ->assertOk()->assertJsonPath('data.medications.0.status', 'Completed');

        $historyId = $this->postJson("/api/medical-records/{$recordId}/histories", ['condition' => 'Appendectomy', 'date' => '2020-05-01'])
            ->assertOk()->json('data.medicalHistory.0.id');
        $this->deleteJson("/api/medical-records/{$recordId}/histories/{$historyId}")
            ->assertOk()->assertJsonCount(0, 'data.medicalHistory');
        $this->deleteJson("/api/medical-records/{$recordId}/medications/{$medId}")
            ->assertOk()->assertJsonCount(0, 'data.medications');

        $this->getJson("/api/medical-records/{$recordId}")->assertOk()->assertJsonPath('data.patientId', $patient->patient_id);
    }

    public function test_completing_a_consultation_adds_medical_history(): void
    {
        $patient = Patient::factory()->create();
        $consultation = Consultation::create([
            'reference' => 'CON-2026-777', 'date' => now()->toDateString(), 'time' => '09:00 AM',
            'patient' => $patient->name, 'patient_id' => $patient->patient_id, 'status' => 'In Progress',
        ]);
        $this->actingAs($this->staff(['consultations.update']), 'sanctum');

        $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => 'Fever', 'diagnosis' => 'Viral infection', 'treatment' => 'Rest and fluids',
        ])->assertOk();

        $record = MedicalRecord::where('patient_id', $patient->patient_id)->with('histories')->first();
        $this->assertNotNull($record);
        $this->assertSame('Viral infection', $record->histories->first()->condition);
    }

    // --- Patients & users --------------------------------------------------------------------

    public function test_staff_can_update_patient_details_and_record_is_synced(): void
    {
        $patient = Patient::factory()->create();
        $patient->ensureMedicalRecord();
        $this->actingAs($this->staff(['patients.update']), 'sanctum');

        $this->putJson("/api/patients/{$patient->id}", ['name' => 'Renamed Person', 'age' => 21])
            ->assertOk()->assertJsonPath('data.name', 'Renamed Person');

        $this->assertDatabaseHas('medical_records', ['patient_id' => $patient->patient_id, 'name' => 'Renamed Person', 'age' => 21]);
    }

    public function test_register_patient_without_id_generates_one(): void
    {
        $this->actingAs($this->staff(['patients.create']), 'sanctum');

        $id = $this->postJson('/api/patients', ['name' => 'Walk In', 'type' => 'Student'])
            ->assertCreated()->json('data.patientId');

        $this->assertMatchesRegularExpression('/^STU-\d{2}-\d{6}$/', $id);
        $this->assertDatabaseHas('medical_records', ['patient_id' => $id]);
    }

    public function test_admin_can_delete_users_but_not_themselves(): void
    {
        $admin = $this->staff(['users.delete']);
        $target = $this->student();
        $target->createToken('device');
        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(409);
        $this->deleteJson("/api/users/{$target->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertSame(0, $target->tokens()->count());
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'password'])->assertUnauthorized();
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->staff(['users.update']);
        $target = $this->student();
        $target->createToken('device');
        $this->actingAs($admin, 'sanctum');

        $this->patchJson("/api/users/{$target->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_editing_a_students_id_renames_their_existing_records(): void
    {
        $patient = Patient::factory()->create(['patient_id' => '24-100100']);
        $student = $this->student($patient);
        Appointment::create([
            'reference' => 'APT-2026-700', 'patient' => $patient->name, 'patient_id' => '24-100100', 'type' => 'Check-up',
            'reason' => 'x', 'date' => $this->futureWeekday(), 'time' => '09:00 AM', 'staff' => '', 'status' => 'Pending', 'requested_on' => now()->toDateString(),
        ]);
        $this->actingAs($this->staff(['users.update']), 'sanctum');

        $this->putJson("/api/users/{$student->id}", ['studentId' => '24-100200'])->assertOk();

        $this->assertSame(1, Patient::where('patient_id', '24-100200')->count());
        $this->assertSame(0, Patient::where('patient_id', '24-100100')->count());
        $this->assertDatabaseHas('appointments', ['reference' => 'APT-2026-700', 'patient_id' => '24-100200']);
        $this->assertSame('24-100200', $student->fresh()->patient_id);
    }
}
