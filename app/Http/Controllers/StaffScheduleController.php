<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffScheduleRequest;
use App\Http\Requests\UpdateStaffAvailabilityRequest;
use App\Http\Requests\UpdateStaffScheduleRequest;
use App\Http\Resources\StaffScheduleResource;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StaffScheduleController extends Controller
{
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

        return StaffScheduleResource::collection(
            $query->orderBy('date')->orderBy('start_time')->get()
        );
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
        $roleName = $user->role?->name ?? '';
        if (!in_array($roleName, ['doctor', 'nurse'])) {
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

        return (new StaffScheduleResource($schedule->load('user.role')))
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
            $roleName = $user->role?->name ?? '';
            if (!in_array($roleName, ['doctor', 'nurse'])) {
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

        return new StaffScheduleResource($schedule->fresh('user.role'));
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
        $staff = User::with('role')
            ->whereHas('role', function ($q) {
                $q->whereIn('name', ['doctor', 'nurse']);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return \App\Http\Resources\UserResource::collection($staff);
    }

    /**
     * Check for overlapping schedules for the same staff member on the same date.
     *
     * Two time blocks overlap if: startA < endB AND startB < endA
     */
    private function findConflict(
        int $userId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeId = null,
    ): ?StaffSchedule {
        $query = StaffSchedule::where('user_id', $userId)
            ->where('date', $date)
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($q2) use ($startTime, $endTime) {
                    // Existing schedule overlaps with new schedule
                    $q2->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
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
        $query = StaffSchedule::where('user_id', $userId)
            ->where('date', $date)
            ->where('status', 'Unavailable')
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }
}
