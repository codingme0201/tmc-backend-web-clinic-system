<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\Patient;
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
        $query = User::with(['role', 'patient']);

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
        return new UserResource($user->load(['role', 'patient']));
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

        $firstName = $validated['firstName'] ?? $validated['first_name'] ?? null;
        $middleName = $validated['middleName'] ?? $validated['middle_name'] ?? null;
        $lastName = $validated['lastName'] ?? $validated['last_name'] ?? null;
        $studentId = $validated['studentId'] ?? $validated['student_id'] ?? ($validated['patient_id'] ?? null);

        $name = $validated['name'] ?? null;
        if (! $name && $firstName && $lastName) {
            $name = trim($firstName . ($middleName ? " {$middleName} " : ' ') . $lastName);
        }

        if ($studentId && ($conflict = $this->studentIdConflict($studentId, null, null))) {
            return $conflict;
        }

        $user = User::create([
            'name' => $name ?? 'New User',
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role_id' => $validated['role_id'],
            'status' => $validated['status'] ?? 'active',
            'patient_id' => $studentId,
        ]);

        $user->load('role');

        // If user is a student or student fields were provided, create or update the Patient record
        $isStudentRole = $user->role && in_array($user->role->name, ['student', 'patient']);
        if ($isStudentRole || $studentId || $firstName) {
            $patientId = $studentId ?: ($user->patient_id ?: Patient::generatePlaceholderId());
            $course = $validated['course'] ?? $validated['courseDept'] ?? $validated['course_dept'] ?? 'General';
            $phone = $validated['phone'] ?? $validated['contact'] ?? null;
            $emergName = $validated['emergencyContactName'] ?? $validated['emergency_contact_name'] ?? null;
            $emergPhone = $validated['emergencyContactPhone'] ?? $validated['emergency_contact_phone'] ?? null;

            $patient = Patient::firstOrNew(['patient_id' => $patientId]);
            $patient->name = $name ?? $user->name;
            $patient->first_name = $firstName;
            $patient->middle_name = $middleName;
            $patient->last_name = $lastName;
            if (isset($validated['age'])) $patient->age = $validated['age'];
            $patient->type = 'Student';
            $patient->course_dept = $course;
            if (isset($validated['block'])) $patient->block = $validated['block'];
            if (isset($validated['address'])) $patient->address = $validated['address'];
            if (isset($validated['nationality'])) $patient->nationality = $validated['nationality'];
            if ($phone) $patient->contact = $phone;
            if ($emergName) $patient->emergency_contact_name = $emergName;
            if ($emergPhone) $patient->emergency_contact_phone = $emergPhone;
            if ($emergName || $emergPhone) {
                $patient->emergency_contact = trim(($emergName ?? '') . ($emergPhone ? " ({$emergPhone})" : ''));
            }
            $patient->status = $user->status === 'inactive' ? 'Inactive' : 'Active';
            $patient->save();
            $patient->ensureMedicalRecord();

            $user->update(['patient_id' => $patient->patient_id]);
        }

        $user->load(['role', 'patient']);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Update a user's account information (name, email).
     */
    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        $validated = $request->validated();

        $firstName = $validated['firstName'] ?? $validated['first_name'] ?? null;
        $middleName = $validated['middleName'] ?? $validated['middle_name'] ?? null;
        $lastName = $validated['lastName'] ?? $validated['last_name'] ?? null;
        $studentId = $validated['studentId'] ?? $validated['student_id'] ?? ($validated['patient_id'] ?? null);

        $name = $validated['name'] ?? null;
        if (! $name && $firstName && $lastName) {
            $name = trim($firstName . ($middleName ? " {$middleName} " : ' ') . $lastName);
        }

        // Resolve the currently linked patient before anything changes, so a
        // student ID edit renames that record instead of creating a new one.
        $originalPatient = $user->patient;

        if ($studentId && $studentId !== $user->patient_id) {
            if ($conflict = $this->studentIdConflict($studentId, $user, $originalPatient)) {
                return $conflict;
            }
        }

        $user->update([
            'name' => $name ?? $user->name,
            'email' => $validated['email'] ?? $user->email,
        ]);

        $user->load('role');

        // If user has a patient or has student role or detailed fields provided
        $isStudentRole = $user->role && in_array($user->role->name, ['student', 'patient']);
        if ($isStudentRole || $user->patient_id || $studentId || $firstName) {
            $patientId = $studentId ?: ($user->patient_id ?: Patient::generatePlaceholderId());
            $patient = $originalPatient ?: Patient::firstOrNew(['patient_id' => $patientId]);
            if ($studentId && $patient->exists && $studentId !== $patient->patient_id) {
                $patient->renamePatientId($studentId);
            }
            if ($name) $patient->name = $name;
            if ($firstName !== null) $patient->first_name = $firstName;
            if ($middleName !== null) $patient->middle_name = $middleName;
            if ($lastName !== null) $patient->last_name = $lastName;
            if (array_key_exists('age', $validated)) $patient->age = $validated['age'];
            $course = $validated['course'] ?? $validated['courseDept'] ?? $validated['course_dept'] ?? null;
            if ($course !== null) $patient->course_dept = $course;
            if (array_key_exists('block', $validated)) $patient->block = $validated['block'];
            if (array_key_exists('address', $validated)) $patient->address = $validated['address'];
            if (array_key_exists('nationality', $validated)) $patient->nationality = $validated['nationality'];
            $phone = $validated['phone'] ?? $validated['contact'] ?? null;
            if ($phone !== null) $patient->contact = $phone;
            $emergName = $validated['emergencyContactName'] ?? $validated['emergency_contact_name'] ?? null;
            if ($emergName !== null) $patient->emergency_contact_name = $emergName;
            $emergPhone = $validated['emergencyContactPhone'] ?? $validated['emergency_contact_phone'] ?? null;
            if ($emergPhone !== null) $patient->emergency_contact_phone = $emergPhone;
            if ($emergName || $emergPhone) {
                $patient->emergency_contact = trim(($emergName ?? '') . ($emergPhone ? " ({$emergPhone})" : ''));
            }
            $patient->save();
            $patient->ensureMedicalRecord();

            if ($user->patient_id !== $patient->patient_id) {
                $user->update(['patient_id' => $patient->patient_id]);
            }
        }

        return new UserResource($user->fresh(['role', 'patient']));
    }

    /**
     * Reject a student ID that would merge two patients or share one patient
     * record between two accounts.
     */
    private function studentIdConflict(string $studentId, ?User $user, ?Patient $currentPatient): ?JsonResponse
    {
        $takenByOtherPatient = $currentPatient
            && Patient::where('patient_id', $studentId)->where('id', '!=', $currentPatient->id)->exists();
        $linkedToOtherUser = User::where('patient_id', $studentId)
            ->when($user, fn ($q) => $q->where('id', '!=', $user->id))
            ->exists();

        if ($takenByOtherPatient || $linkedToOtherUser) {
            return response()->json([
                'message' => 'This student ID already belongs to another patient or account.',
                'errors' => ['studentId' => ['This student ID is already in use.']],
            ], 422);
        }

        return null;
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

        // A deactivated account must lose access immediately, not just at next login.
        if ($user->status !== 'active') {
            $user->tokens()->delete();
        }

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
        $user->tokens()->delete();

        return response()->json(['message' => 'Password has been reset successfully.']);
    }

    /**
     * Soft-delete a user account and revoke its tokens.
     *
     * The linked patient record (and its clinical history) is kept. Admins
     * cannot delete themselves or the last active administrator.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 409);
        }

        if ($user->role?->name === 'admin') {
            $otherAdmins = User::where('id', '!=', $user->id)
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
                ->exists();
            if (! $otherAdmins) {
                return response()->json(['message' => 'You cannot delete the last active administrator.'], 409);
            }
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }
}
