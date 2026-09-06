<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlockScheduleRequest;
use App\Http\Requests\StoreClinicEventRequest;
use App\Http\Requests\UpdateClinicEventRequest;
use App\Http\Resources\ClinicEventResource;
use App\Http\Resources\UnavailableScheduleResource;
use App\Models\Appointment;
use App\Models\ClinicEvent;
use App\Models\StaffSchedule;
use App\Models\UnavailableSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarController extends Controller
{
    /**
     * Aggregated calendar data — returns all data sources for a date range.
     *
     * The frontend sends `?start=YYYY-MM-DD&end=YYYY-MM-DD` and receives
     * a unified response with events, appointments, staff schedules, and
     * blocked periods for that range.
     */
    public function index(Request $request): JsonResponse
    {
        $start = $request->query('start');
        $end = $request->query('end');

        if (!$start || !$end) {
            return response()->json(['message' => 'Start and end date parameters are required.'], 422);
        }

        // Fetch clinic events in range
        $events = ClinicEvent::with('creator')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_date', '<=', $start)
                            ->where('end_date', '>=', $start);
                    });
            })
            ->get();

        // Fetch appointments in range
        $appointments = Appointment::whereIn('status', ['Pending', 'Approved', 'Under Review', 'Rescheduled'])
            ->whereBetween('date', [$start, $end])
            ->get();

        // Fetch staff schedules in range
        $schedules = StaffSchedule::with('user.role')
            ->whereBetween('date', [$start, $end])
            ->get();

        // Fetch blocked periods
        $blocked = UnavailableSchedule::with('creator')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_date', '<=', $start)
                            ->where('end_date', '>=', $start);
                    });
            })
            ->get();

        return response()->json([
            'events' => ClinicEventResource::collection($events),
            'appointments' => $appointments->map(fn ($a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'patient' => $a->patient,
                'type' => $a->type,
                'date' => $a->date->format('Y-m-d'),
                'time' => $a->time,
                'staff' => $a->staff,
                'status' => $a->status,
            ]),
            'schedules' => $schedules->map(fn ($s) => [
                'id' => $s->id,
                'userId' => $s->user_id,
                'userName' => $s->user->name,
                'userRole' => $s->user->role?->name,
                'date' => $s->date->format('Y-m-d'),
                'startTime' => $s->start_time,
                'endTime' => $s->end_time,
                'status' => $s->status,
            ]),
            'blocked' => UnavailableScheduleResource::collection($blocked),
        ]);
    }

    // ========== Clinic Event CRUD ==========

    /**
     * List clinic events with optional filtering.
     */
    public function indexEvents(Request $request): AnonymousResourceCollection
    {
        $query = ClinicEvent::with('creator');

        $type = $request->query('type');
        if ($type && $type !== 'All') {
            $query->where('type', $type);
        }

        $status = $request->query('status');
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return ClinicEventResource::collection($query->orderByDesc('id')->get());
    }

    /**
     * Show a single clinic event.
     */
    public function showEvent(ClinicEvent $event): ClinicEventResource
    {
        return new ClinicEventResource($event->load('creator'));
    }

    /**
     * Create a new clinic event.
     */
    public function storeEvent(StoreClinicEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // If no end_date provided, use start_date
        $validated['end_date'] = $validated['end_date'] ?? $validated['start_date'];

        // Backward compatibility: set the `date` column as a display string
        $validated['date'] = $this->formatDisplayDate($validated['start_date']);

        // Set the creator
        $validated['created_by'] = $request->user()->id;

        $event = ClinicEvent::create($validated);

        return (new ClinicEventResource($event->load('creator')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing clinic event.
     */
    public function updateEvent(UpdateClinicEventRequest $request, ClinicEvent $event): ClinicEventResource
    {
        $validated = $request->validated();

        // Update the display date if start_date changed
        if (isset($validated['start_date'])) {
            $validated['date'] = $this->formatDisplayDate($validated['start_date']);
        }

        $event->update($validated);

        return new ClinicEventResource($event->fresh('creator'));
    }

    /**
     * Delete a clinic event.
     */
    public function destroyEvent(ClinicEvent $event): JsonResponse
    {
        $event->delete();

        return response()->json(['message' => 'Event deleted successfully.']);
    }

    // ========== Unavailable Schedule (Block) ==========

    /**
     * List blocked periods.
     */
    public function indexBlocked(Request $request): AnonymousResourceCollection
    {
        $query = UnavailableSchedule::with('creator');

        return UnavailableScheduleResource::collection($query->orderByDesc('id')->get());
    }

    /**
     * Block a period as unavailable.
     */
    public function blockSchedule(BlockScheduleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['created_by'] = $request->user()->id;
        $validated['all_day'] = $validated['all_day'] ?? true;

        $block = UnavailableSchedule::create($validated);

        return (new UnavailableScheduleResource($block->load('creator')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a blocked period.
     */
    public function destroyBlock(UnavailableSchedule $block): JsonResponse
    {
        $block->delete();

        return response()->json(['message' => 'Blocked schedule removed successfully.']);
    }

    /**
     * Format a Y-m-d date string into a display format (e.g., "Aug 15, 2026").
     */
    private function formatDisplayDate(string $date): string
    {
        $parsed = \Carbon\Carbon::parse($date);
        return $parsed->format('M d, Y');
    }
}
