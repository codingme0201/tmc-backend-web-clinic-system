<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Support\SyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Roles whose permissions cannot be checked/unchecked: the single admin
     * account always keeps full access, and students only use the mobile app.
     */
    private const LOCKED_ROLES = ['admin', 'student'];

    /**
     * Permissions that only the administrator may hold.
     */
    private const ADMIN_ONLY_PERMISSIONS = ['audit_logs.view'];

    /**
     * List all roles with permission and user counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::withCount('users')
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    /**
     * Create a new role, optionally with its initial permissions.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create($request->safe(['name', 'description']));
            $role->permissions()->sync($this->withoutAdminOnly($request->validated('permissions', [])));

            return $role;
        });

        ActivityLog::record($request->user(), "Created role {$role->name}", 'Roles & Permissions');

        return (new RoleResource($role->load('permissions')->loadCount('users')->loadCount('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single role with its full permission list.
     */
    public function show(Role $role): RoleResource
    {
        return new RoleResource(
            $role->load('permissions')->loadCount('users')->loadCount('permissions'),
        );
    }

    /**
     * Update role information and, when provided, its assigned permissions.
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource|JsonResponse
    {
        if ($request->has('permissions') && ($locked = $this->lockedResponse($role))) {
            return $locked;
        }
        if ($role->name === 'admin' && $request->filled('name') && $request->input('name') !== 'admin') {
            return response()->json(['message' => 'The admin role cannot be renamed.'], 409);
        }

        $role = DB::transaction(function () use ($request, $role) {
            $role->update($request->safe(['name', 'description']));

            if ($request->has('permissions')) {
                $role->permissions()->sync($this->withoutAdminOnly($request->validated('permissions')));
            }

            return $role;
        });
        ActivityLog::record($request->user(), "Updated role {$role->name}".($request->has('permissions') ? ' and its permissions' : ''), 'Roles & Permissions');

        return new RoleResource(
            $role->load('permissions')->loadCount('users')->loadCount('permissions'),
        );
    }

    /**
     * Delete a role, subject to system restrictions.
     *
     * System roles that keep the authorization structure intact cannot be
     * deleted, nor can roles that still have users assigned.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 409);
        }

        if ($role->users()->exists()) {
            return response()->json(['message' => 'This role is assigned to users and cannot be deleted.'], 409);
        }

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });
        ActivityLog::record($request->user(), "Deleted role {$role->name}", 'Roles & Permissions');

        return response()->json(null, 204);
    }

    /**
     * Replace the permissions assigned to a role.
     */
    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): RoleResource|JsonResponse
    {
        if ($locked = $this->lockedResponse($role)) {
            return $locked;
        }

        $role->permissions()->sync($this->withoutAdminOnly($request->validated('permissions')));
        SyncState::bump('roles');
        ActivityLog::record($request->user(), "Updated permissions of role {$role->name}", 'Roles & Permissions');

        return new RoleResource(
            $role->load('permissions')->loadCount('users')->loadCount('permissions'),
        );
    }

    private function lockedResponse(Role $role): ?JsonResponse
    {
        if (in_array($role->name, self::LOCKED_ROLES, true)) {
            return response()->json([
                'message' => "Permissions of the {$role->name} role cannot be changed. Only doctor, nurse and front desk permissions can be edited.",
            ], 409);
        }

        return null;
    }

    /**
     * @param  array<int, int|string>  $permissionIds
     * @return array<int, int|string>
     */
    private function withoutAdminOnly(array $permissionIds): array
    {
        $adminOnly = Permission::whereIn('name', self::ADMIN_ONLY_PERMISSIONS)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return array_values(array_filter($permissionIds, fn ($id) => ! in_array((string) $id, $adminOnly, true)));
    }
}
