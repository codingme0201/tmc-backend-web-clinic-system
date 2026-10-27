<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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

    public function test_clinic_staff_can_edit_only_their_own_profile(): void
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
            ->assertOk()->assertJsonPath('data.position', 'Desk');

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

    public function test_front_desk_cannot_edit_other_accounts_even_with_stale_management_permissions(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['users.update', 'users.delete', 'users.create', 'schedules.view']);
        $other = $this->userWithRole('nurse');
        $other->staffProfile()->create(['position' => 'School Nurse', 'background' => 'Original background']);
        $originalPassword = $other->password;
        $this->actingAs($frontDesk, 'sanctum');
        $this->putJson('/api/me/staff-profile', ['position' => 'Reception', 'background' => 'My background', 'contactNumber' => '0917-123-4567'])
            ->assertOk()->assertJsonPath('data.position', 'Reception')->assertJsonPath('data.background', 'My background');
        $this->putJson('/api/me/staff-profile', ['user_id' => $other->id, 'background' => 'Changed'])
            ->assertForbidden();
        $this->putJson('/api/users/'.$other->id, ['name' => 'Changed'])->assertForbidden();
        $this->patchJson('/api/users/'.$other->id.'/status', ['status' => 'inactive'])->assertForbidden();
        $this->patchJson('/api/users/'.$other->id.'/role', ['role_id' => $frontDesk->role_id])->assertForbidden();
        $this->postJson('/api/users/'.$other->id.'/reset-password', [])->assertForbidden();
        $this->postJson('/api/clinic-staff/'.$other->id.'/verify', ['status' => 'Verified'])->assertForbidden();
        $this->deleteJson('/api/users/'.$other->id)->assertForbidden();
        $this->assertSame('Original background', $other->staffProfile()->value('background'));
        $this->assertSame($originalPassword, $other->fresh()->password);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame('My background', $frontDesk->staffProfile()->value('background'));
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

    public function test_patient_status_changes_are_shared_by_registry_views_and_live_sync(): void
    {
        $patient = Patient::factory()->create(['status' => 'Active']);
        $this->actingAs($this->userWithRole('admin', ['patients.view', 'patients.update']), 'sanctum');

        foreach (['Inactive', 'Active'] as $status) {
            $before = $this->getJson('/api/sync')->assertOk()->json('data.patients');
            $this->patchJson("/api/patients/{$patient->id}/status", ['status' => $status])->assertOk()
                ->assertJsonPath('data.id', $patient->id)
                ->assertJsonPath('data.status', $status);
            $this->getJson('/api/patients')->assertOk()
                ->assertJsonFragment(['id' => $patient->id, 'status' => $status]);
            $this->getJson("/api/patients/{$patient->id}")->assertOk()
                ->assertJsonPath('data.status', $status);
            $this->assertNotSame($before, $this->getJson('/api/sync')->assertOk()->json('data.patients'));
            $this->assertDatabaseHas('patients', ['id' => $patient->id, 'status' => $status]);
        }
    }

    public function test_patient_registry_exposes_registration_dates_without_using_last_update_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $this->actingAs($this->userWithRole('admin', ['patients.create', 'patients.view', 'patients.update']), 'sanctum');
        $oldPatient = Patient::factory()->create([
            'created_at' => now()->subDays(7),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/patients/{$oldPatient->id}")->assertOk()
            ->assertJsonPath('data.registeredOn', '2026-10-15');
        $this->putJson("/api/patients/{$oldPatient->id}", ['name' => 'Updated Older Patient'])->assertOk()
            ->assertJsonPath('data.registeredOn', '2026-10-15');
        $newId = $this->postJson('/api/patients', ['id' => '26-000001', 'name' => 'New Patient Today'])->assertCreated()
            ->assertJsonPath('data.registeredOn', '2026-10-22')->json('data.id');
        $this->getJson('/api/patients')->assertOk()
            ->assertJsonFragment(['id' => $oldPatient->id, 'registeredOn' => '2026-10-15'])
            ->assertJsonFragment(['id' => $newId, 'registeredOn' => '2026-10-22']);
    }

    public function test_registry_creation_saves_age_and_keeps_related_profiles_in_sync(): void
    {
        $admin = $this->userWithRole('admin', ['patients.create', 'patients.view', 'patients.update', 'medical_records.view']);
        $this->actingAs($admin, 'sanctum');
        $id = $this->postJson('/api/patients', [
            'id' => '24-000020', 'name' => 'Registry Student', 'type' => 'Student', 'age' => '20', 'sex' => 'Female',
        ])->assertCreated()->assertJsonPath('data.age', 20)->assertJsonPath('data.sex', 'Female')->json('data.id');

        $this->assertDatabaseHas('patients', ['id' => $id, 'age' => 20]);
        $this->assertDatabaseMissing('medical_records', ['patient_id' => '24-000020']);
        Patient::findOrFail($id)->ensureMedicalRecord(); // Existing clinical record fixture for demographic sync.
        $this->assertDatabaseHas('medical_records', ['patient_id' => '24-000020', 'age' => 20, 'sex' => 'Female']);
        $this->getJson("/api/patients/{$id}")->assertOk()->assertJsonPath('data.age', 20);
        $this->getJson("/api/patients/{$id}/medical-information")->assertOk()->assertJsonPath('data.age', 20);
        $this->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.age', 20)->assertJsonPath('data.0.studentProfile.age', 20)
            ->assertJsonPath('data.0.sex', 'Female')->assertJsonPath('data.0.studentProfile.sex', 'Female');

        $this->putJson("/api/patients/{$id}", ['age' => 21, 'sex' => 'Other'])->assertOk()->assertJsonPath('data.age', 21);
        $this->assertDatabaseHas('medical_records', ['patient_id' => '24-000020', 'age' => 21, 'sex' => 'Other']);
        $this->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.age', 21)->assertJsonPath('data.0.studentProfile.age', 21);

        $student = $this->userWithRole('student', [], ['patient_id' => '24-000020']);
        $this->actingAs($student, 'sanctum');
        $this->getJson('/api/me/profile')->assertOk()->assertJsonPath('data.age', 21)->assertJsonPath('data.sex', 'Other');
        $this->getJson('/api/me/medical-records')->assertOk()->assertJsonPath('data.age', 21)->assertJsonPath('data.sex', 'Other');
    }

    public function test_registry_type_is_student_and_sex_uses_the_dropdown_values(): void
    {
        $this->actingAs($this->userWithRole('admin', ['patients.create']), 'sanctum');
        $this->postJson('/api/patients', ['name' => 'Invalid Type', 'type' => 'Employee'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->postJson('/api/patients', ['name' => 'Invalid Sex', 'sex' => 'Invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sex']);
        foreach (['Female', 'Male', 'Other'] as $index => $sex) {
            $this->postJson('/api/patients', ['id' => '26-00000'.$index, 'name' => "Student {$sex}", 'sex' => $sex])
                ->assertCreated()->assertJsonPath('data.type', 'Student')->assertJsonPath('data.sex', $sex);
        }
    }

    public function test_registry_creation_validates_age_and_allows_it_to_be_left_blank(): void
    {
        $this->actingAs($this->userWithRole('admin', ['patients.create']), 'sanctum');
        $payload = ['id' => '26-000001', 'name' => 'Age Validation', 'type' => 'Student'];
        foreach (['twenty', '20.5', 0, -1, 121] as $age) {
            $this->postJson('/api/patients', [...$payload, 'age' => $age])
                ->assertUnprocessable()->assertJsonValidationErrors(['age']);
        }
        $this->assertDatabaseCount('patients', 0);
        $this->postJson('/api/patients', [...$payload, 'age' => null])
            ->assertCreated()->assertJsonPath('data.age', null);
    }

    public function test_medical_record_shows_the_age_and_details_the_student_entered(): void
    {
        $student = $this->student(['name' => 'Age Student']);
        $this->assertSame(0, (int) MedicalRecord::where('patient_id', $student->patient_id)->value('age'));

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/me/profile', [
                'firstName' => 'Age', 'lastName' => 'Student', 'age' => 20,
                'address' => 'Tagum Norte', 'emergencyContactName' => 'Mom', 'emergencyContactPhone' => '0917-123-4567',
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

        $this->putJson("/api/roles/{$doctorRole->id}/permissions", ['permissions' => [$reports->id, $audit->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions']);
        $this->assertFalse($doctorRole->permissions()->where('name', 'audit_logs.view')->exists());

        $this->putJson("/api/roles/{$doctorRole->id}/permissions", ['permissions' => [$reports->id]])->assertOk();
        $this->assertTrue($doctorRole->permissions()->where('name', 'reports.view')->exists());
    }

    // --- Audit logs ---------------------------------------------------------------------------

    public function test_account_and_role_changes_are_recorded_in_the_audit_log(): void
    {
        $admin = $this->userWithRole('admin', ['users.update', 'users.delete', 'roles.update', 'roles.assign_permissions', 'audit_logs.view']);
        $doctor = $this->userWithRole('doctor');
        $doctorRole = $doctor->role;
        $this->actingAs($admin, 'sanctum');

        $this->patchJson("/api/users/{$doctor->id}/status", ['status' => 'inactive'])->assertOk();
        $this->postJson("/api/users/{$doctor->id}/reset-password", ['password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertOk();
        $reports = Permission::firstOrCreate(['name' => 'reports.view'], ['module' => 'Reports', 'label' => 'View Reports']);
        $this->putJson("/api/roles/{$doctorRole->id}/permissions", ['permissions' => [$reports->id]])->assertOk();

        $actions = ActivityLog::where('user_id', $admin->id)->pluck('action')->all();
        $this->assertContains("Deactivated account of {$doctor->name} (doctor)", $actions);
        $this->assertContains("Reset the password of {$doctor->name} ({$doctor->email})", $actions);
        $this->assertContains('Updated permissions of role doctor', $actions);

        $logs = $this->getJson('/api/activity-logs?limit=2')->assertOk()->json('data');
        $this->assertCount(2, $logs);
        $this->assertSame('Updated permissions of role doctor', $logs[0]['action']);

        $this->actingAs($this->userWithRole('nurse', ['audit_logs.view']), 'sanctum');
        $this->getJson('/api/activity-logs')->assertForbidden();
    }

    // --- Live sync ----------------------------------------------------------------------------

    public function test_sync_versions_change_when_staff_act_so_clients_refresh(): void
    {
        $this->getJson('/api/sync')->assertUnauthorized();

        $student = $this->student();
        $admin = $this->userWithRole('admin', ['notifications.send']);

        $this->actingAs($student, 'sanctum');
        $before = $this->getJson('/api/sync')->assertOk()->json('data');
        $this->assertArrayHasKey('notifications', $before);
        $this->assertArrayHasKey('appointments', $before);

        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/notifications', ['user_id' => $student->id, 'title' => 'Hi', 'message' => 'Live'])->assertCreated();

        $this->actingAs($student, 'sanctum');
        $after = $this->getJson('/api/sync')->assertOk()->json('data');
        $this->assertNotSame($before['notifications'], $after['notifications']);
        $this->assertSame($before['prescriptions'], $after['prescriptions']);

        $this->patchJson('/api/me/notifications/read-all')->assertOk();
        $this->assertNotSame($after['notifications'], $this->getJson('/api/sync')->json('data.notifications'));
    }
}
