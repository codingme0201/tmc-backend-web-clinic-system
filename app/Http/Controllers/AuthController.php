<?php

namespace App\Http\Controllers;

use App\Support\PhoneNumber;
use App\Http\Requests\LoginRequest;
use App\Models\ActivityLog;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\AcademicPrograms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

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
                'message' => 'Student accounts can only access TMC CareLink via the mobile application. Only doctors, nurses, front desk staff, and administrators can enter the web clinic system.',
                'code' => $user->role?->name === 'student' ? 'STUDENT_MOBILE_ONLY' : 'PATIENT_MOBILE_ONLY',
                'role' => $user->role?->name,
            ], 403);
        }

        if ($client === 'mobile' && ! $isStudentUser) {
            return response()->json([
                'message' => 'This mobile app is for student users only. Doctors, nurses, front desk staff, and administrators must log in through the web clinic portal.',
                'code' => 'CLINIC_STAFF_WEB_ONLY',
                'role' => $user->role?->name,
            ], 403);
        }

        $token = $user->createToken('tmc-carelink')->plainTextToken;
        ActivityLog::record($user, $isStudentUser ? 'Signed in to the mobile app' : 'Signed in to the web clinic system', 'System');

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
        ActivityLog::record($request->user(), 'Signed out', 'System');

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', Password::min(8), 'different:currentPassword'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $user->update([
            'password' => $validated['newPassword'],
        ]);

        // Sign out every other device; the current session keeps its token.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->id : null;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

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
            'password' => ['required', 'string', Password::min(8)],
            'patient_id' => ['required_without_all:student_id,studentId', 'nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/'],
            'student_id' => ['required_without_all:patient_id,studentId', 'nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/'],
            'studentId' => ['required_without_all:patient_id,student_id', 'nullable', 'string', 'regex:/^[0-9]{2}-[0-9]{6}$/'],
            'type' => ['nullable', 'string', 'max:50'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'course' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'course_dept' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'courseDept' => ['nullable', 'string', 'max:150', AcademicPrograms::rule()],
            'block' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'contact' => PhoneNumber::rules(),
            'phone' => PhoneNumber::rules(),
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergencyContactName' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => PhoneNumber::rules(),
            'emergencyContactPhone' => PhoneNumber::rules(),
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

        // Never link a self-registered account to an existing patient record:
        // anyone who knew a student ID could otherwise read that student's
        // medical records. Existing records are linked by clinic staff.
        if ($patientId && (Patient::where('patient_id', $patientId)->exists() || Patient::idHasClinicalRecords($patientId))) {
            return response()->json([
                'message' => 'This student ID already has a clinic record. Please visit the clinic to have your account linked.',
                'errors' => ['studentId' => ['This student ID is already registered.']],
            ], 422);
        }

        $course = $validated['course'] ?? $validated['courseDept'] ?? $validated['course_dept'] ?? 'General';
        $phone = $validated['phone'] ?? $validated['contact'] ?? '';
        $emergName = $validated['emergencyContactName'] ?? $validated['emergency_contact_name'] ?? null;
        $emergPhone = $validated['emergencyContactPhone'] ?? $validated['emergency_contact_phone'] ?? null;
        $emergContact = $emergName || $emergPhone ? trim(($emergName ?? '') . ($emergPhone ? " ({$emergPhone})" : '')) : null;

        $patient = Patient::create([
            'patient_id' => $patientId,
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
        ]);

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
        ActivityLog::record($user, 'Registered an account from the mobile app', 'System');

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'message' => 'Registration successful.',
        ], 201);
    }

    /**
     * Check that an email belongs to an active mobile (student/patient) account
     * before the app shows the "set a new password" step.
     *
     * Staff accounts are excluded: they are reset by an administrator from the
     * web User Management page.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        if (! $this->findResettableUser($validated['email'])) {
            return $this->noResettableAccount();
        }

        return response()->json([
            'message' => 'Account found. You can now set a new password.',
        ]);
    }

    /**
     * Set a new password for a mobile account identified by email.
     *
     * All of the user's existing tokens are revoked.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        $user = $this->findResettableUser($validated['email']);
        if (! $user) {
            return $this->noResettableAccount();
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
        ActivityLog::record($user, 'Reset password from the mobile app', 'System');

        return response()->json(['message' => 'Your password has been reset. You can now log in.']);
    }

    private function findResettableUser(string $email): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->status !== 'active' || ! in_array($user->role?->name, ['student', 'patient'], true)) {
            return null;
        }

        return $user;
    }

    private function noResettableAccount(): JsonResponse
    {
        $message = 'No active student account was found with this email address.';

        return response()->json([
            'message' => $message,
            'errors' => ['email' => [$message]],
        ], 422);
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
            'profilePictureUrl' => $user->profilePictureUrl(),
            'email' => $user->email,
            'role' => $user->role?->name,
            'patientId' => $user->patient_id,
            'isProfileComplete' => $user->patient ? $user->patient->isProfileComplete() : false,
            'permissions' => $user->role?->permissions->pluck('name')->values()->all() ?? [],
        ];
    }
}
