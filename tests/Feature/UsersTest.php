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
