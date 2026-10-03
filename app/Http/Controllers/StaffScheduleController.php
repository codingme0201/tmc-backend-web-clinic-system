<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffScheduleRequest;
use App\Http\Requests\UpdateStaffAvailabilityRequest;
use App\Http\Requests\UpdateStaffScheduleRequest;
use App\Http\Resources\StaffScheduleResource;
use App\Models\Appointment;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class StaffScheduleController extends Controller
{
    /**
     * Upcoming doctor/nurse availability for the student mobile app.
     *
     * Only `Available` entries from today through the next 30 days are
     * returned, grouped per staff member, without internal notes or IDs of
     * unavailable slots.
     */
    public function publicIndex(): JsonResponse
    {
        $today = now()->toDateString();

        $schedules = StaffSchedule::with('user.role')
            ->where('status', 'Available')
            ->whereBetween('date', [$today, now()->addDays(30)->toDateString()])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $data = $schedules->groupBy('user_id')->map(function ($entries) {
            $user = $entries->first()->user;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->name,
                'schedule' => $entries->map(fn ($s) => [
                    'day' => $s->date?->format('l, M j'),
                    'date' => $s->date?->format('Y-m-d'),
                    'startTime' => $s->start_time,
                    'endTime' => $s->end_time,
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * List staff schedules with optional filtering.
     *
     * Supports filtering by user_id, date, date_from, date_to, status,
     * and search (by staff name). Results include the user relationship.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StaffSchedule::with('user.role');

        // Filter by specific user
        $userId = $request->query('user_id');
        if ($userId) {
            $query->where('user_id', $userId);
        }

        // Filter by specific date
        $date = $request->query('date');
        if ($date) {
            $query->where('date', $date);
        }

        // Filter by date range
        $dateFrom = $request->query('date_from');
        if ($dateFrom) {
            $query->where('date', '>=', $dateFrom);
        }

        $dateTo = $request->query('date_to');
        if ($dateTo) {
            $query->where('date', '<=', $dateTo);
        }

        // Filter by status
        $status = $request->query('status');
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        // Search by staff name
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Filter by role (doctor/nurse)
        $role = $request->query('role');
        if ($role && $role !== 'All') {
            $query->whereHas('user.role', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        $schedules = $query->orderBy('date')->get()
            ->sortBy(fn (StaffSchedule $s) => [$s->date?->format('Y-m-d'), ClinicSchedule::toMinutes($s->start_time)])
            ->values();

        return StaffScheduleResource::collection($this->attachBookings($schedules));
    }

    /**
     * Show a single staff schedule.
     */
    public function show(StaffSchedule $schedule): StaffScheduleResource
    {
        return new StaffScheduleResource($schedule->load('user.role'));
    }

    /**
     * Create a new staff schedule entry.
     *
     * Validates that the user is a doctor or nurse, checks for scheduling
     * conflicts (overlapping time blocks on the same date), and ensures
     * the user is not marked as unavailable for the requested period.
     */
    public function store(StoreStaffScheduleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Verify the user is eligible (doctor or nurse)
        $user = User::with('role')->findOrFail($validated['user_id']);
        if (! $user->isClinician()) {
            return response()->json([
                'message' => 'Only doctors and nurses can be assigned schedules.',
            ], 422);
        }

        // Check for scheduling conflicts (overlapping time blocks)
        $conflict = $this->findConflict(
            $validated['user_id'],
            $validated['date'],
            $validated['start_time'],
            $validated['end_time']
        );

        if ($conflict) {
            return response()->json([
                'message' => 'This staff member already has a schedule during the selected time period.',
                'conflict' => new StaffScheduleResource($conflict->load('user.role')),
            ], 409);
        }

        // Check if staff is unavailable for this period
        $unavailable = $this->findUnavailable(
            $validated['user_id'],
            $validated['date'],
            $validated['start_time'],
            $validated['end_time']
        );

        if ($unavailable) {
            return response()->json([
                'message' => 'This staff member is marked as unavailable for the selected time period.',
                'conflict' => new StaffScheduleResource($unavailable->load('user.role')),
            ], 409);
        }

        $schedule = StaffSchedule::create($validated);

        return (new StaffScheduleResource($this->attachBookings(collect([$schedule->load('user.role')]))->first()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing staff schedule entry.
     *
     * Re-validifies conflicts if date/time or user changes.
     */
    public function update(UpdateStaffScheduleRequest $request, StaffSchedule $schedule): StaffScheduleResource
    {
        $validated = $request->validated();

        // If user changed, verify they are eligible
        $userId = $validated['user_id'] ?? $schedule->user_id;
        if (isset($validated['user_id']) && $validated['user_id'] !== $schedule->user_id) {
            $user = User::with('role')->findOrFail($userId);
            if (! $user->isClinician()) {
                abort(422, 'Only doctors and nurses can be assigned schedules.');
            }
        }

        // Check conflicts (exclude current schedule)
        $date = $validated['date'] ?? $schedule->date->format('Y-m-d');
        $startTime = $validated['start_time'] ?? $schedule->start_time;
        $endTime = $validated['end_time'] ?? $schedule->end_time;

        $conflict = $this->findConflict(
            $userId,
            $date,
            $startTime,
            $endTime,
            $schedule->id
        );

        if ($conflict) {
            abort(409, 'This staff member already has a schedule during the selected time period.');
        }

        // Check availability
        $unavailable = $this->findUnavailable(
            $userId,
            $date,
            $startTime,
            $endTime,
            $schedule->id
        );

        if ($unavailable) {
            abort(409, 'This staff member is marked as unavailable for the selected time period.');
        }

        $schedule->update($validated);

        return new StaffScheduleResource($this->attachBookings(collect([$schedule->fresh('user.role')]))->first());
    }

    /**
     * Delete a staff schedule entry.
     */
    public function destroy(StaffSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted successfully.']);
    }

    /**
     * Update the availability status of a staff schedule entry.
     */
    public function updateAvailability(
        UpdateStaffAvailabilityRequest $request,
        StaffSchedule $schedule,
    ): StaffScheduleResource {
        $schedule->update(['status' => $request->validated('status')]);

        return new StaffScheduleResource($schedule->fresh('user.role'));
    }

    /**
     * Fetch eligible staff members (doctors and nurses) for schedule assignment.
     */
    public function eligibleStaff(): AnonymousResourceCollection
    {
        $staff = User::clinicians()
            ->with(['role', 'staffProfile'])
            ->orderBy('name')
            ->get();

        return \App\Http\Resources\UserResource::collection($staff);
    }

    /**
     * Check for overlapping schedules for the same staff member on the same
     * date. Times are compared as minutes (not strings), so "10:00 AM" is
     * correctly after "08:00 AM". Two blocks overlap if startA < endB and
     * startB < endA.
     */
    private function findConflict(
        int $userId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeId = null,
        ?string $status = null,
    ): ?StaffSchedule {
        return StaffSchedule::where('user_id', $userId)
            ->whereDate('date', $date)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->first(fn (StaffSchedule $s) => ClinicSchedule::rangesOverlap($s->start_time, $s->end_time, $startTime, $endTime));
    }

    /**
     * Check if the staff member is marked as unavailable during the requested period.
     */
    private function findUnavailable(
        int $userId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeId = null,
    ): ?StaffSchedule {
        return $this->findConflict($userId, $date, $startTime, $endTime, $excludeId, 'Unavailable');
    }

    /**
     * Attach each schedule's assigned patients (active appointments for that
     * doctor on that date within the shift), so appointment assignments show
     * on the doctor/nurse schedule.
     */
    private function attachBookings(Collection $schedules): Collection
    {
        if ($schedules->isEmpty()) {
            return $schedules;
        }

        $appointments = Appointment::whereIn('staff_id', $schedules->pluck('user_id')->unique()->values())
            ->whereIn('date', $schedules->map(fn ($s) => $s->date?->format('Y-m-d'))->unique()->values())
            ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'Completed'])
            ->get();

        return $schedules->each(function (StaffSchedule $schedule) use ($appointments) {
            $start = ClinicSchedule::toMinutes($schedule->start_time);
            $end = ClinicSchedule::toMinutes($schedule->end_time);
            $schedule->booked_appointments = Appointment::sortFifo($appointments->filter(function ($a) use ($schedule, $start, $end) {
                $minutes = ClinicSchedule::toMinutes($a->time);

                return $a->staff_id === $schedule->user_id
                    && $a->date?->format('Y-m-d') === $schedule->date?->format('Y-m-d')
                    && $minutes !== null && $start !== null && $end !== null
                    && $minutes >= $start && $minutes < $end;
            }));
        });
    }
}
