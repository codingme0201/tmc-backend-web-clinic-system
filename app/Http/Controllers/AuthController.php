<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Fixed bcrypt hash used to equalize response timing when the email is
     * unknown, so login attempts do not leak whether an account exists.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$An8S3z7.A0b5h6Io2qw2W.tXWpGYg6UhQA..k5qdcVYvNNg4ev5h.';

    /**
     * Authenticate a user and issue a Sanctum API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            // Run a dummy check so unknown emails take the same time as a
            // password mismatch.
            Hash::check($credentials['password'], self::DUMMY_PASSWORD_HASH);

            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (isset($user->status) && $user->status !== 'active') {
            return response()->json(['message' => 'This account has been deactivated. Please contact an administrator.'], 403);
        }

        $token = $user->createToken('tmc-carelink')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Revoke the current token, ending the session.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:6'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $user->update([
            'password' => $validated['newPassword'],
        ]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Shape the user payload exposed through the API.
     *
     * Passwords and tokens are never included. `role` is the role name and
     * `permissions` the granted permission keys (module.action), which the
     * frontend uses for module access control — the Laravel backend remains
     * the enforcement boundary.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('role.permissions');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'patientId' => $user->patient_id,
            'permissions' => $user->role?->permissions->pluck('name')->values()->all() ?? [],
        ];
    }
}
