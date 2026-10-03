<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClinicEvent;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Queue (FIFO), doctor assignment, shared time slots, follow-ups, calendar
 * closures, course dropdown validation, staff credentials and clinic roles.
 */
class ClinicWorkflowTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => $this->roleWith('admin', [
                'appointments.view', 'appointments.create', 'appointments.update', 'appointments.approve',
                'consultations.view', 'consultations.create', 'consultations.update',
                'patients.view', 'patients.create', 'patients.update',
                'schedules.view', 'schedules.create', 'users.update',
            ])->id,
            'status' => 'active',
        ]);
    }

    private function doctor(string $name = 'Dr. Queue'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => $this->roleWith('doctor', ['schedules.view'])->id,
            'status' => 'active',
        ]);
    }

    private function appointment(array $overrides = []): Appointment
    {
        static $counter = 0;
        $counter++;

        return Appointment::create(array_merge([
            'reference' => 'APT-2026-'.str_pad((string) (800 + $counter), 3, '0', STR_PAD_LEFT),
            'patient' => "Patient {$counter}",
            'patient_id' => "24-90{$counter}",
            'type' => 'Check-up',
            'visit_type' => Appointment::VISIT_NEW,
            'reason' => 'Headache',
            'date' => now()->toDateString(),
            'time' => '09:00 AM',
            'staff' => '',
            'status' => 'Approved',
            'requested_on' => now()->toDateString(),
        ], $overrides));
    }

    // --- Queue (FIFO) ----------------------------------------------------------

    public function test_queue_serves_checked_in_patients_first_in_first_out(): void
    {
        $admin = $this->admin();
        $late = $this->appointment(['time' => '09:00 AM', 'patient' => 'Late Slot']);
        $firstBooked = $this->appointment(['time' => '08:00 AM', 'patient' => 'First Booked']);
        $secondBooked = $this->appointment(['time' => '08:00 AM', 'patient' => 'Second Booked']);
        $this->appointment(['time' => '10:00 AM', 'patient' => 'Not Arrived']);
        $this->appointment(['time' => '08:30 AM', 'patient' => 'Still Pending', 'status' => 'Pending']);

        $this->actingAs($admin, 'sanctum');

        // Arrival order differs from schedule order.
        $this->postJson("/api/queue/{$late->id}/check-in")->assertOk()->assertJsonPath('data.queueNumber', 1);
        $this->postJson("/api/queue/{$secondBooked->id}/check-in")->assertOk()->assertJsonPath('data.queueNumber', 2);
        $this->postJson("/api/queue/{$firstBooked->id}/check-in")->assertOk()->assertJsonPath('data.queueNumber', 3);
        $this->postJson("/api/queue/{$late->id}/check-in")->assertStatus(409);

        $response = $this->getJson('/api/queue')->assertOk();
        $waiting = collect($response->json('data'))->where('queueStatus', 'Waiting')->values();

        // Earliest slot first; within the same slot, whoever checked in first.
        $this->assertSame(['Second Booked', 'First Booked', 'Late Slot'], $waiting->pluck('patient')->all());
        $this->assertSame([1, 2, 3], $waiting->pluck('position')->all());
        $response->assertJsonPath('meta.waiting', 3)
            ->assertJsonPath('meta.expected', 1)
            ->assertJsonPath('meta.awaitingApproval', 1);

        // Nobody can be called ahead of the head of the line.
        $this->postJson("/api/queue/{$late->id}/serve")
            ->assertStatus(409)
            ->assertJsonPath('nextAppointmentId', $secondBooked->id);

        $consultationId = $this->postJson("/api/queue/{$secondBooked->id}/serve")
            ->assertCreated()
            ->assertJsonPath('data.status', 'In Progress')
            ->assertJsonPath('data.time', '08:00 AM')
            ->assertJsonPath('data.appointmentId', $secondBooked->id)
            ->json('data.id');

        $this->getJson('/api/queue')->assertJsonPath('meta.inConsultation', 1)->assertJsonPath('meta.waiting', 2);

        $this->postJson("/api/consultations/{$consultationId}/complete", [
            'chiefComplaint' => 'Headache', 'diagnosis' => 'Tension headache', 'treatment' => 'Rest',
        ])->assertOk();

        $this->assertSame('Completed', $secondBooked->fresh()->status);
        $this->getJson('/api/queue')->assertJsonPath('meta.served', 1);
    }

    public function test_each_doctor_has_their_own_fifo_line(): void
    {
        $admin = $this->admin();
        $drA = $this->doctor('Dr. A');
        $drB = $this->doctor('Dr. B');
        $forA = $this->appointment(['time' => '08:00 AM', 'staff_id' => $drA->id, 'staff' => 'Dr. A']);
        $forB = $this->appointment(['time' => '09:00 AM', 'staff_id' => $drB->id, 'staff' => 'Dr. B']);

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/queue/{$forA->id}/check-in")->assertOk();
        $this->postJson("/api/queue/{$forB->id}/check-in")->assertOk();

        // Dr. B's first patient is not blocked by Dr. A's line.
        $this->postJson("/api/queue/{$forB->id}/serve")->assertCreated()->assertJsonPath('data.staff', 'Dr. B');
    }

    public function test_only_approved_appointments_for_today_can_check_in(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $pending = $this->appointment(['status' => 'Pending']);
        $tomorrow = $this->appointment(['date' => now()->addDay()->toDateString()]);

        $this->postJson("/api/queue/{$pending->id}/check-in")->assertUnprocessable();
        $this->postJson("/api/queue/{$tomorrow->id}/check-in")->assertUnprocessable();
    }

    public function test_front_desk_cannot_start_consultations_from_the_queue(): void
    {
        $frontDesk = User::factory()->create([
            'role_id' => $this->roleWith('front_desk', ['appointments.view', 'appointments.update'])->id,
        ]);
        $appointment = $this->appointment();

        $this->actingAs($frontDesk, 'sanctum');
        $this->postJson("/api/queue/{$appointment->id}/check-in")->assertOk();
        $this->postJson("/api/queue/{$appointment->id}/serve")->assertForbidden();
    }

    // --- Doctor assignment ---------------------------------------------------------

    public function test_doctor_can_be_assigned_and_is_carried_into_the_consultation(): void
    {
        $admin = $this->admin();
        $doctor = $this->doctor('Dr. Assigned');
        $appointment = $this->appointment();
        $consultation = Consultation::create([
            'reference' => 'CONS-2026-900', 'date' => $appointment->date, 'time' => $appointment->time,
            'patient' => $appointment->patient, 'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id, 'status' => 'Scheduled',
        ]);

        $this->actingAs($admin, 'sanctum');
        $this->patchJson("/api/appointments/{$appointment->id}/assign", ['staff_id' => $doctor->id])
            ->assertOk()
            ->assertJsonPath('data.staff', 'Dr. Assigned')
            ->assertJsonPath('data.staffId', $doctor->id)
            ->assertJsonPath('data.staffRole', 'doctor');

        $this->assertDatabaseHas('consultations', ['id' => $consultation->id, 'staff_id' => $doctor->id, 'staff' => 'Dr. Assigned']);
        $this->assertDatabaseHas('notifications', ['user_id' => $doctor->id, 'title' => 'Patient Assigned']);

        $this->getJson("/api/appointments?staff_id={$doctor->id}")->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/appointments/{$appointment->id}/assign", ['staff_id' => null])
            ->assertOk()
            ->assertJsonPath('data.staff', 'Unassigned');
    }

    public function test_assignment_rejects_non_clinicians_conflicts_and_unavailable_doctors(): void
    {
        $admin = $this->admin();
        $doctor = $this->doctor('Dr. Busy');
        $taken = $this->appointment(['time' => '10:00 AM', 'staff_id' => $doctor->id, 'staff' => 'Dr. Busy']);
        $target = $this->appointment(['time' => '10:00 AM']);
        $afternoon = $this->appointment(['time' => '02:00 PM']);

        StaffSchedule::create([
            'user_id' => $doctor->id, 'date' => now()->toDateString(),
            'start_time' => '01:00 PM', 'end_time' => '05:00 PM', 'status' => 'Unavailable',
        ]);

        $this->actingAs($admin, 'sanctum');

        $this->patchJson("/api/appointments/{$target->id}/assign", ['staff_id' => $admin->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);

        $this->patchJson("/api/appointments/{$target->id}/assign", ['staff_id' => $doctor->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', "The selected doctor/staff (Dr. Busy) already has an appointment booked on {$target->date->format('Y-m-d')} at 10:00 AM.");

        $this->patchJson("/api/appointments/{$afternoon->id}/assign", ['staff_id' => $doctor->id])
            ->assertUnprocessable();

        $this->assertNull($target->fresh()->staff_id);
        $this->assertSame($doctor->id, $taken->fresh()->staff_id);
    }

    public function test_booking_with_staff_id_assigns_the_doctor_and_records_patient_id(): void
    {
        $doctor = $this->doctor('Dr. Booked');
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/appointments', [
            'patient' => 'Walk In', 'patient_id' => '24-123123', 'type' => 'Fever', 'reason' => 'Fever',
            'date' => now()->addDays(2)->toDateString(), 'time' => '09:30 AM', 'staff_id' => $doctor->id,
        ])->assertCreated()
            ->assertJsonPath('data.staff', 'Dr. Booked')
            ->assertJsonPath('data.patientId', '24-123123')
            ->assertJsonPath('data.visitType', 'New Consultation');
    }

    // --- Shared time slots -------------------------------------------------------------

    public function test_consultations_use_the_same_time_slots_as_appointments(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/consultations', ['patient' => 'Slot Test', 'time' => '10:47 AM'])
            ->assertUnprocessable()->assertJsonValidationErrors(['time']);

        $this->postJson('/api/consultations', ['patient' => 'Slot Test', 'time' => '2:30 PM'])
            ->assertCreated()->assertJsonPath('data.time', '02:30 PM');

        $this->postJson('/api/consultations', ['patient' => 'Slot Test', 'time' => '14:00'])
            ->assertCreated()->assertJsonPath('data.time', '02:00 PM');

        $defaultTime = $this->postJson('/api/consultations', ['patient' => 'Slot Test'])->assertCreated()->json('data.time');
        $this->assertContains($defaultTime, Appointment::TIME_SLOTS);
    }

    public function test_consultation_from_an_appointment_keeps_its_slot_doctor_and_visit_type(): void
    {
        $doctor = $this->doctor('Dr. Slot');
        $appointment = $this->appointment([
            'time' => '08:00 AM', 'staff_id' => $doctor->id, 'staff' => 'Dr. Slot',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
        ]);
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/consultations', [
            'patient' => $appointment->patient, 'appointment_id' => $appointment->id,
        ])->assertCreated()
            ->assertJsonPath('data.time', '08:00 AM')
            ->assertJsonPath('data.staff', 'Dr. Slot')
            ->assertJsonPath('data.visitType', 'Follow-up Consultation')
            ->assertJsonPath('data.isFollowUp', true);
    }

    public function test_staff_shifts_start_at_eight_and_overlaps_are_compared_as_times(): void
    {
        $doctor = $this->doctor();
        $this->actingAs($this->admin(), 'sanctum');
        $date = now()->addDays(3)->toDateString();

        $this->postJson('/api/staff-schedules', [
            'user_id' => $doctor->id, 'date' => $date, 'start_time' => '7:30 AM', 'end_time' => '12:00 PM',
        ])->assertUnprocessable()->assertJsonValidationErrors(['start_time']);

        $this->postJson('/api/staff-schedules', [
            'user_id' => $doctor->id, 'date' => $date, 'start_time' => '8:00 AM', 'end_time' => '12:00 PM',
        ])->assertCreated()->assertJsonPath('data.startTime', '08:00 AM');

        // 10:00 AM - 02:00 PM overlaps 08:00 AM - 12:00 PM (string comparison got this wrong).
        $this->postJson('/api/staff-schedules', [
            'user_id' => $doctor->id, 'date' => $date, 'start_time' => '10:00 AM', 'end_time' => '02:00 PM',
        ])->assertStatus(409);

        $this->postJson('/api/staff-schedules', [
            'user_id' => $doctor->id, 'date' => $date, 'start_time' => '01:00 PM', 'end_time' => '05:00 PM',
        ])->assertCreated();
    }

    public function test_schedule_list_shows_the_doctors_assigned_patients(): void
    {
        $doctor = $this->doctor('Dr. Sched');
        StaffSchedule::create([
            'user_id' => $doctor->id, 'date' => now()->toDateString(),
            'start_time' => '08:00 AM', 'end_time' => '12:00 PM', 'status' => 'Available',
        ]);
        $this->appointment(['time' => '09:00 AM', 'staff_id' => $doctor->id, 'staff' => 'Dr. Sched', 'patient' => 'In Shift']);
        $this->appointment(['time' => '02:00 PM', 'staff_id' => $doctor->id, 'staff' => 'Dr. Sched', 'patient' => 'Outside Shift']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson("/api/staff-schedules?user_id={$doctor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.0.bookedAppointments')
            ->assertJsonPath('data.0.bookedAppointments.0.patient', 'In Shift');
    }

    // --- Follow-up consultations -----------------------------------------------------------

    public function test_completing_with_follow_up_books_a_linked_follow_up_visit(): void
    {
        $doctor = $this->doctor('Dr. Follow');
        $patient = Patient::factory()->create();
        $consultation = Consultation::create([
            'reference' => 'CONS-2026-950', 'date' => now()->toDateString(), 'time' => '09:00 AM',
            'patient' => $patient->name, 'patient_id' => $patient->patient_id,
            'staff_id' => $doctor->id, 'staff' => 'Dr. Follow', 'status' => 'In Progress',
        ]);
        $followUpDate = now()->addWeek()->toDateString();

        $this->actingAs($this->admin(), 'sanctum');
        $response = $this->postJson("/api/consultations/{$consultation->id}/complete", [
            'chiefComplaint' => 'Cough', 'diagnosis' => 'Bronchitis', 'treatment' => 'Antibiotics',
            'followUpRequired' => true, 'followUpDate' => $followUpDate, 'followUpTime' => '10:00 AM',
            'followUpNotes' => 'Re-check lungs',
        ])->assertOk()
            ->assertJsonPath('data.followUpRequired', true)
            ->assertJsonPath('data.followUpDate', $followUpDate)
            ->assertJsonPath('data.followUpAppointment.time', '10:00 AM')
            ->assertJsonPath('data.followUpAppointment.status', 'Approved');

        $followUp = Appointment::find($response->json('data.followUpAppointmentId'));
        $this->assertSame(Appointment::VISIT_FOLLOW_UP, $followUp->visit_type);
        $this->assertSame($consultation->id, $followUp->previous_consultation_id);
        $this->assertSame($doctor->id, $followUp->staff_id);

        // The doctor sees why the patient is returning.
        $this->getJson("/api/appointments/{$followUp->id}")
            ->assertOk()
            ->assertJsonPath('data.isFollowUp', true)
            ->assertJsonPath('data.previousConsultation.reference', 'CONS-2026-950')
            ->assertJsonPath('data.previousConsultation.diagnosis', 'Bronchitis');
    }

    public function test_follow_up_can_be_scheduled_after_completion_and_conflicts_are_checked(): void
    {
        $doctor = $this->doctor('Dr. Later');
        $consultation = Consultation::create([
            'reference' => 'CONS-2026-960', 'date' => now()->toDateString(), 'time' => '09:00 AM',
            'patient' => 'Later Patient', 'patient_id' => '24-777000', 'staff_id' => $doctor->id,
            'staff' => 'Dr. Later', 'status' => 'Completed', 'follow_up_required' => true, 'diagnosis' => 'Sprain',
        ]);
        $date = now()->addDays(5)->toDateString();
        $this->appointment(['date' => $date, 'time' => '11:00 AM', 'staff_id' => $doctor->id, 'staff' => 'Dr. Later']);

        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson("/api/consultations/{$consultation->id}/follow-up", ['date' => $date, 'time' => '11:00 AM'])
            ->assertUnprocessable();

        $this->postJson("/api/consultations/{$consultation->id}/follow-up", ['date' => $date, 'time' => '01:30 PM'])
            ->assertOk()
            ->assertJsonPath('data.followUpAppointment.time', '01:30 PM');

        $this->postJson("/api/consultations/{$consultation->id}/follow-up", ['date' => $date, 'time' => '02:30 PM'])
            ->assertStatus(409);
    }

    public function test_follow_up_booking_links_the_previous_consultation_of_the_same_patient(): void
    {
        $previous = Consultation::create([
            'reference' => 'CONS-2026-970', 'date' => now()->subWeek()->toDateString(), 'time' => '09:00 AM',
            'patient' => 'Returning', 'patient_id' => '24-555111', 'status' => 'Completed', 'diagnosis' => 'Allergy',
        ]);
        $this->actingAs($this->admin(), 'sanctum');
        $date = now()->addDays(4)->toDateString();

        $this->postJson('/api/appointments', [
            'patient' => 'Someone Else', 'patient_id' => '24-999999', 'type' => 'Check-up', 'reason' => 'Recheck',
            'date' => $date, 'time' => '09:00 AM', 'visit_type' => 'Follow-up Consultation',
            'previous_consultation_id' => $previous->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['previous_consultation_id']);

        $this->postJson('/api/appointments', [
            'patient' => 'Returning', 'patient_id' => '24-555111', 'type' => 'Check-up', 'reason' => 'Recheck',
            'date' => $date, 'time' => '09:00 AM', 'previous_consultation_id' => $previous->id,
        ])->assertCreated()
            ->assertJsonPath('data.visitType', 'Follow-up Consultation')
            ->assertJsonPath('data.previousConsultation.diagnosis', 'Allergy');

        // Older clients that send the "Follow-up" type still get a follow-up visit.
        $this->postJson('/api/appointments', [
            'patient' => 'Legacy', 'type' => 'Follow-up', 'reason' => 'Recheck', 'date' => $date, 'time' => '10:00 AM',
        ])->assertCreated()->assertJsonPath('data.visitType', 'Follow-up Consultation');
    }

    public function test_front_desk_sees_which_visit_a_follow_up_is_for_but_not_the_diagnosis(): void
    {
        $previous = Consultation::create([
            'reference' => 'CONS-2026-980', 'date' => now()->subWeek()->toDateString(), 'time' => '09:00 AM',
            'patient' => 'Private', 'patient_id' => '24-444222', 'status' => 'Completed', 'diagnosis' => 'Confidential Dx',
        ]);
        $followUp = $this->appointment([
            'patient' => 'Private', 'patient_id' => '24-444222',
            'visit_type' => Appointment::VISIT_FOLLOW_UP, 'previous_consultation_id' => $previous->id,
        ]);
        $frontDesk = User::factory()->create([
            'role_id' => $this->roleWith('front_desk', ['appointments.view', 'appointments.create'])->id,
        ]);

        $this->actingAs($frontDesk, 'sanctum');

        $this->getJson("/api/appointments/{$followUp->id}")
            ->assertOk()
            ->assertJsonPath('data.previousConsultation.reference', 'CONS-2026-980')
            ->assertJsonMissingPath('data.previousConsultation.diagnosis');

        $this->getJson('/api/appointments/follow-up-options?patient_id=24-444222')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'CONS-2026-980')
            ->assertJsonMissingPath('data.0.diagnosis');

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/appointments/follow-up-options?patient_id=24-444222')
            ->assertJsonPath('data.0.diagnosis', 'Confidential Dx');
    }

    // --- Calendar closures -------------------------------------------------------------------

    public function test_non_working_calendar_ranges_block_bookings_on_every_day_in_range(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $start = now()->addDays(10)->startOfDay();

        ClinicEvent::create([
            'title' => 'Semestral Break', 'type' => 'Non-Working Day', 'status' => 'Scheduled', 'all_day' => true,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(3)->toDateString(), 'date' => '',
        ]);
        ClinicEvent::create([
            'title' => 'Wellness Talk', 'type' => 'Seminar', 'status' => 'Scheduled',
            'start_date' => $start->copy()->addDays(5)->toDateString(), 'end_date' => $start->copy()->addDays(5)->toDateString(), 'date' => '',
        ]);

        $payload = ['patient' => 'Range Test', 'type' => 'Check-up', 'reason' => 'x', 'time' => '09:00 AM'];

        $this->postJson('/api/appointments', [...$payload, 'date' => $start->copy()->addDays(2)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => "The clinic is closed on {$start->copy()->addDays(2)->toDateString()} (Non-Working Day: Semestral Break). Please choose another date."]);

        $this->postJson('/api/appointments', [...$payload, 'date' => $start->copy()->addDays(4)->toDateString()])->assertCreated();
        $this->postJson('/api/appointments', [...$payload, 'date' => $start->copy()->addDays(5)->toDateString(), 'time' => '10:00 AM'])->assertCreated();
    }

    // --- Course / department dropdown --------------------------------------------------------

    public function test_academic_programs_are_listed_and_enforced_on_patient_forms(): void
    {
        $programs = $this->getJson('/api/academic-programs')->assertOk()->json('data');
        $this->assertNotEmpty($programs);
        $this->assertArrayHasKey('department', $programs[0]);
        $validCourse = $programs[0]['courses'][0];

        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/patients', ['name' => 'Typed Course', 'type' => 'Student', 'courseDept' => 'BS Made Up'])
            ->assertUnprocessable()->assertJsonValidationErrors(['courseDept']);

        $id = $this->postJson('/api/patients', ['name' => 'Picked Course', 'type' => 'Student', 'courseDept' => $validCourse])
            ->assertCreated()->json('data.id');

        $legacy = Patient::factory()->create(['course_dept' => 'Old Free Text Course']);
        $this->putJson("/api/patients/{$legacy->id}", ['courseDept' => 'Old Free Text Course', 'name' => 'Kept'])->assertOk();
        $this->putJson("/api/patients/{$legacy->id}", ['courseDept' => 'Another Typo'])->assertUnprocessable();
        $this->putJson("/api/patients/{$id}", ['courseDept' => $validCourse])->assertOk();
    }

    // --- Staff credentials -----------------------------------------------------------------------

    public function test_doctor_submits_prc_license_and_admin_verifies_it(): void
    {
        $doctor = $this->doctor('Dr. Licensed');
        $admin = $this->admin();

        $this->actingAs($doctor, 'sanctum');
        $this->putJson('/api/me/staff-profile', ['licenseType' => 'PRC Physician License', 'licenseNumber' => '12345'])
            ->assertUnprocessable()->assertJsonValidationErrors(['licenseNumber']);
        $this->putJson('/api/me/staff-profile', ['licenseType' => 'PRC Nurse License', 'licenseNumber' => '1234567'])
            ->assertUnprocessable()->assertJsonValidationErrors(['licenseType']);

        $this->putJson('/api/me/staff-profile', [
            'specialization' => 'Family Medicine', 'licenseType' => 'PRC Physician License',
            'licenseNumber' => '0123456', 'licenseExpiresAt' => now()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.credentials.status', 'Pending Verification');

        $this->postJson("/api/clinic-staff/{$doctor->id}/verify", ['status' => 'Verified'])->assertForbidden();

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/clinic-staff/{$doctor->id}/verify", ['status' => 'Rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes']);
        $this->postJson("/api/clinic-staff/{$doctor->id}/verify", ['status' => 'Verified'])
            ->assertOk()
            ->assertJsonPath('data.credentials.status', 'Verified')
            ->assertJsonPath('data.credentials.verifiedBy', $admin->name);

        $this->getJson("/api/clinic-staff/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.credentials.licenseNumber', '0123456')
            ->assertJsonPath('data.specialization', 'Family Medicine');

        // Changing the license requires verification again.
        $this->actingAs($doctor, 'sanctum');
        $this->putJson('/api/me/staff-profile', ['licenseType' => 'PRC Physician License', 'licenseNumber' => '0123457'])
            ->assertOk()->assertJsonPath('data.credentials.status', 'Pending Verification');
    }

    public function test_expired_licenses_cannot_be_verified_and_directory_lists_clinic_staff(): void
    {
        $nurse = User::factory()->create(['role_id' => $this->roleWith('nurse')->id, 'status' => 'active']);
        $nurse->staffProfile()->create([
            'license_type' => 'PRC Nurse License', 'license_number' => '0999999',
            'license_expires_at' => now()->subDay()->toDateString(), 'credential_status' => 'Pending Verification',
        ]);
        $student = User::factory()->create(['role_id' => $this->roleWith('student')->id]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/clinic-staff/{$nurse->id}/verify", ['status' => 'Verified'])->assertUnprocessable();
        $this->getJson("/api/clinic-staff/{$student->id}")->assertNotFound();

        $ids = collect($this->getJson('/api/clinic-staff')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($nurse->id, $ids);
        $this->assertNotContains($student->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    // --- Clinic roles ---------------------------------------------------------------------------

    public function test_front_desk_nurse_and_doctor_permissions_are_distinct(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $permissions = fn (string $role) => Role::where('name', $role)->firstOrFail()->permissions()->pluck('name');

        $frontDesk = $permissions('front_desk');
        $this->assertTrue($frontDesk->contains('appointments.approve'));
        $this->assertTrue($frontDesk->contains('patients.update'));
        $this->assertFalse($frontDesk->contains('consultations.view'));
        $this->assertFalse($frontDesk->contains('medical_records.view'));

        $nurse = $permissions('nurse');
        $this->assertTrue($nurse->contains('consultations.update'));
        $this->assertFalse($nurse->contains('prescriptions.create'));

        $doctor = $permissions('doctor');
        $this->assertTrue($doctor->contains('prescriptions.create'));
        $this->assertTrue($doctor->contains('medical_certificates.approve'));
        $this->assertTrue($doctor->contains('appointments.update'));

        $this->assertTrue((bool) Role::where('name', 'front_desk')->value('is_system'));
    }
}
