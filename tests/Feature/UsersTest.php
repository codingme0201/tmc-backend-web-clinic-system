<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $permissions = collect(['users.view', 'users.create', 'users.update'])->map(function ($name) {
            return Permission::factory()->create(['name' => $name, 'module' => 'users'])->id;
        })->all();
        $role->permissions()->sync($permissions);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_role_changes_stay_within_the_existing_account_category(): void
    {
        $admin = $this->adminUser();
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['description' => 'Doctor']);
        $nurseRole = Role::firstOrCreate(['name' => 'nurse'], ['description' => 'Nurse']);
        $patient = Patient::factory()->create();
        $student = User::factory()->create(['role_id' => $studentRole->id, 'patient_id' => $patient->patient_id]);
        $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
        $patientBefore = $patient->fresh()->getAttributes();
        $permissionsBefore = Role::with('permissions')->get()->mapWithKeys(fn ($role) => [$role->id => $role->permissions->modelKeys()])->all();
        $this->actingAs($admin, 'sanctum');

        foreach ([$doctorRole, $nurseRole] as $role) {
            $this->patchJson("/api/users/{$student->id}/role", ['role_id' => $role->id])
                ->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
        }
        $this->patchJson("/api/users/{$doctor->id}/role", ['role_id' => $studentRole->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
        $this->assertSame($studentRole->id, $student->fresh()->role_id);
        $this->assertSame($patientBefore, $patient->fresh()->getAttributes());

        // Authorized changes within staff keep working without modifying role permissions.
        $this->patchJson("/api/users/{$doctor->id}/role", ['role_id' => $nurseRole->id])
            ->assertOk()->assertJsonPath('data.role.name', 'nurse');
        $this->assertSame($permissionsBefore, Role::with('permissions')->get()->mapWithKeys(fn ($role) => [$role->id => $role->permissions->modelKeys()])->all());
    }

    public function test_profile_edits_cannot_silently_change_roles(): void
    {
        $admin = $this->adminUser();
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['description' => 'Doctor']);
        $patient = Patient::factory()->create(['sex' => 'Female', 'nationality' => 'Existing Nationality']);
        $student = User::factory()->create(['role_id' => $studentRole->id, 'patient_id' => $patient->patient_id]);
        $this->actingAs($admin, 'sanctum');
        $this->putJson("/api/users/{$student->id}", ['name' => 'Unexpected Change', 'role_id' => $doctorRole->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
        $this->assertNotSame('Unexpected Change', $student->fresh()->name);
        $this->putJson("/api/users/{$student->id}", ['name' => 'Updated Student'])->assertOk()
            ->assertJsonPath('data.role.name', 'student')
            ->assertJsonPath('data.patient.sex', 'Female')
            ->assertJsonPath('data.patient.nationality', 'Existing Nationality');
        $this->assertSame($studentRole->id, $student->fresh()->role_id);
    }

    public function test_role_assignment_requires_existing_update_permission(): void
    {
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['description' => 'Doctor']);
        $nurseRole = Role::firstOrCreate(['name' => 'nurse'], ['description' => 'Nurse']);
        $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
        $this->actingAs($doctor, 'sanctum');
        $this->patchJson("/api/users/{$doctor->id}/role", ['role_id' => $nurseRole->id])->assertForbidden();
        $this->assertSame($doctorRole->id, $doctor->fresh()->role_id);
    }

    public function test_staff_creation_cannot_repurpose_an_existing_student_account(): void
    {
        $admin = $this->adminUser();
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['description' => 'Doctor']);
        $patient = Patient::factory()->create();
        $student = User::factory()->create(['role_id' => $studentRole->id, 'patient_id' => $patient->patient_id]);
        $before = $patient->fresh()->getAttributes();
        $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'name' => 'Attempted Doctor', 'email' => 'attempted-doctor@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $doctorRole->id, 'student_id' => $patient->patient_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
        $this->assertDatabaseMissing('users', ['email' => 'attempted-doctor@example.com']);
        $this->assertSame($studentRole->id, $student->fresh()->role_id);
        $this->assertSame($before, $patient->fresh()->getAttributes());
    }

    public function test_user_form_sex_options_are_saved_on_the_patient_and_medical_record(): void
    {
        $admin = $this->adminUser();
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $this->actingAs($admin, 'sanctum');
        foreach (['Female', 'Male', 'Others'] as $index => $sex) {
            $storedSex = $sex === 'Others' ? 'Other' : $sex;
            $response = $this->postJson('/api/users', [
                'first_name' => 'New', 'last_name' => 'Student', 'email' => "sex-option-{$index}@example.com",
                'password' => 'password123', 'password_confirmation' => 'password123',
                'role_id' => $studentRole->id, 'sex' => $sex,
                'student_id' => '26-00000'.$index,
            ])->assertCreated()->assertJsonPath('data.patient.sex', $storedSex);
            $this->assertDatabaseHas('patients', ['patient_id' => $response->json('data.patientId'), 'sex' => $storedSex]);
            $this->assertDatabaseMissing('medical_records', ['patient_id' => $response->json('data.patientId')]);
            Patient::where('patient_id', $response->json('data.patientId'))->firstOrFail()->ensureMedicalRecord(); // Existing clinical record fixture.
            $this->assertDatabaseHas('medical_records', ['patient_id' => $response->json('data.patientId'), 'sex' => $storedSex]);
            $this->putJson('/api/users/'.$response->json('data.id'), ['sex' => 'Male'])->assertOk()
                ->assertJsonPath('data.patient.sex', 'Male');
            $this->putJson('/api/users/'.$response->json('data.id'), ['sex' => 'Invalid'])->assertUnprocessable()
                ->assertJsonValidationErrors(['sex']);
        }
    }

    public function test_admin_can_create_user_with_patient_id_linkage(): void
    {
        $admin = $this->adminUser();
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['description' => 'Patient']);

        $patient = Patient::create([
            'patient_id' => '24-009988',
            'name' => 'Eduardo Mendoza',
            'type' => 'Student',
            'course_dept' => 'BS Civil Engineering',
            'contact' => '0917-111-2233',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'name' => 'Eduardo Mendoza',
            'email' => 'emendoza@tmc.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $patientRole->id,
            'patient_id' => $patient->patient_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Eduardo Mendoza')
            ->assertJsonPath('data.email', 'emendoza@tmc.edu.ph')
            ->assertJsonPath('data.patient_id', '24-009988')
            ->assertJsonPath('data.patientId', '24-009988');

        $this->assertDatabaseHas('users', [
            'email' => 'emendoza@tmc.edu.ph',
            'patient_id' => '24-009988',
        ]);
    }

    public function test_admin_can_update_user_patient_id(): void
    {
        $admin = $this->adminUser();
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['description' => 'Patient']);

        $patient = Patient::create([
            'patient_id' => '24-001122',
            'name' => 'Liza Soberano',
            'type' => 'Student',
            'course_dept' => 'BS Psychology',
            'contact' => '0917-444-5566',
            'status' => 'Active',
        ]);

        $user = User::factory()->create([
            'name' => 'Liza S.',
            'email' => 'liza@tmc.edu.ph',
            'role_id' => $patientRole->id,
            'patient_id' => null,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$user->id}", [
            'name' => 'Liza Soberano',
            'patient_id' => $patient->patient_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.patientId', '24-001122');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'patient_id' => '24-001122',
        ]);
    }

    public function test_admin_can_create_student_with_new_student_id(): void
    {
        $admin = $this->adminUser();
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'email' => 'jcruz@tmc.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $studentRole->id,
            'patient_id' => null,
            'student_id' => '24-777777',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'age' => 20,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Juan Cruz')
            ->assertJsonPath('data.patientId', '24-777777');

        $this->assertDatabaseHas('patients', [
            'patient_id' => '24-777777',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
        ]);
    }

    public function test_user_creation_fails_with_invalid_patient_id(): void
    {
        $admin = $this->adminUser();
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['description' => 'Patient']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'name' => 'Ghost Patient',
            'email' => 'ghost@tmc.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $patientRole->id,
            'patient_id' => 'NON-EXISTENT-ID',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }
}
