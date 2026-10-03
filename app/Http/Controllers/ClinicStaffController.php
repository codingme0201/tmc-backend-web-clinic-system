<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffProfileRequest;
use App\Http\Requests\VerifyStaffCredentialsRequest;
use App\Http\Resources\ClinicStaffResource;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\StaffProfile;
use App\Models\User;
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
     * A doctor/nurse submits or updates their own credentials.
     */
    public function updateMine(UpdateStaffProfileRequest $request): ClinicStaffResource
    {
        $this->ensureClinicStaff($request->user());

        return $this->saveProfile($request->user(), $request);
    }

    /**
     * An administrator edits a staff member's profile and credentials.
     */
    public function update(UpdateStaffProfileRequest $request, User $user): ClinicStaffResource
    {
        $this->ensureClinicStaff($user);

        return $this->saveProfile($user, $request);
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
            'position' => 'position', 'specialization' => 'specialization', 'contactNumber' => 'contact_number',
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
