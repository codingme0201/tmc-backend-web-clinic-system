<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List users with optional search, role filter, and status filter.
     *
     * The frontend sends `?search=...&role=...&status=...` query params.
     * Search matches name or email. Results are ordered by newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with('role');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $role = $request->query('role');
        if ($role && $role !== 'All') {
            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }

        $status = $request->query('status');
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return UserResource::collection($query->orderByDesc('id')->get());
    }

    /**
     * Show a single user.
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user->load('role'));
    }

    /**
     * Create a new user account.
     *
     * Passwords are securely hashed by the model's `hashed` cast —
     * plaintext is never stored or returned.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role_id' => $validated['role_id'],
            'status' => $validated['status'] ?? 'active',
        ]);

        $user->load('role');

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Update a user's account information (name, email).
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'] ?? $user->name,
            'email' => $validated['email'] ?? $user->email,
        ]);

        return new UserResource($user->fresh('role'));
    }

    /**
     * Activate or deactivate a user account.
     *
     * Administrators cannot deactivate their own account to prevent
     * accidental lockout.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource|JsonResponse
    {
        if ($request->user()->id === $user->id && $request->validated('status') === 'inactive') {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 409);
        }

        $user->update(['status' => $request->validated('status')]);

        return new UserResource($user->fresh('role'));
    }

    /**
     * Assign a role to a user.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): UserResource
    {
        $user->update(['role_id' => $request->validated('role_id')]);

        return new UserResource($user->fresh('role'));
    }

    /**
     * Reset a user's password.
     *
     * The new password is securely hashed by the model's `hashed` cast.
     * The password hash is never returned in the response.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $user->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json(['message' => 'Password has been reset successfully.']);
    }
}
