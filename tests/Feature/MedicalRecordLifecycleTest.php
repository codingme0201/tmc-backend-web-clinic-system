<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalRecordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const FINAL_NOTES = ['chiefComplaint' => 'Fever', 'diagnosis' => 'Viral infection', 'treatment' => 'Rest and fluids'];

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);
        foreach (['appointments.view', 'appointments.create', 'appointments.approve', 'appointments.update', 'patients.create', 'patients.update', 'users.create', 'users.update', 'consultations.create', 'consultations.update', 'medical_records.view', 'medical_records.create'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => explode('.', $name)[0], 'label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }

    public function test_registration_and_profile_or_account_updates_do_not_create_medical_records(): void
    {
        $this->postJson('/api/register', ['email' => 'student-lifecycle@example.com', 'password' => 'password123', 'studentId' => '00-000001', 'firstName' => 'New', 'lastName' => 'Student'])->assertCreated();
        $student = User::where('email', 'student-lifecycle@example.com')->firstOrFail();
        $this->assertDatabaseCount('medical_records', 0);
        $this->actingAs($student, 'sanctum')->putJson('/api/me/profile', ['age' => 20])->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
        $patient = $student->patient;
        $this->actingAs($this->user('admin'), 'sanctum');
        $this->putJson("/api/patients/{$patient->id}", ['name' => 'Updated Student'])->assertOk();
        $this->putJson("/api/users/{$student->id}", ['name' => 'Updated Student Account'])->assertOk();
        $this->postJson('/api/patients', ['id' => '00-000002', 'name' => 'Registered at Desk'])->assertCreated();
        $this->postJson('/api/users', ['name' => 'Student Account', 'email' => 'desk-student@example.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'role_id' => $student->role_id, 'studentId' => '00-000003'])->assertCreated();
        $this->assertDatabaseCount('medical_records', 0);
    }

    public function test_only_final_save_of_checked_in_consultation_creates_record_and_history_once(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $admin = $this->user('admin');
        $doctor = $this->user('doctor');
        $student = $this->user('student');
        $patient = Patient::factory()->create(['patient_id' => '00-000004']);
        $student->update(['patient_id' => $patient->patient_id]);
        StaffSchedule::create(['user_id' => $doctor->id, 'date' => '2026-10-20', 'start_time' => '08:00 AM', 'end_time' => '05:00 PM', 'status' => 'Available']);
        $this->actingAs($student, 'sanctum');
        $id = $this->postJson('/api/me/appointments', ['type' => 'General Check-up', 'reason' => 'Fever', 'date' => '2026-10-20', 'time' => '09:00 AM', 'staff_id' => $doctor->id])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('medical_records', 0);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/appointments/{$id}/status", ['status' => 'Approved'])->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
        $this->postJson("/api/queue/{$id}/check-in")->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
        $this->actingAs($doctor, 'sanctum');
        $consultationId = $this->postJson("/api/queue/{$id}/serve")->assertCreated()->json('data.id');
        $this->assertDatabaseCount('medical_records', 0);
        $this->patchJson("/api/consultations/{$consultationId}", self::FINAL_NOTES)->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
        $this->postJson("/api/consultations/{$consultationId}/complete", [...self::FINAL_NOTES, 'diagnosis' => ''])->assertUnprocessable();
        $this->assertDatabaseCount('medical_records', 0);

        $this->postJson("/api/consultations/{$consultationId}/complete", self::FINAL_NOTES)->assertOk();
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertDatabaseCount('medical_record_histories', 1);
        $record = MedicalRecord::sole();
        $this->assertSame($patient->patient_id, $record->patient_id);
        $this->assertSame('Viral infection', $record->histories()->sole()->condition);
        $this->assertSame('Completed', Appointment::findOrFail($id)->status);
        $this->getJson('/api/medical-records')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.patientId', $patient->patient_id);
        $this->postJson("/api/consultations/{$consultationId}/complete", self::FINAL_NOTES)->assertStatus(409);
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertDatabaseCount('medical_record_histories', 1);

        // Booking/approving a later visit does not add clinical history to an existing record.
        $this->actingAs($admin, 'sanctum');
        $later = $this->postJson('/api/appointments', ['patient' => $patient->name, 'patient_id' => $patient->patient_id, 'type' => 'General Check-up', 'reason' => 'Recheck', 'date' => '2026-10-21', 'time' => '09:00 AM'])->assertCreated()->json('data.id');
        $this->patchJson("/api/appointments/{$later}/status", ['status' => 'Approved'])->assertOk();
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertDatabaseCount('medical_record_histories', 1);
    }

    public function test_pending_cancelled_and_no_show_bookings_never_create_medical_records(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(7, 0));
        $this->actingAs($this->user('admin'), 'sanctum');
        foreach (['Pending', 'Cancelled', 'No-Show'] as $status) {
            $patient = Patient::factory()->create();
            $id = $this->postJson('/api/appointments', ['patient' => $patient->name, 'patient_id' => $patient->patient_id, 'type' => 'General Check-up', 'reason' => 'Check-up', 'date' => '2026-10-20', 'time' => '09:00 AM'])->assertCreated()->json('data.id');
            if ($status !== 'Pending') $this->patchJson("/api/appointments/{$id}/status", ['status' => $status])->assertOk();
            $this->getJson("/api/appointments/{$id}")->assertOk()->assertJsonPath('data.status', $status);
            $this->assertDatabaseMissing('medical_records', ['patient_id' => $patient->patient_id]);
        }
        $this->getJson('/api/medical-records')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('appointments', 3);
        $this->assertDatabaseCount('medical_record_histories', 0);
    }

    public function test_unattended_or_cancelled_visit_cannot_be_completed_into_a_medical_record(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum');
        foreach (['Pending', 'Approved', 'Cancelled', 'No-Show'] as $status) {
            $patient = Patient::factory()->create();
            $appointment = Appointment::factory()->create(['patient_id' => $patient->patient_id, 'status' => $status, 'checked_in_at' => $status === 'Approved' ? null : now()]);
            $consultation = Consultation::create(['reference' => 'CONS-UNATTENDED-'.$appointment->id, 'patient' => $patient->name, 'patient_id' => $patient->patient_id, 'appointment_id' => $appointment->id, 'date' => now()->toDateString(), 'time' => '09:00 AM', 'status' => 'In Progress']);
            $this->postJson("/api/consultations/{$consultation->id}/complete", self::FINAL_NOTES)->assertUnprocessable()->assertJsonValidationErrors(['appointment']);
            $this->assertSame('In Progress', $consultation->fresh()->status);
            $this->assertSame($status, $appointment->fresh()->status);
        }
        $this->assertDatabaseCount('medical_records', 0);
        $this->assertDatabaseCount('medical_record_histories', 0);
    }

    public function test_manual_record_creation_and_standalone_consultation_do_not_bypass_check_in(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum');
        $patient = Patient::factory()->create();
        $this->postJson('/api/medical-records', ['patientId' => $patient->patient_id])->assertUnprocessable();
        $consultation = Consultation::create(['reference' => 'CONS-NO-CHECK-IN', 'patient' => $patient->name, 'patient_id' => $patient->patient_id, 'date' => now()->toDateString(), 'time' => '09:00 AM', 'status' => 'In Progress']);
        $this->postJson("/api/consultations/{$consultation->id}/complete", self::FINAL_NOTES)->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
        $this->assertDatabaseCount('medical_record_histories', 0);
    }
}
