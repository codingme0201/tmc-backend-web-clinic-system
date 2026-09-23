<?php

namespace Tests\Feature;

use App\Models\MedicalRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_PERMISSIONS = [
        'medical_records.view', 'medical_records.create', 'medical_records.update', 'medical_records.delete',
    ];

    /** Create the given permissions (idempotently) and return their ids. */
    private function permissions(array $names): array
    {
        return collect($names)->map(function (string $name) {
            [$module] = explode('.', $name, 2);

            return Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'label' => ucfirst($name)],
            )->id;
        })->all();
    }

    /** Administrator with the base admin role granted all record permissions. */
    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $role->permissions()->sync($this->permissions(self::ALL_PERMISSIONS));

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** User whose role holds exactly the given permissions. */
    private function userWithPermissions(array $permissionNames): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync($this->permissions($permissionNames));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actingAsUser(User $user): void
    {
        $this->actingAs($user, 'sanctum');
    }

    /** Build a medical record with sensible defaults for a fresh DB. */
    private function makeRecord(array $overrides = []): MedicalRecord
    {
        return MedicalRecord::create(array_merge([
            'patient_id' => '2024-' . fake()->unique()->numberBetween(1000, 9999),
            'name' => 'Test Patient',
            'age' => 21,
            'sex' => 'Male',
            'type' => 'Student',
            'course_dept' => 'BS Test Program',
            'contact' => '0912-000-0000',
            'emergency_contact' => 'Guardian — 0912-000-0001',
            'status' => 'Active',
            'last_updated' => '2026-08-01',
        ], $overrides));
    }

    // --- Authentication boundary -------------------------------------------

    public function test_medical_record_endpoints_require_authentication(): void
    {
        $this->getJson('/api/medical-records')->assertUnauthorized();
        $this->postJson('/api/medical-records/1/conditions', ['name' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/medical-records/1/conditions/1', ['status' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/medical-records/1/conditions/1')->assertUnauthorized();
        $this->postJson('/api/medical-records/1/allergies', ['allergen' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/medical-records/1/allergies/1', ['severity' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/medical-records/1/allergies/1')->assertUnauthorized();
    }

    // --- Authorization boundary --------------------------------------------

    public function test_users_without_view_permission_receive_403(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $this->actingAsUser($user);
        $this->getJson('/api/medical-records')->assertForbidden();
    }

    public function test_view_only_users_cannot_manage_conditions_or_allergies(): void
    {
        $viewOnly = $this->userWithPermissions(['medical_records.view']);
        $record = $this->makeRecord();

        $this->actingAsUser($viewOnly);
        $this->postJson("/api/medical-records/{$record->id}/conditions", ['name' => 'Hypertension'])->assertForbidden();
        $this->postJson("/api/medical-records/{$record->id}/allergies", ['allergen' => 'Penicillin'])->assertForbidden();
    }

    // --- View --------------------------------------------------------------

    public function test_admin_can_list_records_with_frontend_shape(): void
    {
        $admin = $this->adminUser();
        $this->makeRecord(['patient_id' => '2024-0901', 'name' => 'Frontend Shape Case']);

        $this->actingAsUser($admin);

        $this->getJson('/api/medical-records')
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'patientId', 'name', 'age', 'sex', 'type', 'courseDept',
                'contact', 'emergencyContact', 'status', 'lastUpdated',
                'medicalHistory', 'conditions', 'allergies', 'medications',
            ]]])
            ->assertJsonPath('data.0.name', 'Frontend Shape Case');
    }

    public function test_list_returns_nested_clinical_sections(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-0902']);
        $record->conditions()->create(['name' => 'Mild Asthma', 'status' => 'Active', 'diagnosed_date' => '2025-01-10']);
        $record->allergies()->create(['allergen' => 'Peanuts', 'severity' => 'Severe', 'date_recorded' => '2025-02-01']);
        $record->histories()->create(['date' => '2024-12-01', 'condition' => 'Rhinitis', 'notes' => 'Seasonal']);
        $record->medications()->create(['name' => 'Salbutamol', 'dosage' => '100 mcg', 'status' => 'Active']);

        $this->actingAsUser($admin);

        $json = $this->getJson('/api/medical-records')->assertOk()->json('data');
        $entry = collect($json)->firstWhere('patientId', '2024-0902');

        $this->assertContains('Mild Asthma', array_column($entry['conditions'], 'name'));
        $this->assertContains('Peanuts', array_column($entry['allergies'], 'allergen'));
        $this->assertCount(1, $entry['medicalHistory']);
        $this->assertCount(1, $entry['medications']);
    }

    public function test_list_can_be_filtered_by_search_and_status(): void
    {
        $admin = $this->adminUser();
        $this->makeRecord(['patient_id' => '2024-1001', 'name' => 'Zed Alpha', 'status' => 'Active']);
        $this->makeRecord(['patient_id' => '2024-1002', 'name' => 'Zed Beta', 'status' => 'Archived']);

        $this->actingAsUser($admin);

        $this->getJson('/api/medical-records?status=Archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zed Beta');

        $this->getJson('/api/medical-records?search=Beta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zed Beta');
    }

    public function test_list_search_matches_condition_and_allergen_names(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-1003', 'name' => 'Condition Search Case']);
        $record->conditions()->create(['name' => 'Hypertension', 'status' => 'Active']);
        $record->allergies()->create(['allergen' => 'Latex', 'severity' => 'Mild']);

        $this->actingAsUser($admin);

        $this->getJson('/api/medical-records?search=Hypertension')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Condition Search Case');

        $this->getJson('/api/medical-records?search=Latex')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Condition Search Case');
    }

    // --- Medical conditions -------------------------------------------------

    public function test_store_condition_rejects_missing_name(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord();

        $this->actingAsUser($admin);
        $this->postJson("/api/medical-records/{$record->id}/conditions", ['notes' => 'No name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_add_a_condition_and_gets_the_updated_record(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-2001']);

        $this->actingAsUser($admin);

        $json = $this->postJson("/api/medical-records/{$record->id}/conditions", [
            'name' => 'Hypertension',
            'status' => 'Active',
            'diagnosedDate' => '2026-07-15',
            'notes' => 'Monitor weekly.',
        ])
            ->assertOk()
            ->json('data');

        $this->assertContains('Hypertension', array_column($json['conditions'], 'name'));
        $this->assertDatabaseHas('medical_record_conditions', [
            'medical_record_id' => $record->id,
            'name' => 'Hypertension',
            'diagnosed_date' => '2026-07-15',
        ]);
        $this->assertDatabaseHas('medical_records', ['id' => $record->id, 'last_updated' => now()->toDateString()]);
    }

    public function test_condition_defaults_status_and_diagnosed_date(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord();

        $this->actingAsUser($admin);
        $this->postJson("/api/medical-records/{$record->id}/conditions", ['name' => 'Migraine'])
            ->assertOk();

        $this->assertDatabaseHas('medical_record_conditions', [
            'medical_record_id' => $record->id,
            'name' => 'Migraine',
            'status' => 'Active',
            'diagnosed_date' => now()->toDateString(),
        ]);
    }

    public function test_condition_from_another_record_returns_404(): void
    {
        $admin = $this->adminUser();
        $recordA = $this->makeRecord(['patient_id' => '2024-2002']);
        $recordB = $this->makeRecord(['patient_id' => '2024-2003']);
        $condition = $recordA->conditions()->create(['name' => 'Asthma', 'status' => 'Active']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/medical-records/{$recordB->id}/conditions/{$condition->id}", ['status' => 'Resolved'])->assertNotFound();
        $this->deleteJson("/api/medical-records/{$recordB->id}/conditions/{$condition->id}")->assertNotFound();
    }

    public function test_admin_can_update_a_condition(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-2004']);
        $condition = $record->conditions()->create(['name' => 'Asthma', 'status' => 'Active']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/medical-records/{$record->id}/conditions/{$condition->id}", ['status' => 'Resolved'])
            ->assertOk();

        $this->assertDatabaseHas('medical_record_conditions', [
            'id' => $condition->id,
            'status' => 'Resolved',
        ]);
    }

    public function test_admin_can_remove_a_condition(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-2005']);
        $condition = $record->conditions()->create(['name' => 'Eczema', 'status' => 'Inactive']);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/medical-records/{$record->id}/conditions/{$condition->id}")
            ->assertOk();

        $this->assertDatabaseMissing('medical_record_conditions', ['id' => $condition->id]);
    }

    // --- Allergies ----------------------------------------------------------

    public function test_store_allergy_rejects_missing_allergen(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord();

        $this->actingAsUser($admin);
        $this->postJson("/api/medical-records/{$record->id}/allergies", ['severity' => 'Severe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allergen']);
    }

    public function test_admin_can_add_an_allergy_and_gets_the_updated_record(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-3001']);

        $this->actingAsUser($admin);

        $json = $this->postJson("/api/medical-records/{$record->id}/allergies", [
            'allergen' => 'Penicillin',
            'reaction' => 'Skin rash',
            'severity' => 'Moderate',
            'dateRecorded' => '2026-06-01',
            'notes' => 'Avoid penicillin-based antibiotics.',
        ])
            ->assertOk()
            ->json('data');

        $this->assertContains('Penicillin', array_column($json['allergies'], 'allergen'));
        $this->assertDatabaseHas('medical_record_allergies', [
            'medical_record_id' => $record->id,
            'allergen' => 'Penicillin',
            'date_recorded' => '2026-06-01',
        ]);
        $this->assertDatabaseHas('medical_records', ['id' => $record->id, 'last_updated' => now()->toDateString()]);
    }

    public function test_allergy_defaults_severity_to_moderate(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord();

        $this->actingAsUser($admin);
        $this->postJson("/api/medical-records/{$record->id}/allergies", ['allergen' => 'Seafood'])
            ->assertOk();

        $this->assertDatabaseHas('medical_record_allergies', [
            'medical_record_id' => $record->id,
            'allergen' => 'Seafood',
            'severity' => 'Moderate',
        ]);
    }

    public function test_admin_can_update_an_allergy(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-3002']);
        $allergy = $record->allergies()->create(['allergen' => 'Sulfa', 'severity' => 'Moderate']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/medical-records/{$record->id}/allergies/{$allergy->id}", ['severity' => 'Severe'])
            ->assertOk();

        $this->assertDatabaseHas('medical_record_allergies', [
            'id' => $allergy->id,
            'severity' => 'Severe',
        ]);
    }

    public function test_admin_can_remove_an_allergy(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-3003']);
        $allergy = $record->allergies()->create(['allergen' => 'Latex', 'severity' => 'Mild']);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/medical-records/{$record->id}/allergies/{$allergy->id}")
            ->assertOk();

        $this->assertDatabaseMissing('medical_record_allergies', ['id' => $allergy->id]);
    }

    // --- Data integrity -----------------------------------------------------

    public function test_deleting_a_record_cascades_to_its_children(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-4001']);
        $condition = $record->conditions()->create(['name' => 'GERD', 'status' => 'Active']);
        $allergy = $record->allergies()->create(['allergen' => 'Nuts', 'severity' => 'Severe']);

        $record->delete();

        $this->assertDatabaseMissing('medical_record_conditions', ['id' => $condition->id]);
        $this->assertDatabaseMissing('medical_record_allergies', ['id' => $allergy->id]);
    }

    public function test_admin_can_archive_and_restore_a_medical_record(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-5001', 'status' => 'Active']);

        $this->actingAsUser($admin);

        // Archive
        $this->patchJson("/api/medical-records/{$record->id}/status", ['status' => 'Archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Archived');

        $this->assertDatabaseHas('medical_records', [
            'id' => $record->id,
            'status' => 'Archived',
        ]);

        // Restore
        $this->patchJson("/api/medical-records/{$record->id}/status", ['status' => 'Active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Active');

        $this->assertDatabaseHas('medical_records', [
            'id' => $record->id,
            'status' => 'Active',
        ]);
    }

    public function test_updating_status_validates_allowed_values(): void
    {
        $admin = $this->adminUser();
        $record = $this->makeRecord(['patient_id' => '2024-5002', 'status' => 'Active']);

        $this->actingAsUser($admin);

        $this->patchJson("/api/medical-records/{$record->id}/status", ['status' => 'InvalidStatus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
