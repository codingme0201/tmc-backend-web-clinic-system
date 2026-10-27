<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPatientVisitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_real_latest_visit_dates_without_treating_missed_or_future_appointments_as_visits(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(10, 0));
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        $seen = Patient::factory()->create(['created_at' => now()->subYear(), 'status' => 'Active']);
        $missed = Patient::factory()->create(['created_at' => now()->subYear(), 'status' => 'Active']);
        Appointment::factory()->create(['patient_id' => $seen->patient_id, 'date' => '2026-10-20', 'status' => 'Completed']);
        Consultation::create(['reference' => 'CONS-2026-991', 'patient_id' => $seen->patient_id, 'patient' => $seen->name, 'date' => '2026-10-23', 'time' => '09:00 AM', 'status' => 'Completed']);
        foreach (['Pending', 'No-Show', 'Cancelled', 'Expired'] as $status) {
            Appointment::factory()->create(['patient_id' => $missed->patient_id, 'date' => '2026-10-23', 'status' => $status]);
        }
        Appointment::factory()->create(['patient_id' => $seen->patient_id, 'date' => '2026-10-27', 'status' => 'Approved']);
        $this->actingAs($admin, 'sanctum');
        $patients = collect($this->getJson('/api/patients')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('2026-10-23', $patients[$seen->id]['lastVisitOn']);
        $this->assertNull($patients[$missed->id]['lastVisitOn']);
        $this->assertSame(2, $patients->count()); // Main registry still contains every patient.
    }
}
