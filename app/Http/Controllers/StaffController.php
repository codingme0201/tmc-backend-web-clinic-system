<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    /**
     * Today's duty roster: every active, assignable doctor and nurse with
     * today's shifts from the Doctor/Nurse Schedule. A clinician is On duty
     * while one of today's Available shifts covers the current time and Off
     * duty otherwise; a status set manually on the dashboard today (e.g.
     * Break) takes precedence until the end of the day.
     */
    public function index(): AnonymousResourceCollection
    {
        $today = now()->toDateString();
        $minutesNow = (int) now()->format('G') * 60 + (int) now()->format('i');

        $clinicians = User::clinicians()
            ->with(['role', 'staffProfile', 'staffSchedules' => fn ($q) => $q->whereDate('date', $today)])
            ->orderBy('name')
            ->get();

        $overrides = Staff::whereIn('user_id', $clinicians->pluck('id'))
            ->whereDate('updated_at', $today)
            ->get()
            ->keyBy('user_id');

        return StaffResource::collection(
            $clinicians->map(fn (User $user) => $this->rosterEntry($user, $overrides->get($user->id), $minutesNow)),
        );
    }

    /**
     * Set a clinician's duty status for today (On duty / Break / Off duty).
     * Older clients send the staff name; newer ones send the user ID.
     */
    public function updateStatus(Request $request): StaffResource|JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(Staff::STATUSES)],
        ]);

        $user = User::clinicians()
            ->with(['role', 'staffProfile', 'staffSchedules' => fn ($q) => $q->whereDate('date', now()->toDateString())])
            ->when(
                ! empty($validated['user_id']),
                fn ($q) => $q->whereKey($validated['user_id']),
                fn ($q) => $q->where('name', $validated['name']),
            )
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Only active doctors and nurses appear on the duty roster.',
                'errors' => ['name' => ['The selected staff member is not an active doctor or nurse.']],
            ], 422);
        }

        $entry = Staff::where('user_id', $user->id)->first()
            ?? Staff::where('name', $user->name)->first()
            ?? new Staff();
        $entry->fill([
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $this->roleLabel($user),
            'shift' => $this->shiftLabel($user->staffSchedules),
            'status' => $validated['status'],
        ]);
        $entry->updated_at = now();
        $entry->save();

        $minutesNow = (int) now()->format('G') * 60 + (int) now()->format('i');

        return new StaffResource($this->rosterEntry($user, $entry, $minutesNow));
    }

    private function rosterEntry(User $user, ?Staff $override, int $minutesNow): Staff
    {
        $shifts = $user->staffSchedules;
        $onShiftNow = $shifts->contains(function (StaffSchedule $s) use ($minutesNow) {
            $start = ClinicSchedule::toMinutes($s->start_time);
            $end = ClinicSchedule::toMinutes($s->end_time);

            return $s->status === 'Available' && $start !== null && $end !== null
                && $minutesNow >= $start && $minutesNow < $end;
        });

        $entry = new Staff([
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $this->roleLabel($user),
            'shift' => $this->shiftLabel($shifts),
            'status' => $override?->status ?? ($onShiftNow ? 'On duty' : 'Off duty'),
        ]);
        $entry->id = $user->id;
        $entry->department = $user->clinicalDepartment();
        $entry->scheduled_today = $shifts->contains('status', 'Available');
        $entry->on_shift_now = $onShiftNow;
        $entry->status_source = $override ? 'manual' : 'schedule';

        return $entry;
    }

    private function roleLabel(User $user): string
    {
        $profile = $user->staffProfile;

        return $profile?->position ?: ($profile?->specialization ?: ucfirst((string) $user->role?->name));
    }

    private function shiftLabel($shifts): string
    {
        $available = $shifts->where('status', 'Available')
            ->sortBy(fn ($s) => ClinicSchedule::toMinutes($s->start_time))
            ->map(fn ($s) => ClinicSchedule::normalize($s->start_time).' - '.ClinicSchedule::normalize($s->end_time));

        if ($available->isNotEmpty()) {
            return $available->implode(', ');
        }

        return $shifts->isNotEmpty() ? 'Unavailable today' : 'No shift scheduled today';
    }
}
