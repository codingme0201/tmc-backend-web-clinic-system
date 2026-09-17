<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    private function permissions(array $names): array
    {
        return collect($names)->map(function (string $name) {
            [$module] = explode('.', $name, 2);

            return Permission::factory()->create(['name' => $name, 'module' => $module])->id;
        })->all();
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync($this->permissions($permissionNames));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_activity_logs_endpoints_require_authentication(): void
    {
        $this->getJson('/api/activity-logs')->assertUnauthorized();
        $this->postJson('/api/activity-logs', ['action' => 'test'])->assertUnauthorized();
    }

    public function test_viewing_activity_logs_requires_permission(): void
    {
        $unauthorizedUser = $this->userWithPermissions([]);
        $this->actingAs($unauthorizedUser, 'sanctum');

        $this->getJson('/api/activity-logs')->assertForbidden();
    }

    public function test_user_with_permission_can_list_activity_logs(): void
    {
        $authorizedUser = $this->userWithPermissions(['audit_logs.view']);
        $this->actingAs($authorizedUser, 'sanctum');

        // Create log with author
        ActivityLog::create([
            'user_id' => $authorizedUser->id,
            'time' => '10:00 AM',
            'user' => $authorizedUser->name,
            'module' => 'Consultations',
            'action' => 'Logged consultation session',
        ]);

        // Create log without author (e.g. System)
        ActivityLog::create([
            'user_id' => null,
            'time' => '12:00 PM',
            'user' => 'System',
            'module' => 'System',
            'action' => 'Automated backup completed',
        ]);

        $response = $this->getJson('/api/activity-logs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'userId',
                        'time',
                        'user',
                        'module',
                        'action',
                        'role',
                        'createdAt',
                    ],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_activity_logs_filtering_by_module_and_search(): void
    {
        $authorizedUser = $this->userWithPermissions(['audit_logs.view']);
        $this->actingAs($authorizedUser, 'sanctum');

        ActivityLog::create([
            'user_id' => $authorizedUser->id,
            'time' => '09:00 AM',
            'user' => 'Dr. Smith',
            'module' => 'Appointments',
            'action' => 'Rescheduled appointment for John Doe',
        ]);

        ActivityLog::create([
            'user_id' => $authorizedUser->id,
            'time' => '10:00 AM',
            'user' => 'Nurse Joy',
            'module' => 'Prescriptions',
            'action' => 'Dispensed medication for Jane Doe',
        ]);

        // Filter by module
        $resModule = $this->getJson('/api/activity-logs?module=Appointments');
        $resModule->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Appointments', $resModule->json('data.0.module'));

        // Search
        $resSearch = $this->getJson('/api/activity-logs?search=Dispensed');
        $resSearch->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Prescriptions', $resSearch->json('data.0.module'));
    }

    public function test_can_record_activity_log(): void
    {
        $user = $this->userWithPermissions([]);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/activity-logs', [
            'action' => 'Updated clinic profile information',
            'module' => 'System Settings',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.action', 'Updated clinic profile information')
            ->assertJsonPath('data.module', 'System Settings')
            ->assertJsonPath('data.userId', $user->id);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'Updated clinic profile information',
            'module' => 'System Settings',
        ]);
    }
}
