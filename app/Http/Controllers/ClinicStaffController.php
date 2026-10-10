<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffProfileRequest;
use App\Http\Requests\VerifyStaffCredentialsRequest;
use App\Http\Resources\ClinicStaffResource;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Medical staff directory: doctors, nurses and front desk staff with their
 * profile, professional credentials (PRC license) and verification status,
 * plus their upcoming schedule and assigned patients.
 */
class ClinicStaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['role', 'staffProfile.verifier'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', User::CLINIC_STAFF_ROLES))
            ->withCount(['assignedAppointments as today_appointments_count' => fn ($q) => $q
                ->whereDate('date', now()->toDateString())
                ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'Completed'])]);

        if (($role = $request->query('role')) && $role !== 'All') {
            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }

        return ClinicStaffResource::collection($query->orderBy('name')->get());
    }

    public function show(User $user): ClinicStaffResource
    {
        $this->ensureClinicStaff($user);

        return new ClinicStaffResource($this->loadDetail($user));
    }

    /**
     * The signed-in staff member's own profile.
     */
    public function me(Request $request): ClinicStaffResource
    {
        $this->ensureClinicStaff($request->user());

        return new ClinicStaffResource($this->loadDetail($request->user()));
    }

    /**
     * A doctor/nurse submits or updates their own background and credentials.
     * Nobody else (including administrators) can edit these profiles.
     */
    public function updateMine(UpdateStaffProfileRequest $request): ClinicStaffResource
    {
        $this->ensureClinicStaff($request->user());

        return $this->saveProfile($request->user(), $request);
    }

    /**
     * Doctor/nurse directory for the student mobile app: read-only public
     * profile (background, specialization, credentials status) and the
     * upcoming available schedule.
     */
    public function publicIndex(): JsonResponse
    {
        $staff = User::with(['role', 'staffProfile', 'staffSchedules' => fn ($q) => $q
            ->where('status', 'Available')
            ->whereBetween('date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('date')
            ->orderBy('start_time')])
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', User::PROFILE_SELF_EDIT_ROLES))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $staff->map(fn (User $u) => $this->publicProfile($u))->values()]);
    }

    public function publicShow(User $user): JsonResponse
    {
        $user->loadMissing('role');
        if ($user->status !== 'active' || ! in_array($user->role?->name, User::PROFILE_SELF_EDIT_ROLES, true)) {
            abort(404, 'Medical staff member not found.');
        }

        $user->load(['staffProfile', 'staffSchedules' => fn ($q) => $q
            ->where('status', 'Available')
            ->whereBetween('date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('date')
            ->orderBy('start_time')]);

        return response()->json(['data' => $this->publicProfile($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicProfile(User $user): array
    {
        $profile = $user->staffProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role?->name,
            'position' => $profile?->position ?? '',
            'specialty' => $profile?->specialization ?: ($user->role?->name === 'nurse' ? 'Nursing Services' : 'General Medicine'),
            'specialization' => $profile?->specialization ?? '',
            'background' => $profile?->background ?? '',
            'contactNumber' => $profile?->contact_number ?? '',
            'email' => $user->email,
            'licenseType' => $profile?->license_type ?? '',
            'credentialStatus' => $profile?->credential_status ?? 'Not Submitted',
            'otherCredentials' => $profile?->other_credentials ?? '',
            'schedule' => $user->staffSchedules
                ->sortBy(fn ($s) => [$s->date?->format('Y-m-d'), ClinicSchedule::toMinutes($s->start_time)])
                ->map(fn ($s) => [
                    'day' => $s->date?->format('l, M j'),
                    'date' => $s->date?->format('Y-m-d'),
                    'startTime' => ClinicSchedule::normalize($s->start_time),
                    'endTime' => ClinicSchedule::normalize($s->end_time),
                ])->values()->all(),
        ];
    }

    /**
     * Administrator verification of the submitted license/credentials
     * (checked against the PRC online verification service).
     */
    public function verify(VerifyStaffCredentialsRequest $request, User $user): ClinicStaffResource|JsonResponse
    {
        $this->ensureClinicStaff($user);
        $profile = $user->staffProfile()->first();
        $status = $request->validated('status');

        if ($status === 'Verified') {
            if (! $profile?->license_number) {
                return response()->json([
                    'message' => 'There is no license number to verify. Ask the staff member to submit their credentials first.',
                ], 422);
            }
            if ($profile->isLicenseExpired()) {
                return response()->json([
                    'message' => 'This license has expired and cannot be marked as verified.',
                ], 422);
            }
        }

        $profile ??= $user->staffProfile()->create(['credential_status' => 'Not Submitted']);
        $profile->update([
            'credential_status' => $status,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'verification_notes' => $request->validated('notes'),
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => "Credentials {$status}",
            'message' => $status === 'Verified'
                ? "Your {$profile->license_type} ({$profile->license_number}) has been verified by the clinic administrator."
                : 'Your submitted credentials were not verified: '.$request->validated('notes'),
            'type' => 'system',
            'category' => 'system',
            'source' => 'Medical Staff',
        ]);

        return new ClinicStaffResource($this->loadDetail($user));
    }

    private function saveProfile(User $user, UpdateStaffProfileRequest $request): ClinicStaffResource
    {
        $validated = $request->validated();
        $profile = $user->staffProfile()->first() ?? new StaffProfile(['user_id' => $user->id]);

        $map = [
            'position' => 'position', 'specialization' => 'specialization', 'background' => 'background',
            'contactNumber' => 'contact_number',
            'licenseType' => 'license_type', 'licenseNumber' => 'license_number',
            'licenseIssuedAt' => 'license_issued_at', 'licenseExpiresAt' => 'license_expires_at',
            'otherCredentials' => 'other_credentials',
        ];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $profile->{$column} = $validated[$input];
            }
        }

        // Any change to the license details needs a fresh verification.
        if (! $profile->exists || $profile->isDirty(['license_type', 'license_number', 'license_issued_at', 'license_expires_at'])) {
            $profile->credential_status = $profile->license_number ? 'Pending Verification' : 'Not Submitted';
            $profile->verified_by = null;
            $profile->verified_at = null;
            $profile->verification_notes = null;
        }

        $profile->user_id = $user->id;
        $profile->save();

        return new ClinicStaffResource($this->loadDetail($user));
    }

    private function loadDetail(User $user): User
    {
        $today = now()->toDateString();

        return $user->load([
            'role',
            'staffProfile.verifier',
            'staffSchedules' => fn ($q) => $q->whereDate('date', '>=', $today)
                ->whereDate('date', '<=', now()->addDays(14)->toDateString())
                ->orderBy('date'),
            'assignedAppointments' => fn ($q) => $q->whereDate('date', '>=', $today)
                ->whereIn('status', Appointment::ACTIVE_STATUSES)
                ->orderBy('date'),
        ])->loadCount(['assignedAppointments as today_appointments_count' => fn ($q) => $q
            ->whereDate('date', $today)
            ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'Completed'])]);
    }

    private function ensureClinicStaff(User $user): void
    {
        $user->loadMissing('role');
        if (! in_array($user->role?->name, User::CLINIC_STAFF_ROLES, true)) {
            abort(404, 'This account is not a clinic staff member.');
        }
    }
}
