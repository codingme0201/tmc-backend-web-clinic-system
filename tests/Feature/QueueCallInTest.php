<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueCallInTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(): Appointment
    {
        return Appointment::create([
            'reference' => 'APT-2026-901',
            'patient' => 'Queue Patient',
            'patient_id' => '24-900001',
            'type' => 'Dental concern',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
            'reason' => 'Dental recheck',
            'date' => now()->toDateString(),
            'time' => '09:00 AM',
            'status' => 'Approved',
            'requested_on' => now()->toDateString(),
        ]);
    }

    public function test_type_migration_upgrades_existing_consultations_without_overwriting_visit_type(): void
    {
        $migration = require database_path('migrations/2026_10_17_100000_add_type_to_consultations_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('consultations', 'type'));

        $appointment = $this->appointment();
        $consultation = Consultation::create([
            'reference' => 'CONS-2026-901',
            'appointment_id' => $appointment->id,
            'date' => $appointment->date->format('Y-m-d'),
            'time' => $appointment->time,
            'patient' => $appointment->patient,
            'status' => 'In Progress',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('consultations', 'type'));
        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'type' => 'Dental concern',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
        ]);
    }

    public function test_call_in_saves_consultation_and_moves_patient_out_of_waiting(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
        $role = Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']);
        foreach (['appointments.view', 'appointments.update', 'consultations.create'] as $name) {
            [$module] = explode('.', $name, 2);
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => $module, 'label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->actingAs(User::factory()->create(['role_id' => $role->id, 'status' => 'active']), 'sanctum');
        $appointment = $this->appointment();

        $this->postJson("/api/queue/{$appointment->id}/check-in")->assertOk();
        $this->getJson('/api/queue')->assertOk()
            ->assertJsonPath('meta.waiting', 1)
            ->assertJsonPath('meta.inConsultation', 0);

        $consultationId = $this->postJson("/api/queue/{$appointment->id}/serve")
            ->assertCreated()
            ->assertJsonPath('data.status', 'In Progress')
            ->assertJsonPath('data.type', 'Dental concern')
            ->assertJsonPath('data.visitType', Appointment::VISIT_FOLLOW_UP)
            ->json('data.id');

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'appointment_id' => $appointment->id,
            'status' => 'In Progress',
            'type' => 'Dental concern',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
        ]);
        $this->assertNotNull(Consultation::findOrFail($consultationId)->started_at);
        $this->getJson('/api/queue')->assertOk()
            ->assertJsonPath('meta.waiting', 0)
            ->assertJsonPath('meta.inConsultation', 1)
            ->assertJsonPath('data.0.appointmentId', $appointment->id)
            ->assertJsonPath('data.0.queueStatus', 'In Consultation')
            ->assertJsonPath('data.0.consultation.id', $consultationId);

        $this->postJson("/api/queue/{$appointment->id}/serve")->assertUnprocessable();
        $this->assertDatabaseCount('consultations', 1);
    }
}
