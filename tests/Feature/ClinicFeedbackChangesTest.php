<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\MedicalRecord;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff profiles (self-edit only, visible to students), consultation
 * deletion, notification recipients, prescriber selection, medical record
 * demographics, single admin account and locked admin permissions.
 */
class ClinicFeedbackChangesTest extends TestCase
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

    private function userWithRole(string $role, array $permissions = [], array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $this->roleWith($role, $permissions)->id,
            'status' => 'active',
        ], $attributes));
    }

    private function student(array $patientAttributes = []): User
    {
        $patient = Patient::create(array_merge([
            'patient_id' => '24-'.random_int(100000, 999999),
            'name' => 'Stu Dent',
            'type' => 'Student',
            'course_dept' => 'General',
            'status' => 'Active',
        ], $patientAttributes));
        $patient->ensureMedicalRecord();

        return $this->userWithRole('student', [], ['patient_id' => $patient->patient_id, 'name' => $patient->name]);
    }

    // --- Staff profiles ------------------------------------------------------------------------

    public function test_only_the_doctor_or_nurse_can_edit_their_own_profile(): void
    {
        $doctor = $this->userWithRole('doctor');
        $admin = $this->userWithRole('admin', ['users.update', 'schedules.view']);
        $frontDesk = $this->userWithRole('front_desk');

        $this->actingAs($doctor, 'sanctum')
            ->putJson('/api/me/staff-profile', ['background' => 'MD, 10 years in school health.', 'specialization' => 'Family Medicine'])
            ->assertOk()
            ->assertJsonPath('data.background', 'MD, 10 years in school health.');

        // Administrators can no longer edit someone else's profile.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/clinic-staff/{$doctor->id}/profile", ['background' => 'Changed by admin'])
            ->assertNotFound();
        $this->actingAs($frontDesk, 'sanctum')
            ->putJson('/api/me/staff-profile', ['position' => 'Desk'])
            ->assertForbidden();

        $this->assertSame('MD, 10 years in school health.', $doctor->staffProfile()->value('background'));
    }

    public function test_students_can_view_doctor_and_nurse_profiles(): void
    {
        $doctor = $this->userWithRole('doctor', [], ['name' => 'Dr. Visible']);
        $doctor->staffProfile()->create(['background' => 'Pediatrics residency', 'specialization' => 'Pediatrics', 'credential_status' => 'Verified']);
        $nurse = $this->userWithRole('nurse', [], ['name' => 'Nurse Visible']);
        $frontDesk = $this->userWithRole('front_desk');
        $student = $this->student();

        $this->actingAs($student, 'sanctum');
        $list = collect($this->getJson('/api/me/clinic-staff')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$doctor->id, $nurse->id], $list->pluck('id')->all());
        $this->assertSame('Pediatrics residency', $list->firstWhere('id', $doctor->id)['background']);

        $this->getJson("/api/me/clinic-staff/{$doctor->id}")->assertOk()->assertJsonPath('data.specialty', 'Pediatrics');
        $this->getJson("/api/me/clinic-staff/{$frontDesk->id}")->assertNotFound();
        $this->putJson('/api/me/staff-profile', ['background' => 'x'])->assertForbidden();
    }

    // --- Consultations -------------------------------------------------------------------------

    public function test_consultations_can_be_deleted_with_permission(): void
    {
        $consultation = Consultation::create([
            'reference' => 'CON-2026-901', 'patient' => 'Del Patient', 'patient_id' => '24-000901',
            'date' => now()->toDateString(), 'time' => '09:00 AM', 'status' => 'Completed',
        ]);

        $this->actingAs($this->userWithRole('nurse', ['consultations.view']), 'sanctum')
            ->deleteJson("/api/consultations/{$consultation->id}")
            ->assertForbidden();

        $this->actingAs($this->userWithRole('doctor', ['consultations.view', 'consultations.delete']), 'sanctum')
            ->deleteJson("/api/consultations/{$consultation->id}")
            ->assertOk();

        $this->assertDatabaseMissing('consultations', ['id' => $consultation->id]);
    }

    // --- Notifications -------------------------------------------------------------------------

    public function test_selected_user_receives_the_notification_and_sender_sees_it(): void
    {
        $doctor = $this->userWithRole('doctor', ['notifications.view', 'notifications.send']);
        $student = $this->student(['name' => 'Notified Student']);
        $inactive = $this->userWithRole('nurse', [], ['status' => 'inactive']);

        $this->actingAs($doctor, 'sanctum');
        $recipients = collect($this->getJson('/api/notifications/recipients')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($student->id, $recipients);
        $this->assertNotContains($doctor->id, $recipients);
        $this->assertNotContains($inactive->id, $recipients);

        $this->postJson('/api/notifications', ['user_id' => $inactive->id, 'title' => 'x', 'message' => 'y'])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);

        $this->postJson('/api/notifications', ['user_id' => $student->id, 'title' => 'Results ready', 'message' => 'Please visit the clinic.'])
            ->assertCreated()
            ->assertJsonPath('data.recipient.id', $student->id)
            ->assertJsonPath('data.senderId', $doctor->id);

        $this->getJson('/api/notifications/sent')->assertOk()->assertJsonPath('data.0.recipient.name', 'Notified Student');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Results ready');
        $this->assertSame(1, Notification::where('user_id', $student->id)->count());
    }

    // --- Prescriptions -------------------------------------------------------------------------

    public function test_prescriber_is_chosen_from_doctor_accounts(): void
    {
        $doctor = $this->userWithRole('doctor', ['prescriptions.view', 'prescriptions.create', 'prescriptions.update'], ['name' => 'Dr. Prescriber']);
        $nurse = $this->userWithRole('nurse');
        $this->actingAs($doctor, 'sanctum');

        $payload = [
            'patient' => 'Rx Patient', 'patient_id' => '24-000777',
            'medications' => [['medicine_name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'Every 6 hours']],
        ];

        $this->postJson('/api/prescriptions', [...$payload, 'prescribed_by_id' => $nurse->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['prescribed_by_id']);

        $this->postJson('/api/prescriptions', [...$payload, 'prescribed_by_id' => $doctor->id])
            ->assertCreated()
            ->assertJsonPath('data.prescribedById', $doctor->id)
            ->assertJsonPath('data.prescribedBy', 'Dr. Prescriber');
    }

    // --- Medical records -----------------------------------------------------------------------

    public function test_medical_record_shows_the_age_and_details_the_student_entered(): void
    {
        $student = $this->student(['name' => 'Age Student']);
        $this->assertSame(0, (int) MedicalRecord::where('patient_id', $student->patient_id)->value('age'));

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/me/profile', [
                'firstName' => 'Age', 'lastName' => 'Student', 'age' => 20,
                'address' => 'Tagum Norte', 'emergencyContactName' => 'Mom', 'emergencyContactPhone' => '0917',
            ])->assertOk();

        $this->getJson('/api/me/medical-records')
            ->assertOk()
            ->assertJsonPath('data.age', 20)
            ->assertJsonPath('data.studentProfile.address', 'Tagum Norte')
            ->assertJsonPath('data.studentProfile.emergencyContactName', 'Mom');
        $this->assertSame(20, (int) MedicalRecord::where('patient_id', $student->patient_id)->value('age'));

        $this->actingAs($this->userWithRole('doctor', ['medical_records.view']), 'sanctum')
            ->getJson('/api/medical-records')
            ->assertOk()
            ->assertJsonPath('data.0.age', 20)
            ->assertJsonPath('data.0.studentProfile.age', 20);
    }

    // --- Single admin & locked permissions -----------------------------------------------------

    public function test_only_one_admin_and_admin_account_cannot_be_toggled(): void
    {
        $admin = $this->userWithRole('admin', ['users.view', 'users.create', 'users.update', 'users.delete']);
        $adminRole = Role::where('name', 'admin')->first();
        $doctor = $this->userWithRole('doctor');
        $student = $this->student();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/users', [
            'name' => 'Second Admin', 'email' => 'second@tmc.edu.ph',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role_id' => $adminRole->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);

        $this->patchJson("/api/users/{$doctor->id}/role", ['role_id' => $adminRole->id])->assertUnprocessable();
        $this->patchJson("/api/users/{$admin->id}/status", ['status' => 'inactive'])->assertStatus(409);
        $this->patchJson("/api/users/{$admin->id}/role", ['role_id' => $doctor->role_id])->assertStatus(409);
        $this->patchJson("/api/users/{$student->id}/status", ['status' => 'inactive'])->assertStatus(409);

        $this->patchJson("/api/users/{$doctor->id}/status", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchJson("/api/users/{$doctor->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame(1, User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->count());
    }

    public function test_admin_role_permissions_are_locked_and_audit_logs_stay_admin_only(): void
    {
        $admin = $this->userWithRole('admin', ['roles.view', 'roles.update', 'roles.assign_permissions']);
        $adminRole = Role::where('name', 'admin')->first();
        $doctorRole = $this->roleWith('doctor');
        $audit = Permission::firstOrCreate(['name' => 'audit_logs.view'], ['module' => 'Audit Logs', 'label' => 'View Audit Logs']);
        $reports = Permission::firstOrCreate(['name' => 'reports.view'], ['module' => 'Reports', 'label' => 'View Reports']);
        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/roles/{$adminRole->id}/permissions", ['permissions' => [$reports->id]])->assertStatus(409);
        $this->assertTrue($adminRole->permissions()->where('name', 'roles.view')->exists());

        $this->putJson("/api/roles/{$doctorRole->id}/permissions", ['permissions' => [$reports->id, $audit->id]])->assertOk();
        $this->assertTrue($doctorRole->permissions()->where('name', 'reports.view')->exists());
        $this->assertFalse($doctorRole->permissions()->where('name', 'audit_logs.view')->exists());
    }
}
