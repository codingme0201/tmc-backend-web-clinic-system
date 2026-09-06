<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    /**
     * List appointments, optionally filtered by search/status/date.
     *
     * The existing frontend also searches, filters, and paginates client-side
     * over this list, so the API returns the full (filtered) collection; the
     * query parameters are available for the future server-side swap.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::query();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('patient', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            // 'All' is the client-side sentinel for "no filter".
            if ($status !== 'All') {
                $query->where('status', $status);
            }
        }

        if ($date = $request->query('date')) {
            $query->where('date', $date);
        }

        $appointments = $query->orderBy('date')->orderBy('time')->get();

        return AppointmentResource::collection($appointments);
    }

    /**
     * Show a single appointment.
     */
    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment);
    }

    /**
     * Book a new appointment.
     */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Locked reference generation inside a transaction so concurrent
        // bookings can never produce a duplicate reference.
        $appointment = DB::transaction(function () use ($request, $validated) {
            $staffName = $validated['staff'] ?? '';
            $staffId = $staffName ? User::where('name', $staffName)->value('id') : null;

            return Appointment::create([
                ...$request->safe(['patient', 'patient_id', 'type', 'reason', 'date', 'time', 'staff']),
                'staff_id' => $staffId,
                'reference' => Appointment::nextReference($validated['date'], true),
                'status' => 'Pending',
                'requested_on' => now()->toDateString(),
            ]);
        });

        return (new AppointmentResource($appointment))->response()->setStatusCode(201);
    }

    /**
     * Move an appointment through its lifecycle.
     *
     * The required permission depends on the target status: approving demands
     * `appointments.approve`, rejecting `appointments.reject`, and review/
     * cancel/complete fall back to `appointments.update`. Enforced here (not
     * only by hiding buttons) so a direct API call is still rejected.
     */
    public function updateStatus(Appointment $appointment, UpdateAppointmentStatusRequest $request): AppointmentResource|JsonResponse
    {
        $status = $request->validated('status');

        if (! $request->user()->hasPermission($this->statusPermission($status))) {
            abort(403, 'You do not have permission to perform this action.');
        }

        if (! $appointment->canTransitionTo($status)) {
            return response()->json([
                'message' => "Cannot change appointment from \"{$appointment->status}\" to \"{$status}\".",
            ], 422);
        }

        $appointment->update([
            'status' => $status,
            'notes' => $this->appendNote($appointment->notes, $request->validated('note')),
        ]);

        return new AppointmentResource($appointment);
    }

    /**
     * Reschedule an appointment to a new date/time.
     *
     * Only open appointments (Pending, Under Review, Approved) can be
     * rescheduled; the action records the change in notes and moves the
     * appointment to the Rescheduled state, matching the existing UI.
     */
    public function reschedule(Appointment $appointment, RescheduleAppointmentRequest $request): AppointmentResource|JsonResponse
    {
        if (! in_array($appointment->status, ['Pending', 'Under Review', 'Approved'], true)) {
            return response()->json([
                'message' => "Appointments in \"{$appointment->status}\" status cannot be rescheduled.",
            ], 422);
        }

        $date = $request->validated('date');
        $time = $request->validated('time');
        $note = $request->validated('note');

        $rescheduleNote = sprintf(
            'Rescheduled from %s %s to %s %s%s',
            $appointment->date->format('Y-m-d'),
            $appointment->time,
            $date,
            $time,
            $note ? " — {$note}" : '',
        );

        $appointment->update([
            'date' => $date,
            'time' => $time,
            'status' => 'Rescheduled',
            'notes' => $this->appendNote($appointment->notes, $rescheduleNote),
        ]);

        return new AppointmentResource($appointment);
    }

    /**
     * Permission required to move an appointment to the given status.
     */
    private function statusPermission(string $status): string
    {
        return match ($status) {
            'Approved' => 'appointments.approve',
            'Rejected' => 'appointments.reject',
            default => 'appointments.update',
        };
    }

    /**
     * Append a note to the appointment's history, preserving existing notes.
     */
    private function appendNote(?string $existing, ?string $new): ?string
    {
        if (! $new) {
            return $existing;
        }

        return $existing ? "{$existing}\n{$new}" : $new;
    }
}
