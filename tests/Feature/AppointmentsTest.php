<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->travelTo(now()->setDate(2026, 8, 10)->setTime(8, 0));
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

    public function test_list_shows_newest_bookings_first_regardless_of_visit_date(): void
    {
        $this->actingAsUser($this->adminUser());
        $this->travelTo(now()->setDate(2026, 8, 2)->setTime(8, 0));
        $newer = Appointment::factory()->create([
            'date' => '2026-08-20', 'time' => '09:00 AM', 'status' => 'Pending',
        ]);
        $latest = Appointment::factory()->create([
            'date' => '2026-08-15', 'status' => 'Pending',
        ]);
        $this->travelTo(now()->setDate(2026, 8, 1));
        $older = Appointment::factory()->create([
            'date' => '2026-08-10', 'status' => 'Pending',
        ]);
        $afternoon = Appointment::factory()->create([
            'date' => '2026-08-20', 'time' => '01:00 PM', 'status' => 'Pending',
        ]);

        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.2.id', $afternoon->id)
            ->assertJsonPath('data.3.id', $older->id);
    }

    public function test_approval_moves_a_new_booking_into_schedule_order_below_pending_requests(): void
    {
        $this->actingAsUser($this->adminUser());
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(8, 0));
        $laterDate = Appointment::factory()->create([
            'status' => 'Approved', 'date' => '2026-08-20', 'time' => '08:00 AM',
        ]);
        $afternoon = Appointment::factory()->create([
            'status' => 'Approved', 'date' => '2026-08-15', 'time' => '01:00 PM',
        ]);
        $morning = Appointment::factory()->create([
            'status' => 'Approved', 'date' => '2026-08-15', 'time' => '09:00 AM',
        ]);
        $pending = Appointment::factory()->create([
            'status' => 'Pending', 'date' => '2026-08-25',
        ]);
        $this->travelTo(now()->addDay());
        $newBooking = Appointment::factory()->create([
            'status' => 'Pending', 'date' => '2026-08-15', 'time' => '10:00 AM',
        ]);

        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonPath('data.0.id', $newBooking->id)
            ->assertJsonPath('data.1.id', $pending->id);

        $this->patchJson("/api/appointments/{$newBooking->id}/status", ['status' => 'Approved'])
            ->assertOk();

        $expected = [$pending->id, $morning->id, $newBooking->id, $afternoon->id, $laterDate->id];
        $response = $this->getJson('/api/appointments')->assertOk();
        $this->assertSame($expected, array_column($response->json('data'), 'id'));

        $response = $this->getJson('/api/appointments?status=Approved')->assertOk();
        $this->assertSame(array_slice($expected, 1), array_column($response->json('data'), 'id'));
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
            'type' => 'General Check-up',
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
            'type' => 'General Check-up',
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

    public function test_booking_requires_a_registered_patient_and_never_creates_one_from_typed_text(): void
    {
        $this->actingAsUser($this->adminUser());
        foreach ([null, '', '24-UNREGISTERED'] as $patientId) {
            $this->postJson('/api/appointments', [
                'patient' => 'Unregistered Typed Name',
                'patient_id' => $patientId,
                'type' => 'General Check-up',
                'reason' => 'Routine check-up',
                'date' => now()->addDay()->toDateString(),
                'time' => '09:00 AM',
            ])->assertUnprocessable()->assertJsonValidationErrors(['patient_id']);
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_booking_uses_the_selected_registered_patient_instead_of_a_typed_name(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 10)->startOfDay());
        $patient = Patient::factory()->create(['name' => 'Registered Patient']);
        $this->actingAsUser($this->adminUser());
        $id = $this->postJson('/api/appointments', [
            'patient' => 'Different Typed Name',
            'patient_id' => $patient->patient_id,
            'type' => 'General Check-up',
            'reason' => 'Routine check-up',
            'date' => now()->addDay()->toDateString(),
            'time' => '09:00 AM',
        ])->assertCreated()
            ->assertJsonPath('data.patient', $patient->name)
            ->assertJsonPath('data.patientId', $patient->patient_id)
            ->json('data.id');

        $this->getJson("/api/appointments/{$id}")->assertOk()
            ->assertJsonPath('data.patient', $patient->name)
            ->assertJsonPath('data.patientId', $patient->patient_id);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'patient' => $patient->name, 'patient_id' => $patient->patient_id]);
        $this->assertDatabaseCount('patients', 1);
    }

    public function test_booking_creates_pending_appointment_with_reference(): void
    {
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        Patient::factory()->create(['patient_id' => '2024-0100', 'name' => 'Rica Bautista']);

        $this->postJson('/api/appointments', [
            'patient' => 'Rica Bautista',
            'patient_id' => '2024-0100',
            'type' => 'Follow-up Consultation',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
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

    public function test_both_appointment_types_are_saved_and_displayed_for_staff_and_patient_bookings(): void
    {
        $admin = $this->adminUser();
        $patient = Patient::create([
            'patient_id' => '24-101010', 'name' => 'Type Patient', 'type' => 'Student',
            'course_dept' => '', 'status' => 'Active',
        ]);
        $student = User::factory()->create([
            'role_id' => Role::firstOrCreate(['name' => 'student'], ['description' => 'Student'])->id,
            'patient_id' => $patient->patient_id,
        ]);
        $this->actingAsUser($student);
        $this->getJson('/api/me/appointment-options')->assertOk()
            ->assertJsonPath('data.types', ['General Check-up', 'Follow-up Consultation']);

        foreach (['General Check-up', 'Follow-up Consultation'] as $index => $type) {
            $visitType = $type === Appointment::VISIT_FOLLOW_UP ? Appointment::VISIT_FOLLOW_UP : Appointment::VISIT_NEW;
            $payload = [
                'patient' => 'Type Patient', 'patient_id' => $patient->patient_id, 'type' => $type, 'reason' => 'Appointment type verification',
                'date' => now()->addDays($index + 2)->toDateString(), 'time' => '09:00 AM',
            ];
            $this->actingAsUser($admin);
            $id = $this->postJson('/api/appointments', $payload)->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.visitType', $visitType)
                ->assertJsonPath('data.isFollowUp', $type === Appointment::VISIT_FOLLOW_UP)->json('data.id');
            $this->assertDatabaseHas('appointments', ['id' => $id, 'type' => $type, 'visit_type' => $visitType]);
            $this->getJson("/api/appointments/{$id}")->assertOk()->assertJsonPath('data.type', $type);
            $this->getJson('/api/appointments')->assertOk()->assertJsonFragment(['id' => $id, 'type' => $type, 'visitType' => $visitType]);

            $this->actingAsUser($student);
            $patientId = $this->postJson('/api/me/appointments', [...$payload, 'time' => '10:00 AM'])->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.visitType', $visitType)
                ->assertJsonPath('data.isFollowUp', $type === Appointment::VISIT_FOLLOW_UP)->json('data.id');
            $this->assertDatabaseHas('appointments', ['id' => $patientId, 'type' => $type]);
            $this->getJson('/api/me/appointments')->assertOk()->assertJsonFragment(['id' => $patientId, 'type' => $type]);
        }
    }

    public function test_removed_appointment_types_cannot_be_booked(): void
    {
        $this->actingAsUser($this->adminUser());
        foreach (['Other', 'Check-up', 'Dental concern', 'Follow-up', 'Fever', 'Vaccination', 'Emergency'] as $type) {
            $this->postJson('/api/appointments', [
                'patient' => 'Invalid Type', 'type' => $type, 'reason' => 'Test',
                'date' => now()->addDays(2)->toDateString(), 'time' => '09:00 AM',
            ])->assertUnprocessable()->assertJsonValidationErrors(['type']);
        }
        $this->assertDatabaseCount('appointments', 0);
    }

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

    public function test_update_permission_allows_record_status_actions(): void
    {
        $user = $this->userWithPermissions(['appointments.view', 'appointments.update']);
        $this->actingAsUser($user);

        foreach ([
            ['Under Review', 'Pending'],
            ['Pending', 'Under Review'],
            ['Pending', 'Cancelled'],
            ['Approved', 'No-Show'],
        ] as [$initialStatus, $targetStatus]) {
            $appointment = Appointment::factory()->create(['status' => $initialStatus]);

            $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => $targetStatus])
                ->assertOk()
                ->assertJsonPath('data.status', $targetStatus);

            $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => $targetStatus]);
        }
    }

    public function test_returning_to_pending_requires_update_permission(): void
    {
        $this->actingAsUser($this->userWithPermissions(['appointments.view']));
        $appointment = Appointment::factory()->create(['status' => 'Under Review']);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Pending'])
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'Under Review']);
    }

    public function test_only_under_review_appointments_can_return_to_pending(): void
    {
        $this->actingAsUser($this->adminUser());

        foreach (['Pending', 'Approved', 'Rescheduled', 'Rejected', 'Cancelled', 'Completed', 'No-Show'] as $status) {
            $appointment = Appointment::factory()->create(['status' => $status]);

            $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Pending'])
                ->assertUnprocessable();

            $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => $status]);
        }
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
        $this->travelTo(now()->setDate(2026, 8, 10)->setTime(8, 0));
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
        $this->travelTo(now()->setDate(2026, 8, 10)->setTime(8, 0));
        $admin = $this->adminUser();
        $this->actingAsUser($admin);

        Appointment::factory()->create([
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'staff' => 'Dr. Mendoza',
            'status' => 'Approved',
        ]);

        Patient::factory()->create(['patient_id' => '24-001234', 'name' => 'Jane Doe']);
        $response = $this->postJson('/api/appointments', [
            'patient' => 'Jane Doe',
            'patient_id' => '24-001234',
            'type' => 'General Check-up',
            'reason' => 'Routine follow-up',
            'date' => '2026-08-10',
            'time' => '09:00 AM',
            'staff' => 'Dr. Mendoza',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected doctor/staff (Dr. Mendoza) already has an appointment booked on 2026-08-10 at 09:00 AM.');
    }

    public static function walkInStaffRoles(): array
    {
        return [['doctor'], ['nurse']];
    }

    #[DataProvider('walkInStaffRoles')]
    public function test_walk_in_keeps_assigned_medical_staff_in_appointment_and_queue_records(string $roleName): void
    {
        $this->travelTo(now()->setDate(2026, 8, 14)->setTime(9, 0));
        $this->actingAsUser($this->adminUser());
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => ucfirst($roleName)]);
        $staff = User::factory()->create([
            'name' => ucfirst($roleName).' Walk-in Clinician',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        StaffSchedule::create(['user_id' => $staff->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '05:00 PM', 'status' => 'Available']);

        $patient = Patient::factory()->create(['name' => 'Walk-in Patient']);
        $appointmentId = $this->postJson('/api/appointments', [
            'patient' => 'Walk-in Patient',
            'patient_id' => $patient->patient_id,
            'type' => 'General Check-up',
            'reason' => 'General Check-up',
            'date' => now()->toDateString(),
            'time' => '09:00 AM',
            'staff_id' => $staff->id,
        ])->assertCreated()
            ->assertJsonPath('data.staffId', $staff->id)
            ->assertJsonPath('data.staff', $staff->name)
            ->assertJsonPath('data.staffRole', $roleName)
            ->json('data.id');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'staff_id' => $staff->id,
            'staff' => $staff->name,
        ]);
        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonPath('data.0.staffId', $staff->id)
            ->assertJsonPath('data.0.staff', $staff->name)
            ->assertJsonPath('data.0.staffRole', $roleName);
        $this->getJson('/api/queue')->assertOk()
            ->assertJsonPath('data.0.staffId', $staff->id)
            ->assertJsonPath('data.0.staff', $staff->name)
            ->assertJsonPath('data.0.staffRole', $roleName)
            ->assertJsonPath('data.0.queueStatus', 'Awaiting Approval');

        $this->patchJson("/api/appointments/{$appointmentId}/status", ['status' => 'Approved'])->assertOk();
        $this->postJson("/api/queue/{$appointmentId}/check-in")->assertOk()
            ->assertJsonPath('data.staffId', $staff->id)
            ->assertJsonPath('data.staff', $staff->name)
            ->assertJsonPath('data.staffRole', $roleName)
            ->assertJsonPath('data.queueStatus', 'Waiting');
        $this->getJson("/api/appointments/{$appointmentId}")->assertOk()
            ->assertJsonPath('data.staffId', $staff->id)
            ->assertJsonPath('data.staff', $staff->name)
            ->assertJsonPath('data.staffRole', $roleName);
        $this->getJson('/api/queue')->assertOk()
            ->assertJsonPath('data.0.appointmentId', $appointmentId)
            ->assertJsonPath('data.0.staffId', $staff->id)
            ->assertJsonPath('data.0.staff', $staff->name)
            ->assertJsonPath('data.0.staffRole', $roleName)
            ->assertJsonPath('data.0.queueStatus', 'Waiting');
    }

    public function test_same_day_booking_closes_after_five_pm(): void
    {
        $this->actingAsUser($this->adminUser());
        $patientIds = [];
        foreach (['Rica Bautista', 'Ana Cruz', 'Ben Ramos', 'Carla Reyes', 'Dan Santos'] as $name) {
            $patientIds[$name] = Patient::factory()->create(['name' => $name])->patient_id;
        }
        $payload = [
            'patient' => 'Rica Bautista',
            'patient_id' => $patientIds['Rica Bautista'],
            'type' => 'General Check-up',
            'reason' => 'Walk-in',
            'date' => '2026-08-14',
        ];

        $this->travelTo(now()->setDate(2026, 8, 14)->setTime(14, 10));
        $this->postJson('/api/appointments', [...$payload, 'time' => '01:30 PM'])->assertUnprocessable();
        $this->postJson('/api/appointments', [...$payload, 'time' => '02:00 PM'])->assertCreated();

        $this->travelTo(now()->setTime(16, 30));
        $this->postJson('/api/appointments', [...$payload, 'patient' => 'Ana Cruz', 'patient_id' => $patientIds['Ana Cruz'], 'time' => '04:30 PM'])->assertCreated();

        $this->travelTo(now()->setTime(16, 31));
        $this->postJson('/api/appointments', [...$payload, 'patient' => 'Ben Ramos', 'patient_id' => $patientIds['Ben Ramos'], 'time' => '05:00 PM'])->assertCreated();

        $this->travelTo(now()->setTime(17, 0));
        $this->postJson('/api/appointments', [...$payload, 'patient' => 'Carla Reyes', 'patient_id' => $patientIds['Carla Reyes'], 'time' => '05:00 PM'])->assertCreated();

        $this->travelTo(now()->setTime(17, 1));
        $this->postJson('/api/appointments', [...$payload, 'patient' => 'Dan Santos', 'patient_id' => $patientIds['Dan Santos'], 'time' => '05:00 PM'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The 05:00 PM slot on 2026-08-14 is no longer available. Same-day appointments can only be booked until 05:00 PM and for times that have not yet passed.');
        $this->postJson('/api/appointments', [...$payload, 'patient' => 'Ben Ramos', 'patient_id' => $patientIds['Ben Ramos'], 'date' => '2026-08-17', 'time' => '08:00 AM'])->assertCreated();
    }

    public function test_booking_and_rescheduling_use_eight_am_to_five_pm_slots(): void
    {
        $this->actingAsUser($this->adminUser());
        $this->travelTo(now()->setDate(2026, 8, 14)->setTime(8, 0));
        $payload = [
            'patient' => 'Rica Bautista',
            'patient_id' => Patient::factory()->create(['name' => 'Rica Bautista'])->patient_id,
            'type' => 'General Check-up',
            'reason' => 'Routine check-up',
            'date' => '2026-08-17',
        ];

        foreach (['08:00 AM', '05:00 PM'] as $time) {
            $this->postJson('/api/appointments', [...$payload, 'time' => $time])
                ->assertCreated()->assertJsonPath('data.time', $time);
        }

        $appointment = Appointment::factory()->create(['status' => 'Pending']);
        foreach (['07:30 AM', '05:30 PM'] as $time) {
            $this->postJson('/api/appointments', [...$payload, 'time' => $time])
                ->assertUnprocessable()->assertJsonValidationErrors(['time']);
            $this->postJson("/api/appointments/{$appointment->id}/reschedule", ['date' => $payload['date'], 'time' => $time])
                ->assertUnprocessable()->assertJsonValidationErrors(['time']);
        }

        $this->postJson("/api/appointments/{$appointment->id}/reschedule", ['date' => $payload['date'], 'time' => '05:00 PM'])
            ->assertOk()->assertJsonPath('data.time', '05:00 PM');
    }
}
