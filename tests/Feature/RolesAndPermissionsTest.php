<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Create the given permissions and return their ids. */
    private function permissions(array $names): array
    {
        return collect($names)->map(function (string $name) {
            [$module] = explode('.', $name, 2);

            return Permission::factory()->create(['name' => $name, 'module' => $module])->id;
        })->all();
    }

    /** Administrator with the base admin role granted the roles.* permissions. */
    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $role->permissions()->sync($this->permissions([
            'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign_permissions',
        ]));

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

    public function test_roles_endpoints_require_authentication(): void
    {
        $this->getJson('/api/roles')->assertUnauthorized();
        $this->postJson('/api/roles', ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/roles/1')->assertUnauthorized();
    }

    // --- Authorization boundary --------------------------------------------

    public function test_users_without_permission_receive_403(): void
    {
        $user = $this->userWithPermissions(['appointments.view']);

        $this->actingAsUser($user);
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/roles', ['name' => 'x', 'description' => 'x'])->assertForbidden();
        $this->getJson('/api/permissions')->assertForbidden();
    }

    // --- View roles ----------------------------------------------------------

    public function test_admin_can_list_roles_with_counts(): void
    {
        $admin = $this->adminUser();
        Role::factory()->create(['name' => 'custom']);

        $this->actingAsUser($admin);

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_system', 'permissions_count', 'users_count']]])
            ->assertJsonPath('data.0.name', 'admin')
            ->assertJsonPath('data.0.is_system', true);
    }

    public function test_role_detail_includes_assigned_permissions(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'custom']);
        $role->permissions()->sync($this->permissions(['patients.view', 'patients.create']));

        $this->actingAsUser($admin);

        $this->getJson("/api/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'custom')
            ->assertJsonCount(2, 'data.permissions');
    }

    public function test_permissions_can_be_listed(): void
    {
        $admin = $this->adminUser();
        $this->permissions(['patients.view', 'reports.view']);

        $this->actingAsUser($admin);

        $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'module', 'name', 'label']]]);
    }

    // --- Create role ----------------------------------------------------------

    public function test_admin_can_create_role_with_permissions(): void
    {
        $admin = $this->adminUser();
        $permissionIds = $this->permissions(['patients.view', 'patients.create']);

        $this->actingAsUser($admin);

        $response = $this->postJson('/api/roles', [
            'name' => 'front-desk',
            'description' => 'Front desk staff',
            'permissions' => $permissionIds,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'front-desk')
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', ['name' => 'front-desk']);
        $role = Role::where('name', 'front-desk')->firstOrFail();
        $this->assertSame($permissionIds, $role->permissions->pluck('id')->all());
    }

    public function test_role_name_must_be_unique(): void
    {
        $admin = $this->adminUser();

        $this->actingAsUser($admin);

        $this->postJson('/api/roles', ['name' => 'admin', 'description' => 'Duplicate'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_name_cannot_be_staff_instructor_faculty_or_patient(): void
    {
        $admin = $this->adminUser();

        $this->actingAsUser($admin);

        foreach (['staff', 'instructor', 'faculty', 'patient'] as $forbidden) {
            $this->postJson('/api/roles', ['name' => $forbidden, 'description' => $forbidden])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }
    }

    // --- Update role -----------------------------------------------------------

    public function test_admin_can_update_role_information_and_permissions(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'old-name', 'description' => 'Before']);
        $newPermissions = $this->permissions(['reports.view']);

        $this->actingAsUser($admin);

        $this->putJson("/api/roles/{$role->id}", [
            'name' => 'new-name',
            'description' => 'After',
            'permissions' => $newPermissions,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'new-name')
            ->assertJsonCount(1, 'data.permissions');

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'new-name']);
        $this->assertSame($newPermissions, $role->fresh()->permissions->pluck('id')->all());
    }

    // --- Delete role -------------------------------------------------------------

    public function test_admin_can_delete_an_unused_custom_role(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'temp-role']);

        $this->actingAsUser($admin);

        $this->deleteJson("/api/roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $admin = $this->adminUser();
        $system = Role::where('name', 'admin')->firstOrFail();

        $this->actingAsUser($admin);

        $this->deleteJson("/api/roles/{$system->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'System roles cannot be deleted.');
    }

    public function test_roles_assigned_to_users_cannot_be_deleted(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'occupied']);
        User::factory()->create(['role_id' => $role->id]);

        $this->actingAsUser($admin);

        $this->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This role is assigned to users and cannot be deleted.');
    }

    // --- Assign permissions -------------------------------------------------------

    public function test_admin_can_assign_and_revoke_permissions(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'flexible']);
        $first = $this->permissions(['patients.view']);
        $role->permissions()->sync($first);

        $this->actingAsUser($admin);

        $second = $this->permissions(['reports.view', 'reports.export']);
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => $second])
            ->assertOk()
            ->assertJsonCount(2, 'data.permissions');

        // Old assignment gone, new ones present, nothing duplicated.
        $fresh = $role->fresh();
        $this->assertSame($second, $fresh->permissions->pluck('id')->all());
    }

    public function test_permission_assignment_requires_valid_permission_ids(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->create(['name' => 'strict']);

        $this->actingAsUser($admin);

        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => [999999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_users_can_check_permission_via_model(): void
    {
        $role = Role::factory()->create(['name' => 'checker']);
        $role->permissions()->sync($this->permissions(['appointments.view']));
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('appointments.view'));
        $this->assertFalse($user->hasPermission('roles.create'));
    }
}
