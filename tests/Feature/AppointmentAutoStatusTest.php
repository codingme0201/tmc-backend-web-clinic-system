<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentAutoStatus;
use App\Support\SyncState;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppointmentAutoStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Manila'));
    }

    private function appointment(string $status, array $overrides = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'status' => $status, 'date' => '2026-10-20', 'time' => '09:30 AM', 'checked_in_at' => null,
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
    }

    public function test_command_updates_only_overdue_unchecked_pending_and_approved_appointments(): void
    {
        $pending = $this->appointment('Pending');
        $approved = $this->appointment('Approved');
        $yesterday = $this->appointment('Pending', ['date' => '2026-10-19', 'time' => '05:00 PM']);
        $olderApproved = $this->appointment('Approved', ['date' => '2026-10-19']);
        $unchanged = [];
        foreach (['Pending', 'Approved'] as $status) {
            $unchanged[] = $this->appointment($status, ['time' => '10:00 AM']); // Exactly on time.
            $unchanged[] = $this->appointment($status, ['time' => '10:30 AM']);
            $unchanged[] = $this->appointment($status, ['date' => '2026-10-21']);
            $unchanged[] = $this->appointment($status, ['checked_in_at' => now()->subHour()]);
        }
        foreach (['Under Review', 'Rescheduled', 'Completed', 'Cancelled', 'Rejected', 'No-Show', 'Expired', 'Checked-in'] as $status) {
            $unchanged[] = $this->appointment($status, ['date' => '2026-10-19']);
        }
        $unchanged[] = $this->appointment('Approved', ['time' => 'invalid']);
        $this->artisan('appointments:refresh-statuses')->expectsOutput('Updated 4 overdue appointment(s).')->assertSuccessful();
        $this->assertSame('Expired', $pending->fresh()->status);
        $this->assertSame('Expired', $yesterday->fresh()->status);
        $this->assertSame('No-Show', $approved->fresh()->status);
        $this->assertSame('No-Show', $olderApproved->fresh()->status);
        foreach ($unchanged as $appointment) $this->assertSame($appointment->status, $appointment->fresh()->status);
        $this->assertSame(0, MedicalRecord::count());
        $version = SyncState::versions()['appointments'];
        $this->assertSame(0, app(AppointmentAutoStatus::class)->refresh());
        $this->assertSame($version, SyncState::versions()['appointments']);
    }

    public function test_comparison_uses_philippine_time_and_strictly_passed_start_time(): void
    {
        // 02:00 UTC is 10:00 in the Philippines, irrespective of the clock's input zone.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 02:00:00', 'UTC'));
        $past = $this->appointment('Pending', ['time' => '09:59']);
        $exact = $this->appointment('Approved', ['time' => '10:00 AM']);
        $this->assertSame(1, app(AppointmentAutoStatus::class)->refresh());
        $this->assertSame('Expired', $past->fresh()->status);
        $this->assertSame('Approved', $exact->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 02:00:01', 'UTC'));
        $this->assertSame(1, app(AppointmentAutoStatus::class)->refresh());
        $this->assertSame('No-Show', $exact->fresh()->status);
    }

    public function test_automatic_updates_never_overwrite_concurrent_checkin_completion_or_rescheduling(): void
    {
        $checkedIn = $this->appointment('Approved');
        $completed = $this->appointment('Approved');
        $rescheduled = $this->appointment('Pending');
        $updates = [
            $checkedIn->id => ['checked_in_at' => now()->subMinute()],
            $completed->id => ['status' => 'Completed'],
            $rescheduled->id => ['date' => '2026-10-21', 'time' => '11:00 AM'],
        ];
        $event = 'eloquent.retrieved: '.Appointment::class;
        Event::listen($event, function (Appointment $appointment) use ($updates) {
            if (isset($updates[$appointment->id])) DB::table('appointments')->where('id', $appointment->id)->update($updates[$appointment->id]);
        });
        try {
            $this->assertSame(0, app(AppointmentAutoStatus::class)->refresh());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('Approved', $checkedIn->fresh()->status);
        $this->assertNotNull($checkedIn->fresh()->checked_in_at);
        $this->assertSame('Completed', $completed->fresh()->status);
        $this->assertSame('Pending', $rescheduled->fresh()->status);
        $this->assertSame('2026-10-21', $rescheduled->fresh()->date->format('Y-m-d'));
    }

    public function test_api_sync_lists_reports_and_exports_automatically_reflect_persisted_statuses(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $pending = $this->appointment('Pending');
        $before = SyncState::versions()['appointments'];
        $versions = $this->getJson('/api/sync')->assertOk()->json('data');
        $this->assertNotSame($before, $versions['appointments']);
        $this->getJson('/api/appointments?status=Expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id)->assertJsonPath('data.0.status', 'Expired');
        $approved = $this->appointment('Approved');
        $this->getJson('/api/reports/appointments?status=No-Show')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id)->assertJsonPath('data.0.status', 'No-Show');
        $this->getJson('/api/reports/statistics')->assertOk()->assertJsonPath('data.appointments.byStatus.Expired', 1)->assertJsonPath('data.appointments.byStatus.No-Show', 1);
        $this->getJson('/api/reports/export/appointments?status=Expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.Status', 'Expired');
        $this->getJson('/api/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_overdue_route_bound_appointments_cannot_be_approved_checked_in_or_reopened(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $pending = $this->appointment('Pending');
        $approved = $this->appointment('Approved');
        $this->patchJson('/api/appointments/'.$pending->id.'/status', ['status' => 'Approved'])->assertUnprocessable();
        $this->postJson('/api/queue/'.$approved->id.'/check-in')->assertUnprocessable();
        $this->getJson('/api/appointments/'.$pending->id)->assertOk()->assertJsonPath('data.status', 'Expired');
        $this->assertSame('No-Show', $approved->fresh()->status);
        $this->assertNull($approved->fresh()->checked_in_at);
        $this->patchJson('/api/appointments/'.$pending->id.'/status', ['status' => 'Pending'])->assertUnprocessable();
    }

    public function test_command_processes_multiple_batches_and_is_scheduled_every_minute(): void
    {
        Appointment::factory()->count(205)->create(['date' => '2026-10-19', 'time' => '09:00 AM', 'status' => 'Pending', 'checked_in_at' => null]);
        $this->assertSame(205, app(AppointmentAutoStatus::class)->refresh());
        $this->assertSame(205, Appointment::where('status', 'Expired')->count());
        $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'appointments:refresh-statuses'));
        $this->assertNotNull($scheduled);
        $this->assertSame('* * * * *', $scheduled->expression);
    }
}
