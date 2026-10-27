<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_PERMISSIONS = [
        'consultations.view', 'consultations.create', 'consultations.update', 'consultations.delete',
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

    /** Administrator with the base admin role granted all consultation permissions. */
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

    /** Build a consultation record with sensible defaults for a fresh DB. */
    private function makeConsultation(array $overrides = []): Consultation
    {
        return Consultation::create(array_merge([
            'reference' => Consultation::nextReference('2026-08-10'),
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'patient' => 'Test Patient',
            'patient_id' => '2024-0100',
            'staff' => 'Dr. R. Mendoza',
            'status' => 'Scheduled',
            'chief_complaint' => 'Recurring headache',
            'vitals' => [
                'temperature' => '', 'bloodPressure' => '', 'pulseRate' => '',
                'respiratoryRate' => '', 'height' => '', 'weight' => '',
            ],
            'clinical_findings' => '',
            'diagnosis' => '',
            'treatment' => '',
            'disposition' => '',
            'started_at' => null,
            'completed_at' => null,
        ], $overrides));
    }

    public function test_clinical_record_saves_and_displays_both_available_consultation_types(): void
    {
        $this->actingAsUser($this->adminUser());

        foreach (['General Check-up', 'Follow-up Consultation'] as $type) {
            $visitType = $type === 'Follow-up Consultation' ? 'Follow-up Consultation' : 'New Consultation';
            $id = $this->postJson('/api/consultations', [
                'patient' => 'Clinical Record Patient',
                'type' => $type,
                'visit_type' => 'New Consultation',
                'date' => now()->addDay()->toDateString(),
                'time' => '09:00 AM',
                'status' => 'Scheduled',
            ])->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.visitType', $visitType)
                ->json('data.id');

            $this->assertDatabaseHas('consultations', ['id' => $id, 'type' => $type, 'visit_type' => $visitType]);
            $this->getJson("/api/consultations/{$id}")->assertOk()->assertJsonPath('data.type', $type);
            $this->getJson('/api/consultations')->assertOk()->assertJsonFragment(['id' => $id, 'type' => $type]);
        }
    }

    // --- Authentication boundary -------------------------------------------

    public function test_consultation_endpoints_require_authentication(): void
    {
        $this->getJson('/api/consultations')->assertUnauthorized();
        $this->getJson('/api/consultations/1')->assertUnauthorized();
        $this->postJson('/api/consultations', ['patient' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/consultations/1', ['diagnosis' => 'X'])->assertUnauthorized();
        $this->postJson('/api/consultations/1/start')->assertUnauthorized();
        $this->postJson('/api/consultations/1/complete', [])->assertUnauthorized();
    }

    // --- Authorization boundary --------------------------------------------

    public function test_users_without_view_permission_receive_403(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $this->actingAsUser($user);
        $this->getJson('/api/consultations')->assertForbidden();
        $this->postJson('/api/consultations', ['patient' => 'X'])->assertForbidden();
    }

    // --- View --------------------------------------------------------------

    public function test_admin_can_list_consultations_with_frontend_shape(): void
    {
        $admin = $this->adminUser();
        $this->makeConsultation(['reference' => 'CONS-2026-100', 'patient' => 'Frontend Shape Case']);

        $this->actingAsUser($admin);

        $this->getJson('/api/consultations')
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'reference', 'date', 'time', 'patient', 'patientId', 'appointmentId',
                'staff', 'status', 'chiefComplaint', 'vitals', 'clinicalFindings',
                'diagnosis', 'treatment', 'disposition', 'startedAt', 'completedAt',
            ]]])
            ->assertJsonPath('data.0.patient', 'Frontend Shape Case');
    }

    public function test_list_can_be_filtered_by_status_and_search(): void
    {
        $admin = $this->adminUser();
        $this->makeConsultation(['reference' => 'CONS-2026-201', 'patient' => 'Zed Alpha', 'status' => 'Scheduled']);
        $this->makeConsultation(['reference' => 'CONS-2026-202', 'patient' => 'Zed Beta', 'status' => 'In Progress']);
        $this->makeConsultation(['reference' => 'CONS-2026-203', 'patient' => 'Other', 'status' => 'Completed']);

        $this->actingAsUser($admin);

        $this->getJson('/api/consultations?status=In Progress')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient', 'Zed Beta');

        $this->getJson('/api/consultations?search=Beta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient', 'Zed Beta');
    }

    public function test_consultation_detail_can_be_viewed(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['reference' => 'CONS-2026-300', 'diagnosis' => 'Tension Headache']);

        $this->actingAsUser($admin);

        $this->getJson("/api/consultations/{$consultation->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'CONS-2026-300')
            ->assertJsonPath('data.diagnosis', 'Tension Headache');
    }

    public function test_list_shows_newest_records_first_regardless_of_status_or_visit_date(): void
    {
        $this->actingAsUser($this->adminUser());
        $this->travelTo(now()->setDate(2026, 8, 2)->setTime(8, 0));
        $newer = $this->makeConsultation([
            'date' => '2026-08-15', 'status' => 'Completed',
        ]);
        $latest = $this->makeConsultation([
            'date' => '2026-08-10', 'status' => 'Scheduled',
        ]);
        $this->travelTo(now()->setDate(2026, 8, 1));
        $older = $this->makeConsultation([
            'date' => '2026-08-20', 'status' => 'In Progress',
        ]);

        $this->getJson('/api/consultations')->assertOk()
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.2.id', $older->id);
    }

    // --- Create (log) ------------------------------------------------------

    public function test_creating_consultation_requires_create_permission(): void
    {
        $viewOnly = $this->userWithPermissions(['consultations.view']);

        $this->actingAsUser($viewOnly);
        $this->postJson('/api/consultations', ['patient' => 'New Patient'])->assertForbidden();
    }

    public function test_store_rejects_missing_patient(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        $this->postJson('/api/consultations', ['symptoms' => 'Fever'])->assertUnprocessable();
    }

    public function test_admin_can_log_consultation_with_legacy_dashboard_shape(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        $this->postJson('/api/consultations', [
            'patient' => 'Rica Bautista',
            'patient_id' => '2024-0100',
            'staff' => 'Nurse C. Villanueva',
            'symptoms' => 'Fever and body aches',
            'vitals' => ['bp' => '120/80', 'temp' => '38.2°C', 'pulse' => '92 bpm'],
            'diagnosis' => 'Mild Flu',
            'treatment' => 'Paracetamol 500mg every 4 hours',
            'disposition' => 'Sent Home',
        ])
            ->assertCreated()
            ->assertJsonPath('data.patient', 'Rica Bautista')
            ->assertJsonPath('data.status', 'Completed')
            ->assertJsonPath('data.chiefComplaint', 'Fever and body aches')
            ->assertJsonPath('data.vitals.bloodPressure', '120/80')
            ->assertJsonPath('data.reference', 'CONS-2026-001');

        $this->assertDatabaseHas('consultations', ['patient' => 'Rica Bautista', 'reference' => 'CONS-2026-001']);
    }

    // --- Start -------------------------------------------------------------

    public function test_start_requires_create_permission(): void
    {
        $viewOnly = $this->userWithPermissions(['consultations.view']);
        $consultation = $this->makeConsultation();

        $this->actingAsUser($viewOnly);
        $this->postJson("/api/consultations/{$consultation->id}/start")->assertForbidden();
    }

    public function test_admin_can_start_a_scheduled_consultation(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['reference' => 'CONS-2026-400', 'status' => 'Scheduled']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$consultation->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'In Progress')
            ->assertJsonPath('data.startedAt', now()->format('Y-m-d h:i A'));
    }

    public function test_start_rejects_non_scheduled_consultations(): void
    {
        $admin = $this->adminUser();
        $inProgress = $this->makeConsultation(['reference' => 'CONS-2026-410', 'status' => 'In Progress']);
        $completed = $this->makeConsultation(['reference' => 'CONS-2026-411', 'status' => 'Completed']);

        $this->actingAsUser($admin);

        $this->postJson("/api/consultations/{$inProgress->id}/start")->assertStatus(409);
        $this->postJson("/api/consultations/{$completed->id}/start")->assertStatus(409);
    }

    // --- Save progress (update) --------------------------------------------

    public function test_update_requires_update_permission(): void
    {
        $viewCreateOnly = $this->userWithPermissions(['consultations.view', 'consultations.create']);
        $consultation = $this->makeConsultation(['status' => 'In Progress']);

        $this->actingAsUser($viewCreateOnly);
        $this->patchJson("/api/consultations/{$consultation->id}", ['diagnosis' => 'X'])->assertForbidden();
    }

    public function test_update_persists_staff_chief_complaint_and_vitals(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['reference' => 'CONS-2026-500', 'status' => 'In Progress']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/consultations/{$consultation->id}", [
            'staff' => 'Dr. S. Lopez',
            'chiefComplaint' => 'Severe headache and nausea',
            'vitals' => ['temperature' => '37.1°C', 'bloodPressure' => '110/70'],
        ])
            ->assertOk()
            ->assertJsonPath('data.staff', 'Dr. S. Lopez')
            ->assertJsonPath('data.chiefComplaint', 'Severe headache and nausea')
            ->assertJsonPath('data.vitals.temperature', '37.1°C');

        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'staff' => 'Dr. S. Lopez',
            'chief_complaint' => 'Severe headache and nausea',
        ]);
    }

    public function test_completed_consultations_are_read_only(): void
    {
        $admin = $this->adminUser();
        $completed = $this->makeConsultation(['reference' => 'CONS-2026-510', 'status' => 'Completed']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/consultations/{$completed->id}", ['diagnosis' => 'Changed'])->assertStatus(409);
    }

    // --- Complete ----------------------------------------------------------

    public function test_complete_requires_update_permission(): void
    {
        $viewCreateOnly = $this->userWithPermissions(['consultations.view', 'consultations.create']);
        $consultation = $this->makeConsultation(['status' => 'In Progress']);

        $this->actingAsUser($viewCreateOnly);
        $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => 'Cough', 'diagnosis' => 'URTI', 'treatment' => 'Rest',
        ])->assertForbidden();
    }

    public function test_complete_requires_core_clinical_fields(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['status' => 'In Progress']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => 'Cough only',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['diagnosis', 'treatment']);
    }

    public function test_admin_can_complete_an_in_progress_consultation(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['reference' => 'CONS-2026-600', 'status' => 'In Progress']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'staff' => 'Nurse C. Villanueva',
            'chiefComplaint' => 'Fever and body aches since morning',
            'vitals' => ['temperature' => '38.2°C', 'bloodPressure' => '118/76'],
            'clinicalFindings' => 'Flushed skin, mild dehydration',
            'diagnosis' => 'Mild Flu Symptoms',
            'treatment' => 'Paracetamol 500mg every 4 hours. Hydrate well.',
            'disposition' => 'Sent Home',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Completed')
            ->assertJsonPath('data.chiefComplaint', 'Fever and body aches since morning')
            ->assertJsonPath('data.staff', 'Nurse C. Villanueva')
            ->assertJsonPath('data.diagnosis', 'Mild Flu Symptoms')
            ->assertJsonPath('data.completedAt', now()->format('Y-m-d h:i A'));

        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'status' => 'Completed',
            'chief_complaint' => 'Fever and body aches since morning',
            'staff' => 'Nurse C. Villanueva',
            'diagnosis' => 'Mild Flu Symptoms',
        ]);
    }

    public function test_already_completed_consultations_cannot_be_completed_again(): void
    {
        $admin = $this->adminUser();
        $completed = $this->makeConsultation(['reference' => 'CONS-2026-610', 'status' => 'Completed']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$completed->id}/complete", [
            'chiefComplaint' => 'X', 'diagnosis' => 'Y', 'treatment' => 'Z',
        ])->assertStatus(409);
    }

    public function test_scheduled_consultations_cannot_skip_to_completed(): void
    {
        $admin = $this->adminUser();
        $scheduled = $this->makeConsultation(['reference' => 'CONS-2026-620', 'status' => 'Scheduled']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$scheduled->id}/complete", [
            'chiefComplaint' => 'X', 'diagnosis' => 'Y', 'treatment' => 'Z',
        ])->assertStatus(409);
    }

    public function test_whitespace_only_required_fields_are_rejected_on_complete(): void
    {
        $admin = $this->adminUser();
        $consultation = $this->makeConsultation(['status' => 'In Progress']);

        $this->actingAsUser($admin);
        $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => '   ',
            'diagnosis' => '  ',
            'treatment' => 'Rest',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chiefComplaint', 'diagnosis']);
    }
}
