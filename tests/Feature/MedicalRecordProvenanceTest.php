<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalRecordProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleName, string $name): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);
        foreach (['medical_records.view', 'medical_records.update', 'patients.update'] as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['module' => explode('.', $permissionName)[0], 'label' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['name' => $name, 'role_id' => $role->id, 'status' => 'active']);
    }

    public function test_unattributed_profile_is_not_falsely_labelled_student_provided(): void
    {
        $patient = Patient::factory()->create();
        $patient->ensureMedicalRecord();
        $this->actingAs($this->user('admin', 'Clinic Admin'), 'sanctum')->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.studentProvided', false)
            ->assertJsonPath('data.0.studentUpdatedAt', null)
            ->assertJsonPath('data.0.lastUpdatedBy', null);
    }

    public function test_mobile_attribution_is_preserved_when_staff_later_update_the_profile(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $patient = Patient::factory()->create(['patient_id' => '00-000100']);
        $patient->ensureMedicalRecord();
        $student = $this->user('student', 'Student Editor');
        $student->update(['patient_id' => $patient->patient_id]);
        $staff = $this->user('admin', 'Clinic Editor');
        $this->travel(1)->minutes();
        $this->actingAs($student, 'sanctum')->putJson('/api/me/profile', ['age' => 20])->assertOk();
        $mobileTime = now()->toIso8601String();
        $this->actingAs($staff, 'sanctum')->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.lastUpdatedBy.name', 'Student Editor')
            ->assertJsonPath('data.0.lastUpdatedBy.source', 'Mobile App')
            ->assertJsonPath('data.0.studentProvided', true)
            ->assertJsonPath('data.0.studentUpdatedAt', $mobileTime);

        $this->travel(1)->minutes();
        $this->putJson("/api/patients/{$patient->id}", ['age' => 21])->assertOk();
        $this->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.age', 21)
            ->assertJsonPath('data.0.lastUpdatedBy.name', 'Clinic Editor')
            ->assertJsonPath('data.0.lastUpdatedBy.source', 'Web')
            ->assertJsonPath('data.0.studentProvided', true)
            ->assertJsonPath('data.0.studentUpdatedAt', $mobileTime);
    }

    public function test_existing_mobile_audit_entries_are_recognized_and_unaudited_edits_remain_unknown(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $patient = Patient::factory()->create(['patient_id' => '00-000101']);
        $patient->ensureMedicalRecord();
        $student = $this->user('student', 'Legacy Student');
        $student->update(['patient_id' => $patient->patient_id]);
        ActivityLog::record($student, 'Registered an account from the mobile app', 'System');
        $staff = $this->user('admin', 'Reader');
        $this->actingAs($staff, 'sanctum')->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.studentProvided', true)
            ->assertJsonPath('data.0.lastUpdatedBy.source', 'Mobile App');
        $this->travel(1)->minutes();
        Patient::whereKey($patient->id)->update(['age' => 26]); // A legacy bulk edit without an author.
        $this->getJson('/api/medical-records')->assertOk()
            ->assertJsonPath('data.0.lastUpdatedBy', null)
            ->assertJsonPath('data.0.studentProvided', true);
    }
}
