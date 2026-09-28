<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        // Never link a self-registered account to an existing patient record:
        // anyone who knew a student ID could otherwise read that student's
        // medical records. Existing records are linked by clinic staff.
        if ($patientId && (Patient::where('patient_id', $patientId)->exists() || Patient::idHasClinicalRecords($patientId))) {
            return response()->json([
                'message' => 'This student ID already has a clinic record. Please visit the clinic to have your account linked.',
                'errors' => ['studentId' => ['This student ID is already registered.']],
            ], 422);
        }

        if (! $patientId) {
            $patientId = Patient::generatePlaceholderId();
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
        $patient->ensureMedicalRecord();

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
     * Email a 6-digit password reset code.
     *
     * A short code (instead of a link) works for both the mobile app and the
     * web portal. Only its hash is stored in password_reset_tokens, and the
     * response is identical whether or not the email exists.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->status === 'active') {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()],
            );
            Cache::forget($this->resetAttemptsKey($user->email));

            try {
                Mail::raw(
                    "Your TMC CareLink password reset code is {$code}.\n\n"
                    .'It expires in '.self::RESET_CODE_TTL_MINUTES." minutes. If you did not request this, you can ignore this email.",
                    fn ($message) => $message->to($user->email)->subject('TMC CareLink password reset code'),
                );
            } catch (\Throwable $e) {
                Log::error('Failed to send password reset code.', ['email' => $user->email, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'If an account exists with this email address, a password reset code has been sent.',
        ]);
    }

    /**
     * Reset a password with the emailed code.
     *
     * Codes expire after RESET_CODE_TTL_MINUTES and are invalidated after
     * RESET_MAX_ATTEMPTS wrong guesses. All of the user's tokens are revoked.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        $invalid = fn () => response()->json([
            'message' => 'The reset code is invalid or has expired.',
            'errors' => ['code' => ['The reset code is invalid or has expired.']],
        ], 422);

        $row = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();
        $user = User::where('email', $validated['email'])->first();

        if (! $row || ! $user || now()->diffInMinutes($row->created_at, true) > self::RESET_CODE_TTL_MINUTES) {
            return $invalid();
        }

        if (! Hash::check($validated['code'], $row->token)) {
            $attemptsKey = $this->resetAttemptsKey($user->email);
            $attempts = Cache::increment($attemptsKey);
            if ($attempts >= self::RESET_MAX_ATTEMPTS) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                Cache::forget($attemptsKey);
            }

            return $invalid();
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        Cache::forget($this->resetAttemptsKey($user->email));

        return response()->json(['message' => 'Your password has been reset. You can now log in.']);
    }

    private const RESET_CODE_TTL_MINUTES = 30;

    private const RESET_MAX_ATTEMPTS = 5;

    private function resetAttemptsKey(string $email): string
    {
        return 'password-reset-attempts:'.strtolower($email);
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
