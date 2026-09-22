<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['description' => 'Admin']);
        $perms = [
            'reports.view',
            'reports.export',
        ];
        $permIds = [];
        foreach ($perms as $p) {
            $permIds[] = Permission::firstOrCreate(['name' => $p, 'module' => 'reports'], ['label' => $p])->id;
        }
        $role->permissions()->sync($permIds);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_admin_can_fetch_appointments_report(): void
    {
        $admin = $this->createAdmin();

        $patient = Patient::create([
            'patient_id' => '24-001234',
            'name' => 'Juan Dela Cruz',
            'type' => 'Student',
            'course_dept' => 'BS Information Technology',
            'contact' => '0912-345-6789',
            'status' => 'Active',
        ]);

        Appointment::create([
            'reference' => 'APT-2026-0001',
            'patient' => 'Juan Dela Cruz',
            'patient_id' => '24-001234',
            'staff' => 'Dr. Santos',
            'type' => 'Check-up',
            'reason' => 'Regular checkup',
            'date' => '2026-09-22',
            'time' => '10:00 AM',
            'status' => 'Approved',
            'requested_on' => '2026-09-22',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/appointments');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'reference', 'patient', 'patientId', 'staff', 'type', 'reason', 'date', 'time', 'status'],
                ],
                'meta' => ['total', 'filters'],
            ]);

        // Test with start_date only
        $resStartDateOnly = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/appointments?start_date=2026-09-01');
        $resStartDateOnly->assertOk();

        // Test with end_date only
        $resEndDateOnly = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/appointments?end_date=2026-09-30');
        $resEndDateOnly->assertOk();
    }

    public function test_admin_can_fetch_consultations_report(): void
    {
        $admin = $this->createAdmin();

        Consultation::create([
            'reference' => 'CNS-2026-0001',
            'patient' => 'Juan Dela Cruz',
            'patient_id' => '24-001234',
            'staff' => 'Dr. Santos',
            'date' => '2026-09-22',
            'time' => '10:00 AM',
            'status' => 'Completed',
            'chief_complaint' => 'Headache',
            'diagnosis' => 'Tension headache',
            'treatment' => 'Rest and paracetamol',
            'started_at' => '2026-09-22 10:00:00',
            'completed_at' => '2026-09-22 10:30:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/consultations');
        $response->assertOk();

        // Search by patient ID
        $responseFiltered = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/consultations?patient=24-001234');
        $responseFiltered->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_can_fetch_patients_report(): void
    {
        $admin = $this->createAdmin();

        Patient::create([
            'patient_id' => '24-001234',
            'name' => 'Juan Dela Cruz',
            'type' => 'Student',
            'course_dept' => 'BS Information Technology',
            'contact' => '0912-345-6789',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/patients');
        $response->assertOk()
            ->assertJsonPath('data.0.patientId', '24-001234');

        $responseSearch = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/patients?patient=24-001234');
        $responseSearch->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_can_fetch_medical_certificates_report(): void
    {
        $admin = $this->createAdmin();

        MedicalCertificate::create([
            'reference' => 'MC-2026-0001',
            'patient' => 'Juan Dela Cruz',
            'patient_id' => '24-001234',
            'purpose' => 'Sick Leave',
            'diagnosis' => 'Flu',
            'issued_by' => 'Dr. Santos',
            'issue_date' => '2026-09-22',
            'valid_until' => '2026-09-25',
            'status' => 'Issued',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/medical-certificates');
        $response->assertOk();

        $responseFiltered = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/medical-certificates?patient=24-001234');
        $responseFiltered->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_can_fetch_prescriptions_report(): void
    {
        $admin = $this->createAdmin();

        Prescription::create([
            'reference' => 'RX-2026-0001',
            'patient' => 'Juan Dela Cruz',
            'patient_id' => '24-001234',
            'prescribed_by' => 'Dr. Santos',
            'prescription_date' => '2026-09-22',
            'notes' => 'Take with water',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/prescriptions');
        $response->assertOk();

        $responseFiltered = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/prescriptions?patient=24-001234');
        $responseFiltered->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_can_fetch_statistics(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/statistics');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['appointments', 'consultations', 'patients', 'medicalCertificates', 'prescriptions'],
            ]);

        $responseWithDate = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/statistics?start_date=2026-09-01&end_date=2026-09-30');
        $responseWithDate->assertOk();
    }

    public function test_admin_can_export_report(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/export/appointments');
        $response->assertOk()
            ->assertJsonStructure(['data', 'columns', 'type']);

        $responseConsultations = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/export/consultations');
        $responseConsultations->assertOk();

        $responsePatients = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/export/patients');
        $responsePatients->assertOk();

        $responseMedCerts = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/export/medical-certificates');
        $responseMedCerts->assertOk();

        $responseRx = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/export/prescriptions');
        $responseRx->assertOk();
    }
}
