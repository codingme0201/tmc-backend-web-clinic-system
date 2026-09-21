<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnavailableSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_PERMISSIONS = [
        'appointments.view', 'appointments.create', 'appointments.update',
        'appointments.approve', 'appointments.reject', 'appointments.reschedule',
        'appointments.delete',
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

    /** Administrator with the base admin role granted all appointment permissions. */
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

    // --- Authentication boundary -------------------------------------------

    public function test_appointment_endpoints_require_authentication(): void
    {
        $this->getJson('/api/appointments')->assertUnauthorized();
        $this->postJson('/api/appointments', ['patient' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/appointments/1/status', ['status' => 'Approved'])->assertUnauthorized();
        $this->postJson('/api/appointments/1/reschedule', ['date' => '2026-08-10', 'time' => '09:00 AM'])->assertUnauthorized();
    }

    // --- Authorization boundary --------------------------------------------

    public function test_users_without_view_permission_receive_403(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $this->actingAsUser($user);
        $this->getJson('/api/appointments')->assertForbidden();
        $this->postJson('/api/appointments', ['patient' => 'X'])->assertForbidden();
    }

    // --- View -----------------------------------------------------------------

    public function test_admin_can_list_appointments_with_frontend_shape(): void
    {
        $admin = $this->adminUser();
        Appointment::factory()->create([
            'reference' => 'APT-2026-011',
            'patient' => 'Test Patient',
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'status' => 'Pending',
        ]);

        $this->actingAsUser($admin);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'reference', 'patient', 'patientId', 'type', 'reason',
                'date', 'time', 'staff', 'status', 'notes', 'requestedOn',
            ]]])
            ->assertJsonPath('data.0.patient', 'Test Patient');
    }

    public function test_list_can_be_filtered_by_status_date_and_search(): void
    {
        $admin = $this->adminUser();
        Appointment::factory()->create(['reference' => 'APT-2026-020', 'patient' => 'Zed Alpha', 'status' => 'Pending', 'date' => '2026-08-10']);
        Appointment::factory()->create(['reference' => 'APT-2026-021', 'patient' => 'Zed Beta', 'status' => 'Approved', 'date' => '2026-08-10']);
        Appointment::factory()->create(['reference' => 'APT-2026-022', 'patient' => 'Other', 'status' => 'Pending', 'date' => '2026-08-11']);

        $this->actingAsUser($admin);

        $this->getJson('/api/appointments?status=Pending')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/appointments?date=2026-08-11')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments?search=Beta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient', 'Zed Beta');
    }

    public function test_appointment_detail_can_be_viewed(): void
    {
        $admin = $this->adminUser();
        $appointment = Appointment::factory()->create(['reference' => 'APT-2026-030', 'patient' => 'Detail Case']);

        $this->actingAsUser($admin);

        $this->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'APT-2026-030')
            ->assertJsonPath('data.patientId', $appointment->patient_id);
    }

    // --- Create ------------------------------------------------------------------

    public function test_creating_appointment_requires_create_permission(): void
    {
        $viewOnly = $this->userWithPermissions(['appointments.view']);

        $this->actingAsUser($viewOnly);
        $this->postJson('/api/appointments', [
            'patient' => 'New Patient',
            'type' => 'Check-up',
            'reason' => 'Routine check',
            'date' => '2026-08-12',
            'time' => '09:00 AM',
        ])->assertForbidden();
    }

    public function test_appointment_validation_rejects_invalid_payloads(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        $this->postJson('/api/appointments', [
            'type' => 'Check-up',
            'reason' => 'No patient',
            'date' => '2026-08-12',
            'time' => '09:00 AM',
        ])->assertUnprocessable()->assertJsonValidationErrors(['patient']);

        $this->postJson('/api/appointments', [
            'patient' => 'X',
            'type' => 'Not a type',
            'reason' => 'R',
            'date' => '2026-08-12',
            'time' => '12:00 PM',
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'time']);
    }

    public function test_booking_creates_pending_appointment_with_reference(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        $this->postJson('/api/appointments', [
            'patient' => 'Rica Bautista',
            'patient_id' => '2024-0100',
            'type' => 'Follow-up',
            'reason' => 'Prescription refill',
            'date' => '2026-08-14',
            'time' => '01:30 PM',
            'staff' => 'Dr. R. Mendoza',
        ])
            ->assertCreated()
            ->assertJsonPath('data.patient', 'Rica Bautista')
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.requestedOn', now()->toDateString())
            ->assertJsonPath('data.reference', 'APT-2026-001');

        $this->assertDatabaseHas('appointments', ['patient' => 'Rica Bautista', 'reference' => 'APT-2026-001']);
    }

    // --- Approve -----------------------------------------------------------------

    public function test_approve_requires_approve_permission(): void
    {
        $updateOnly = $this->userWithPermissions(['appointments.view', 'appointments.update']);
        $appointment = Appointment::factory()->create(['status' => 'Pending']);

        $this->actingAsUser($updateOnly);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Approved'])
            ->assertForbidden();
    }

    public function test_admin_can_approve_a_pending_appointment(): void
    {
        $admin = $this->adminUser();
        $appointment = Appointment::factory()->create(['status' => 'Pending', 'notes' => 'Initial note']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/appointments/{$appointment->id}/status", [
            'status' => 'Approved',
            'note' => 'Approved — slot confirmed',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.notes', "Initial note\nApproved — slot confirmed");
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $admin = $this->adminUser();
        $cancelled = Appointment::factory()->create(['status' => 'Cancelled']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/appointments/{$cancelled->id}/status", ['status' => 'Approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change appointment from "Cancelled" to "Approved".');
    }

    // --- Reject ------------------------------------------------------------------

    public function test_reject_requires_reject_permission_and_records_reason(): void
    {
        $updateOnly = $this->userWithPermissions(['appointments.view', 'appointments.update']);
        $appointment = Appointment::factory()->create(['status' => 'Pending']);

        $this->actingAsUser($updateOnly);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Rejected'])
            ->assertForbidden();

        $admin = $this->adminUser();
        $this->actingAsUser($admin);
        $this->patchJson("/api/appointments/{$appointment->id}/status", [
            'status' => 'Rejected',
            'note' => 'Rejected — no available slots',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Rejected')
            ->assertJsonPath('data.notes', 'Rejected — no available slots');
    }

    // --- Cancel ------------------------------------------------------------------

    public function test_cancel_requires_update_permission(): void
    {
        $viewOnly = $this->userWithPermissions(['appointments.view']);
        $appointment = Appointment::factory()->create(['status' => 'Pending']);

        $this->actingAsUser($viewOnly);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Cancelled'])
            ->assertForbidden();
    }

    public function test_admin_can_cancel_an_approved_appointment(): void
    {
        $admin = $this->adminUser();
        $appointment = Appointment::factory()->create(['status' => 'Approved']);

        $this->actingAsUser($admin);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Cancelled');
    }

    // --- Reschedule ----------------------------------------------------------------

    public function test_reschedule_requires_reschedule_permission(): void
    {
        $updateOnly = $this->userWithPermissions(['appointments.view', 'appointments.update']);
        $appointment = Appointment::factory()->create(['status' => 'Approved']);

        $this->actingAsUser($updateOnly);
        $this->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'date' => '2026-08-20',
            'time' => '10:30 AM',
        ])->assertForbidden();
    }

    public function test_admin_can_reschedule_an_open_appointment(): void
    {
        $admin = $this->adminUser();
        $appointment = Appointment::factory()->create([
            'status' => 'Approved',
            'date' => '2026-08-10',
            'time' => '09:00 AM',
        ]);

        $this->actingAsUser($admin);
        $this->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'date' => '2026-08-20',
            'time' => '10:30 AM',
            'note' => 'Patient requested a later date',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Rescheduled')
            ->assertJsonPath('data.date', '2026-08-20')
            ->assertJsonPath('data.time', '10:30 AM')
            ->assertJsonPath('data.notes', 'Rescheduled from 2026-08-10 09:00 AM to 2026-08-20 10:30 AM — Patient requested a later date');
    }

    public function test_reschedule_rejects_invalid_time_and_terminal_states(): void
    {
        $admin = $this->adminUser();
        $open = Appointment::factory()->create(['status' => 'Pending']);
        $completed = Appointment::factory()->create(['status' => 'Completed']);

        $this->actingAsUser($admin);

        $this->postJson("/api/appointments/{$open->id}/reschedule", [
            'date' => '2026-08-20',
            'time' => '12:00 PM',
        ])->assertUnprocessable()->assertJsonValidationErrors(['time']);

        $this->postJson("/api/appointments/{$completed->id}/reschedule", [
            'date' => '2026-08-20',
            'time' => '10:30 AM',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Appointments in "Completed" status cannot be rescheduled.');
    }

    public function test_booking_fails_when_doctor_already_has_appointment_at_same_time(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        Appointment::factory()->create([
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'staff' => 'Dr. Mendoza',
            'status' => 'Approved',
        ]);

        $response = $this->postJson('/api/appointments', [
            'patient' => 'Jane Doe',
            'patient_id' => '24-001234',
            'type' => 'Check-up',
            'reason' => 'Routine follow-up',
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'staff' => 'Dr. Mendoza',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected doctor/staff (Dr. Mendoza) already has an appointment booked on 2026-08-10 at 09:00 AM.');
    }

    public function test_booking_fails_when_date_is_blocked_by_unavailable_schedule(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        UnavailableSchedule::create([
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'all_day' => true,
            'reason' => 'Clinic Disinfection & Maintenance',
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson('/api/appointments', [
            'patient' => 'Jane Doe',
            'patient_id' => '24-001234',
            'type' => 'Check-up',
            'reason' => 'Routine follow-up',
            'date' => '2026-08-11',
            'time' => '09:00 AM',
            'staff' => 'Dr. Mendoza',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time slot is unavailable due to a clinic schedule block.');
    }
}
