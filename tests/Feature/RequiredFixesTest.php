<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Duty roster, clinic insights, rejected medical staff, department-matched
 * doctors, prescription removal, role restrictions, phone validation,
 * patient ordering, report dates and web → mobile appointment status.
 */
class RequiredFixesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PERMISSIONS = [
        'dashboard.view', 'schedules.view', 'schedules.create', 'schedules.update',
        'appointments.view', 'appointments.create', 'appointments.update', 'appointments.approve', 'appointments.reject',
        'consultations.view', 'consultations.create', 'consultations.update',
        'prescriptions.view', 'prescriptions.create', 'prescriptions.update', 'prescriptions.delete',
        'patients.view', 'patients.create', 'patients.update', 'reports.view', 'reports.export',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign_permissions',
        'users.view', 'audit_logs.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:15:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function roleWith(string $name, array $permissions = [], bool $system = false): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['description' => ucfirst($name), 'is_system' => $system]);
        $ids = collect($permissions)->map(function (string $permission) {
            [$module] = explode('.', $permission, 2);

            return Permission::firstOrCreate(['name' => $permission], ['module' => $module, 'label' => $permission])->id;
        })->all();
        $role->permissions()->syncWithoutDetaching($ids);

        return $role;
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => $this->roleWith('admin', self::ADMIN_PERMISSIONS, true)->id, 'status' => 'active']);
    }

    private function clinician(string $role, string $name, array $profile = []): User
    {
        $user = User::factory()->create(['name' => $name, 'role_id' => $this->roleWith($role)->id, 'status' => 'active']);
        if ($profile) {
            $user->staffProfile()->create($profile);
        }

        return $user;
    }

    private function appointment(array $overrides = []): Appointment
    {
        static $counter = 0;
        $counter++;

        return Appointment::create(array_merge([
            'reference' => 'APT-2026-'.str_pad((string) (500 + $counter), 3, '0', STR_PAD_LEFT),
            'patient' => "Patient {$counter}",
            'patient_id' => "24-70{$counter}",
            'type' => 'Check-up',
            'visit_type' => Appointment::VISIT_NEW,
            'reason' => 'Headache',
            'date' => now()->toDateString(),
            'time' => '11:00 AM',
            'staff' => '',
            'status' => 'Pending',
            'requested_on' => now()->toDateString(),
        ], $overrides));
    }

    // --- Dashboard: duty roster and on-duty staff ---------------------------------------------

    public function test_duty_roster_is_built_from_clinicians_and_todays_schedules(): void
    {
        $onDuty = $this->clinician('doctor', 'Dr. Morning');
        $later = $this->clinician('nurse', 'Nurse Afternoon');
        $rejected = $this->clinician('doctor', 'Dr. Rejected', ['credential_status' => 'Rejected']);
        StaffSchedule::create(['user_id' => $onDuty->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '12:00 PM', 'status' => 'Available']);
        StaffSchedule::create(['user_id' => $later->id, 'date' => now()->toDateString(), 'start_time' => '01:00 PM', 'end_time' => '05:00 PM', 'status' => 'Available']);

        $this->actingAs($this->admin(), 'sanctum');
        $roster = collect($this->getJson('/api/staff')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Dr. Morning', 'Nurse Afternoon'], $roster->keys()->sort()->values()->all());
        $this->assertFalse($roster->has($rejected->name));
        $this->assertSame('On duty', $roster['Dr. Morning']['status']);
        $this->assertSame('08:00 AM - 12:00 PM', $roster['Dr. Morning']['shift']);
        $this->assertSame('Off duty', $roster['Nurse Afternoon']['status']);

        $this->patchJson('/api/staff/status', ['name' => 'Dr. Morning', 'status' => 'Break'])
            ->assertOk()->assertJsonPath('data.status', 'Break');
        $this->assertSame('Break', collect($this->getJson('/api/staff')->json('data'))->firstWhere('name', 'Dr. Morning')['status']);

        $this->patchJson('/api/staff/status', ['name' => 'Dr. Rejected', 'status' => 'On duty'])->assertUnprocessable();
    }

    public function test_only_admin_can_change_dashboard_staff_status_even_with_schedule_permissions(): void
    {
        $doctor = $this->clinician('doctor', 'Dr. Read Only');
        StaffSchedule::create(['user_id' => $doctor->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '12:00 PM', 'status' => 'Available']);

        foreach (['doctor', 'nurse', 'front_desk', 'custom_staff'] as $roleName) {
            $role = $this->roleWith($roleName, ['schedules.view', 'schedules.update']);
            $viewer = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
            $this->actingAs($viewer, 'sanctum');

            $this->getJson('/api/staff')->assertOk()->assertJsonFragment(['name' => $doctor->name, 'status' => 'On duty']);
            $this->patchJson('/api/staff/status', ['user_id' => $doctor->id, 'status' => 'Break'])->assertForbidden();
            $this->patchJson('/api/staff/status', ['name' => $doctor->name, 'status' => 'Off duty'])->assertForbidden();
            $this->assertDatabaseMissing('staff', ['user_id' => $doctor->id]);
        }

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/staff/status', ['user_id' => $doctor->id, 'status' => 'Break'])
            ->assertOk()->assertJsonPath('data.status', 'Break');
        $this->assertDatabaseHas('staff', ['user_id' => $doctor->id, 'status' => 'Break']);
    }

    // --- Dashboard: clinic activity and peak hours ----------------------------------------------

    public function test_clinic_activity_and_peak_hours_come_from_clinic_records(): void
    {
        $this->appointment(['time' => '08:30 AM', 'status' => 'Completed']);
        $this->appointment(['time' => '09:00 AM', 'status' => 'Completed']);
        $this->appointment(['time' => '02:30 PM']);
        $this->appointment(['time' => '03:00 PM', 'status' => 'Rejected']);
        $this->appointment(['time' => '09:30 AM', 'date' => now()->subDays(60)->toDateString(), 'status' => 'Completed']);
        Consultation::create(['reference' => 'CONS-2026-700', 'date' => now()->toDateString(), 'time' => '10:30 AM', 'patient' => 'Walk In', 'status' => 'Completed']);

        $this->actingAs($this->admin(), 'sanctum');

        $activity = collect($this->getJson('/api/insights/activity')->assertOk()->json())->keyBy('label');
        $this->assertSame(4, $activity['Appointments']['count']);
        $this->assertSame(3, $activity['Appointments']['recentCount']);
        $this->assertSame(1, $activity['Consultations']['count']);
        $this->assertSame(100, $activity['Appointments']['percent']);

        $peak = collect($this->getJson('/api/insights/peak-hours')->assertOk()->json())->keyBy('label');
        $this->assertSame(2, $peak['08:00 AM - 10:00 AM']['count']);
        $this->assertSame(2, $peak['08:00 AM - 10:00 AM']['recentCount']);
        $this->assertSame(1, $peak['10:00 AM - 12:00 PM']['count']);
        $this->assertSame(1, $peak['02:00 PM - 04:00 PM']['count']);
        $this->assertSame(100, $peak['08:00 AM - 10:00 AM']['percent']);
    }

    public function test_peak_hours_use_the_current_school_week_and_exclude_holidays_and_duplicate_visits(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00')); // Sunday still belongs to Oct 19-23.
        config(['appointment_calendar.holidays.2026.2026-10-21' => 'School Holiday']);

        $appointment = $this->appointment(['date' => '2026-10-19', 'time' => '08:00 AM', 'status' => 'Completed']);
        $this->appointment(['date' => '2026-10-23', 'time' => '05:00 PM', 'status' => 'Completed']);
        foreach (['2026-10-16', '2026-10-18', '2026-10-21', '2026-10-24', '2026-10-25', '2026-10-26'] as $index => $date) {
            $this->appointment(['date' => $date, 'time' => '08:30 AM', 'status' => 'Completed']);
            Consultation::create(['reference' => "CONS-EXCLUDED-{$index}", 'date' => $date, 'time' => '10:00 AM', 'patient' => 'Excluded', 'status' => 'Completed']);
        }
        foreach (['Cancelled', 'Rejected', 'Expired', 'No-Show'] as $status) {
            $this->appointment(['date' => '2026-10-20', 'time' => '08:30 AM', 'status' => $status]);
        }
        Consultation::create(['reference' => 'CONS-LINKED', 'appointment_id' => $appointment->id, 'date' => '2026-10-19', 'time' => '08:30 AM', 'patient' => 'Linked', 'status' => 'Completed']);
        foreach (['10:00 AM', '12:00 PM', '02:00 PM', '04:00 PM'] as $index => $time) {
            Consultation::create(['reference' => "CONS-WALKIN-{$index}", 'date' => '2026-10-20', 'time' => $time, 'patient' => 'Walk In', 'status' => 'In Progress']);
        }

        $this->actingAs($this->admin(), 'sanctum');
        $rows = $this->getJson('/api/insights/peak-hours')->assertOk()->json();
        $this->assertSame([1, 1, 1, 1, 2], array_column($rows, 'count'));
        $this->assertSame([50, 50, 50, 50, 100], array_column($rows, 'percent'));

        $this->travelTo(Carbon::parse('2026-10-26 00:00:00')); // New Monday resets the week.
        $rows = $this->getJson('/api/insights/peak-hours')->assertOk()->json();
        $this->assertSame([1, 1, 0, 0, 0], array_column($rows, 'count'));
    }

    public function test_peak_hours_handle_a_school_week_spanning_two_years_and_empty_weeks(): void
    {
        $this->travelTo(Carbon::parse('2027-01-03 12:00:00'));
        $this->appointment(['date' => '2026-12-28', 'time' => '09:00 AM', 'status' => 'Completed']);
        $this->appointment(['date' => '2026-12-29', 'time' => '10:00 AM', 'status' => 'Completed']);
        foreach (['2026-12-30', '2026-12-31', '2027-01-01'] as $date) {
            $this->appointment(['date' => $date, 'time' => '09:00 AM', 'status' => 'Completed']);
        }
        $this->actingAs($this->admin(), 'sanctum');
        $rows = $this->getJson('/api/insights/peak-hours')->assertOk()->json();
        $this->assertSame([1, 1, 0, 0, 0], array_column($rows, 'count'));

        $this->travelTo(Carbon::parse('2027-01-04 00:00:00'));
        $rows = $this->getJson('/api/insights/peak-hours')->assertOk()->json();
        $this->assertSame([0, 0, 0, 0, 0], array_column($rows, 'count'));
        $this->assertSame([0, 0, 0, 0, 0], array_column($rows, 'percent'));
    }

    // --- Medical staff: rejected applications ------------------------------------------------------

    public function test_rejected_medical_staff_cannot_be_selected_or_assigned(): void
    {
        $rejected = $this->clinician('doctor', 'Dr. Turned Down', ['credential_status' => 'Rejected']);
        $approved = $this->clinician('doctor', 'Dr. Approved', ['credential_status' => 'Verified']);
        $appointment = $this->appointment();
        $this->actingAs($this->admin(), 'sanctum');

        $ids = collect($this->getJson('/api/staff-schedules/eligible-staff')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($rejected->id, $ids);

        $this->patchJson("/api/appointments/{$appointment->id}/assign", ['staff_id' => $rejected->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        $this->assertNull($appointment->fresh()->staff_id);

        $this->postJson('/api/consultations', ['patient' => 'Some Patient', 'staff_id' => $rejected->id, 'status' => 'Scheduled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);

        $this->postJson('/api/staff-schedules', [
            'user_id' => $rejected->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '12:00 PM', 'status' => 'Available',
        ])->assertUnprocessable();

        $this->postJson('/api/prescriptions', [
            'patient' => 'Some Patient', 'prescribed_by_id' => $rejected->id,
            'medications' => [['medicine_name' => 'Paracetamol', 'dosage' => '500 mg', 'frequency' => 'Every 6 hours']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['prescribed_by_id']);
    }

    // --- Appointments / consultations: department-matched doctors ---------------------------

    public function test_doctor_and_nurse_selection_follows_the_visit_department(): void
    {
        $dentist = $this->clinician('doctor', 'Dr. Dentist', ['license_type' => 'PRC Dentist License', 'specialization' => 'General Dentistry']);
        $physician = $this->clinician('doctor', 'Dr. Physician', ['license_type' => 'PRC Physician License']);
        $nurse = $this->clinician('nurse', 'Nurse Helper');
        foreach ([$dentist, $physician, $nurse] as $member) {
            StaffSchedule::create(['user_id' => $member->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '05:00 PM', 'status' => 'Available']);
        }
        $dental = $this->appointment(['type' => 'Dental concern']);
        $checkUp = $this->appointment(['type' => 'Check-up', 'time' => '01:00 PM']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->patchJson("/api/appointments/{$dental->id}/assign", ['staff_id' => $physician->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        $this->patchJson("/api/appointments/{$dental->id}/assign", ['staff_id' => $dentist->id])->assertOk();
        $this->patchJson("/api/appointments/{$checkUp->id}/assign", ['staff_id' => $dentist->id])->assertUnprocessable();
        $this->patchJson("/api/appointments/{$checkUp->id}/assign", ['staff_id' => $nurse->id])
            ->assertOk()->assertJsonPath('data.staffId', $nurse->id)->assertJsonPath('data.staffRole', 'nurse');

        $this->postJson('/api/consultations', ['patient' => 'Dental Patient', 'type' => 'Dental concern', 'staff_id' => $physician->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        $created = $this->postJson('/api/consultations', ['patient' => 'Dental Patient', 'type' => 'Dental concern', 'staff_id' => $dentist->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'Dental concern')
            ->assertJsonPath('data.department', 'Dental')
            ->json('data');

        $this->patchJson("/api/consultations/{$created['id']}", ['staff_id' => $physician->id])->assertUnprocessable();

        $eligible = collect($this->getJson('/api/staff-schedules/eligible-staff')->json('data'))->keyBy('id');
        $this->assertSame('Dental', $eligible[$dentist->id]['staffProfile']['department']);
        $this->assertSame('General Medicine', $eligible[$physician->id]['staffProfile']['department']);
    }

    public function test_booking_and_assignment_accept_only_eligible_nurses_of_the_service_department(): void
    {
        $dentalNurse = $this->clinician('nurse', 'Dental Nurse', ['specialization' => 'Dental Nursing', 'license_type' => 'PRC Nurse License']);
        $medicalNurse = $this->clinician('nurse', 'Medical Nurse', ['license_type' => 'PRC Nurse License']);
        $rejected = $this->clinician('nurse', 'Rejected Nurse', ['specialization' => 'Dental Nursing', 'credential_status' => 'Rejected']);
        $inactive = $this->clinician('nurse', 'Inactive Nurse', ['specialization' => 'Dental Nursing']);
        $inactive->update(['status' => 'inactive']);
        $dental = $this->appointment(['type' => 'Dental concern']);
        $this->actingAs($this->admin(), 'sanctum');

        $payload = [
            'patient' => 'Medical Booking', 'patient_id' => Patient::factory()->create(['name' => 'Medical Booking'])->patient_id, 'type' => 'General Check-up', 'reason' => 'Medical recheck',
            'date' => now()->addDays(2)->toDateString(), 'time' => '09:30 AM',
        ];
        StaffSchedule::create(['user_id' => $medicalNurse->id, 'date' => $payload['date'], 'start_time' => '08:00 AM', 'end_time' => '05:00 PM', 'status' => 'Available']);
        StaffSchedule::create(['user_id' => $dentalNurse->id, 'date' => now()->toDateString(), 'start_time' => '08:00 AM', 'end_time' => '05:00 PM', 'status' => 'Available']);
        foreach ([$dentalNurse, $rejected, $inactive] as $ineligible) {
            $this->postJson('/api/appointments', [...$payload, 'staff_id' => $ineligible->id])
                ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        }
        foreach ([$medicalNurse, $rejected, $inactive] as $ineligible) {
            $this->patchJson("/api/appointments/{$dental->id}/assign", ['staff_id' => $ineligible->id])
                ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        }
        $this->postJson('/api/appointments', [...$payload, 'staff_id' => $medicalNurse->id])
            ->assertCreated()->assertJsonPath('data.staffId', $medicalNurse->id)->assertJsonPath('data.staffRole', 'nurse');
        $this->patchJson("/api/appointments/{$dental->id}/assign", ['staff_id' => $dentalNurse->id])
            ->assertOk()->assertJsonPath('data.staffId', $dentalNurse->id);
        $this->postJson('/api/appointments', [...$payload, 'type' => 'Follow-up Consultation', 'time' => '10:30 AM', 'staff_id' => $medicalNurse->id])
            ->assertCreated()->assertJsonPath('data.staffId', $medicalNurse->id);
        $this->postJson('/api/appointments', [...$payload, 'type' => 'Follow-up Consultation', 'staff_id' => $dentalNurse->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
    }

    public function test_doctor_can_only_call_in_patients_after_assignment(): void
    {
        $this->roleWith('doctor', ['consultations.create']);
        $dentist = $this->clinician('doctor', 'Dr. Tooth', ['license_type' => 'PRC Dentist License']);
        $checkUp = $this->appointment(['type' => 'Check-up', 'status' => 'Approved', 'queue_number' => 1, 'checked_in_at' => now()]);
        $dental = $this->appointment(['type' => 'Dental concern', 'status' => 'Approved', 'queue_number' => 2, 'checked_in_at' => now(), 'time' => '01:00 PM']);

        $this->actingAs($dentist, 'sanctum');
        $this->postJson("/api/queue/{$checkUp->id}/serve")->assertForbidden();
        $this->assertNull($checkUp->fresh()->staff_id);

        $this->postJson("/api/queue/{$dental->id}/serve")->assertForbidden();
        $this->assertNull($dental->fresh()->staff_id);
        $dental->update(['staff_id' => $dentist->id, 'staff' => $dentist->name]);
        $this->postJson("/api/queue/{$dental->id}/serve")->assertCreated()->assertJsonPath('data.type', 'Dental concern');
        $this->assertSame($dentist->id, $dental->fresh()->staff_id);
    }

    public function test_rejected_staff_cannot_issue_certificates_and_dental_follow_ups_stay_dental(): void
    {
        $this->roleWith('admin', ['medical_certificates.view', 'medical_certificates.create', 'medical_certificates.update', 'medical_certificates.approve']);
        $admin = $this->admin();
        $this->clinician('doctor', 'Dr. Turned Down', ['credential_status' => 'Rejected']);
        $consultation = Consultation::create([
            'reference' => 'CONS-2026-701', 'date' => now()->toDateString(), 'time' => '09:00 AM', 'patient' => 'Cert Patient',
            'patient_id' => '24-700001', 'status' => 'Completed', 'type' => 'Dental concern', 'diagnosis' => 'Caries',
        ]);
        $this->actingAs($admin, 'sanctum');

        $id = $this->postJson('/api/medical-certificates', [
            'patient' => 'Cert Patient', 'patient_id' => '24-700001', 'consultation_id' => $consultation->id, 'purpose' => 'Excuse',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/medical-certificates/{$id}/approve")->assertOk();
        $this->postJson("/api/medical-certificates/{$id}/issue", ['issued_by' => 'Dr. Turned Down'])
            ->assertUnprocessable()->assertJsonValidationErrors(['issued_by']);

        $this->roleWith('admin', ['consultations.update']);
        $this->postJson("/api/consultations/{$consultation->id}/follow-up", ['date' => now()->addDays(3)->toDateString(), 'time' => '10:00 AM'])
            ->assertOk();
        $this->assertSame('Dental concern', Appointment::where('previous_consultation_id', $consultation->id)->value('type'));
    }

    // --- Prescriptions: remove -----------------------------------------------------------------

    public function test_prescription_can_be_removed_with_permission(): void
    {
        $doctor = $this->clinician('doctor', 'Dr. Writer');
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $id = $this->postJson('/api/prescriptions', [
            'patient' => 'Rx Patient', 'prescribed_by_id' => $doctor->id,
            'medications' => [['medicine_name' => 'Amoxicillin', 'dosage' => '500 mg', 'frequency' => 'Every 8 hours']],
        ])->assertCreated()->json('data.id');

        $viewer = User::factory()->create(['role_id' => $this->roleWith('nurse', ['prescriptions.view'])->id, 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/prescriptions/{$id}")->assertForbidden();
        $this->assertDatabaseHas('prescriptions', ['id' => $id]);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/prescriptions/{$id}")->assertOk();
        $this->assertDatabaseMissing('prescriptions', ['id' => $id]);
        $this->assertDatabaseMissing('prescription_medications', ['prescription_id' => $id]);
        $this->assertSame(0, Prescription::count());
    }

    // --- Roles & permissions ---------------------------------------------------------------------

    public function test_role_creation_blocks_admin_lookalikes_duplicates_and_admin_permissions(): void
    {
        $this->roleWith('front_desk', [], true);
        $doctorRole = $this->roleWith('doctor', [], true);
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['Administrator', 'clinic-admin', 'Super Admin', 'root'] as $name) {
            $this->postJson('/api/roles', ['name' => $name])
                ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        }
        $this->postJson('/api/roles', ['name' => 'Front-Desk'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $usersUpdate = Permission::where('name', 'roles.update')->value('id');
        $this->postJson('/api/roles', ['name' => 'pharmacist', 'permissions' => [$usersUpdate]])
            ->assertUnprocessable()->assertJsonValidationErrors(['permissions']);
        $this->assertDatabaseMissing('roles', ['name' => 'pharmacist']);

        $this->putJson("/api/roles/{$doctorRole->id}", ['name' => 'physician'])->assertStatus(409);

        $custom = $this->postJson('/api/roles', ['name' => 'pharmacist'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/roles/{$custom}")->assertNoContent();
        $this->deleteJson("/api/roles/{$doctorRole->id}")->assertStatus(409);
    }

    // --- Phone numbers, patient ordering, report dates --------------------------------------------

    public function test_phone_numbers_must_have_exactly_eleven_digits(): void
    {
        $patient = Patient::create(['patient_id' => '24-000123', 'name' => 'Phone Test', 'type' => 'Student', 'course_dept' => 'General', 'status' => 'Active']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->putJson("/api/patients/{$patient->id}", ['contact' => '0917-123-45678'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact']);
        $this->putJson("/api/patients/{$patient->id}", ['contact' => '0917123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact']);
        $this->putJson("/api/patients/{$patient->id}", ['contact' => '0917-123-4567'])->assertOk();
    }

    public function test_patient_registry_is_sorted_by_patient_id(): void
    {
        foreach (['24-000300' => 'Ann', '23-000100' => 'Zed', '24-000200' => 'Bea'] as $id => $name) {
            Patient::create(['patient_id' => $id, 'name' => $name, 'type' => 'Student', 'course_dept' => 'General', 'status' => 'Active']);
        }
        $this->actingAs($this->admin(), 'sanctum');

        $ids = collect($this->getJson('/api/patients')->assertOk()->json('data'))->pluck('patientId')->all();
        $this->assertSame(['23-000100', '24-000200', '24-000300'], $ids);
    }

    public function test_report_export_includes_dates_and_report_period(): void
    {
        $this->appointment(['date' => '2026-10-15', 'requested_on' => '2026-10-10']);
        Patient::create(['patient_id' => '24-000999', 'name' => 'Report Patient', 'type' => 'Student', 'course_dept' => 'General', 'status' => 'Active']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson('/api/reports/export/appointments?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertJsonPath('meta.startDate', '2026-10-01')
            ->assertJsonPath('meta.endDate', '2026-10-31')
            ->assertJsonPath('dateColumns', ['Date', 'Requested On'])
            ->assertJsonPath('data.0.Date', '2026-10-15')
            ->assertJsonPath('data.0.Requested On', '2026-10-10');

        $this->getJson('/api/reports/export/patients')
            ->assertOk()
            ->assertJsonPath('data.0.Registered On', now()->toDateString());
    }

    // --- Mobile: status set on the web reaches the student's app ------------------------------

    public function test_appointment_rejected_on_the_web_is_returned_as_rejected_to_the_student(): void
    {
        $patient = Patient::create(['patient_id' => '24-555555', 'name' => 'Mobile Student', 'type' => 'Student', 'course_dept' => 'General', 'status' => 'Active']);
        $student = User::factory()->create(['role_id' => $this->roleWith('student', [], true)->id, 'patient_id' => $patient->patient_id, 'status' => 'active']);
        $appointment = $this->appointment(['patient' => $patient->name, 'patient_id' => $patient->patient_id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'Rejected', 'note' => 'Rejected — Fully booked'])
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/me/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'Rejected');
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'title' => 'Appointment Rejected']);
    }
}
