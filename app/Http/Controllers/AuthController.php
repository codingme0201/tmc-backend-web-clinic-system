<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Patient;
use App\Models\Role;
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

        $client = $credentials['client'] ?? $request->header('X-Client-Platform');
        $isStudentUser = in_array($user->role?->name, ['student', 'patient'], true);

        if ($client === 'web' && $isStudentUser) {
            return response()->json([
                'message' => 'Student accounts can only access TMC CareLink via the mobile application. Only doctors, nurses, and administrators can enter the web clinic system.',
                'code' => $user->role?->name === 'student' ? 'STUDENT_MOBILE_ONLY' : 'PATIENT_MOBILE_ONLY',
                'role' => $user->role?->name,
            ], 403);
        }

        if ($client === 'mobile' && ! $isStudentUser) {
            return response()->json([
                'message' => 'This mobile app is for student users only. Doctors, nurses, and administrators must log in through the web clinic portal.',
                'code' => 'CLINIC_STAFF_WEB_ONLY',
                'role' => $user->role?->name,
            ], 403);
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
     * Register a new patient user account.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'firstName' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'patient_id' => ['nullable', 'string', 'max:50'],
            'student_id' => ['nullable', 'string', 'max:50'],
            'studentId' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'course' => ['nullable', 'string', 'max:150'],
            'course_dept' => ['nullable', 'string', 'max:150'],
            'courseDept' => ['nullable', 'string', 'max:150'],
            'block' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergencyContactName' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergencyContactPhone' => ['nullable', 'string', 'max:50'],
        ]);

        $firstName = $validated['firstName'] ?? $validated['first_name'] ?? null;
        $middleName = $validated['middleName'] ?? $validated['middle_name'] ?? null;
        $lastName = $validated['lastName'] ?? $validated['last_name'] ?? null;
        $patientId = $validated['studentId'] ?? $validated['student_id'] ?? ($validated['patient_id'] ?? null);

        $name = $validated['name'] ?? null;
        if (! $name && $firstName && $lastName) {
            $name = trim($firstName . ($middleName ? " {$middleName} " : ' ') . $lastName);
        }
        if (! $name) {
            $name = $firstName ?? 'Student User';
        }

        if (! $patientId) {
            $patientId = 'STU-' . date('y') . '-' . str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        }

        $course = $validated['course'] ?? $validated['courseDept'] ?? $validated['course_dept'] ?? 'General';
        $phone = $validated['phone'] ?? $validated['contact'] ?? '';
        $emergName = $validated['emergencyContactName'] ?? $validated['emergency_contact_name'] ?? null;
        $emergPhone = $validated['emergencyContactPhone'] ?? $validated['emergency_contact_phone'] ?? null;
        $emergContact = $emergName || $emergPhone ? trim(($emergName ?? '') . ($emergPhone ? " ({$emergPhone})" : '')) : null;

        $patient = Patient::firstOrCreate(
            ['patient_id' => $patientId],
            [
                'name' => $name,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'age' => $validated['age'] ?? null,
                'type' => $validated['type'] ?? 'Student',
                'course_dept' => $course,
                'block' => $validated['block'] ?? null,
                'address' => $validated['address'] ?? null,
                'nationality' => $validated['nationality'] ?? 'Filipino',
                'contact' => $phone,
                'emergency_contact_name' => $emergName,
                'emergency_contact_phone' => $emergPhone,
                'emergency_contact' => $emergContact ?? '',
                'status' => 'Active',
            ]
        );

        $studentRole = Role::where('name', 'student')->first()
            ?? Role::where('name', 'patient')->first()
            ?? Role::firstOrCreate(['name' => 'student'], ['description' => 'Student — mobile self-service', 'is_system' => true]);

        $user = User::create([
            'name' => $name,
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role_id' => $studentRole->id,
            'patient_id' => $patient->patient_id,
            'status' => 'active',
        ]);

        $token = $user->createToken('tmc-carelink')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'message' => 'Registration successful.',
        ], 201);
    }

    /**
     * Handle forgot password reset request.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        return response()->json([
            'message' => 'If an account exists with this email address, password reset instructions will be sent.',
        ]);
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
        $user->loadMissing(['role.permissions', 'patient']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'patientId' => $user->patient_id,
            'isProfileComplete' => $user->patient ? $user->patient->isProfileComplete() : false,
            'permissions' => $user->role?->permissions->pluck('name')->values()->all() ?? [],
        ];
    }
}
