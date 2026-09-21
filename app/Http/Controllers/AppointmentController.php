<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\UnavailableSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    /**
     * List appointments for the authenticated patient.
     */
    public function myAppointments(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $appointments = Appointment::where('patient_id', $patient->patient_id)
            ->orderBy('date')
            ->orderBy('time')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    /**
     * Request a new appointment for the authenticated patient.
     */
    public function storeMyAppointment(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string'],
            'reason' => ['required', 'string'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string'],
            'staff' => ['nullable', 'string'],
        ]);

        if ($conflict = $this->checkAppointmentConflict(
            $validated['date'],
            $validated['time'],
            $validated['staff'] ?? null,
            $patient->patient_id
        )) {
            return $conflict;
        }

        $appointment = DB::transaction(function () use ($request, $validated, $patient) {
            $staffName = $validated['staff'] ?? '';
            $staffId = $staffName ? User::where('name', $staffName)->value('id') : null;

            return Appointment::create([
                'patient' => $patient->name,
                'patient_id' => $patient->patient_id,
                'type' => $validated['type'],
                'reason' => $validated['reason'],
                'date' => $validated['date'],
                'time' => $validated['time'],
                'staff' => $staffName,
                'staff_id' => $staffId,
                'reference' => Appointment::nextReference($validated['date'], true),
                'status' => 'Pending',
                'requested_on' => now()->toDateString(),
            ]);
        });

        // Notify admin users
        $adminRoleUsers = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($adminRoleUsers as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => 'New Appointment Request',
                'message' => "{$patient->name} has requested a {$validated['type']} appointment for {$validated['date']} at {$validated['time']}.",
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }

        return (new AppointmentResource($appointment))->response()->setStatusCode(201);
    }

    /**
     * Show a single appointment for the authenticated patient.
     */
    public function showMyAppointment(Appointment $appointment, Request $request): AppointmentResource
    {
        $patient = $request->user()->patient;

        if (! $patient || $appointment->patient_id !== $patient->patient_id) {
            abort(403, 'You do not have permission to view this appointment.');
        }

        return new AppointmentResource($appointment);
    }

    /**
     * Reschedule an appointment by the authenticated patient.
     */
    public function rescheduleMyAppointment(Appointment $appointment, Request $request): AppointmentResource|JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient || $appointment->patient_id !== $patient->patient_id) {
            abort(403, 'You do not have permission to reschedule this appointment.');
        }

        if (! in_array($appointment->status, ['Pending', 'Under Review', 'Approved', 'Rescheduled'], true)) {
            return response()->json([
                'message' => "Appointments in \"{$appointment->status}\" status cannot be rescheduled.",
            ], 422);
        }

        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $date = $validated['date'];
        $time = $validated['time'];
        $reason = $validated['reason'] ?? '';

        if ($conflict = $this->checkAppointmentConflict(
            $date,
            $time,
            $appointment->staff,
            $patient->patient_id,
            $appointment->id
        )) {
            return $conflict;
        }

        $rescheduleNote = sprintf(
            'Patient requested reschedule from %s %s to %s %s%s',
            $appointment->date->format('Y-m-d'),
            $appointment->time,
            $date,
            $time,
            $reason ? " — {$reason}" : '',
        );

        $appointment->update([
            'date' => $date,
            'time' => $time,
            'status' => 'Rescheduled',
            'notes' => $this->appendNote($appointment->notes, $rescheduleNote),
        ]);

        $this->notifyReschedule($appointment, $date, $time);

        return new AppointmentResource($appointment);
    }

    /**
     * Cancel an appointment by the authenticated patient.
     */
    public function cancelMyAppointment(Appointment $appointment, Request $request): AppointmentResource|JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient || $appointment->patient_id !== $patient->patient_id) {
            abort(403, 'You do not have permission to cancel this appointment.');
        }

        if (in_array($appointment->status, ['Completed', 'Cancelled', 'No-Show'], true)) {
            return response()->json([
                'message' => "Appointments in \"{$appointment->status}\" status cannot be cancelled.",
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $cancelReason = $validated['reason'] ?? 'Cancelled by patient';

        $appointment->update([
            'status' => 'Cancelled',
            'notes' => $this->appendNote($appointment->notes, "Patient cancelled: {$cancelReason}"),
        ]);

        $this->notifyStatusChange($appointment, 'Cancelled');

        return new AppointmentResource($appointment);
    }

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

        if ($conflict = $this->checkAppointmentConflict(
            $validated['date'],
            $validated['time'],
            $validated['staff'] ?? null,
            $validated['patient_id'] ?? null
        )) {
            return $conflict;
        }

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

        // Notify admin users about new appointment requests.
        $adminRoleUsers = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($adminRoleUsers as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => 'New Appointment Request',
                'message' => "{$validated['patient']} has requested a {$validated['type']} appointment for {$validated['date']} at {$validated['time']}.",
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }

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
        if ($status === 'Confirmed') {
            $status = 'Approved';
        }

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

        $this->notifyStatusChange($appointment, $status);

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

        if ($conflict = $this->checkAppointmentConflict(
            $date,
            $time,
            $appointment->staff,
            $appointment->patient_id,
            $appointment->id
        )) {
            return $conflict;
        }

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

        $this->notifyReschedule($appointment, $date, $time);

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

    /**
     * Generate notification when appointment status changes.
     */
    private function notifyStatusChange(Appointment $appointment, string $status): void
    {
        $statusMessages = [
            'Approved' => "Your appointment {$appointment->reference} ({$appointment->type}) has been approved for {$appointment->date->format('Y-m-d')} at {$appointment->time}.",
            'Rejected' => "Your appointment {$appointment->reference} ({$appointment->type}) has been rejected.",
            'Cancelled' => "Your appointment {$appointment->reference} ({$appointment->type}) has been cancelled.",
            'Completed' => "Your appointment {$appointment->reference} ({$appointment->type}) has been marked as completed.",
            'Under Review' => "Your appointment {$appointment->reference} ({$appointment->type}) is now under review.",
            'No-Show' => "Your appointment {$appointment->reference} ({$appointment->type}) has been recorded as No-Show.",
        ];

        $message = $statusMessages[$status] ?? "Your appointment {$appointment->reference} status has been updated to {$status}.";

        // Notify the patient if they have a user account.
        if ($appointment->patient_id) {
            $patientUser = User::where('patient_id', $appointment->patient_id)->first();
            if ($patientUser) {
                Notification::create([
                    'user_id' => $patientUser->id,
                    'title' => "Appointment {$status}",
                    'message' => $message,
                    'type' => 'appointment',
                    'category' => 'appointment',
                    'source' => 'Appointments',
                    'metadata' => ['appointment_reference' => $appointment->reference],
                ]);
            }
        }

        // Also notify admin for tracking.
        $adminRoleUsers = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($adminRoleUsers as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => "Appointment {$status}",
                'message' => "Appointment {$appointment->reference} for {$appointment->patient} has been {$status}.",
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }
    }

    /**
     * Generate notification when appointment is rescheduled.
     */
    private function notifyReschedule(Appointment $appointment, string $newDate, string $newTime): void
    {
        $message = "Your appointment {$appointment->reference} ({$appointment->type}) has been rescheduled to {$newDate} at {$newTime}.";

        if ($appointment->patient_id) {
            $patientUser = User::where('patient_id', $appointment->patient_id)->first();
            if ($patientUser) {
                Notification::create([
                    'user_id' => $patientUser->id,
                    'title' => 'Appointment Rescheduled',
                    'message' => $message,
                    'type' => 'appointment',
                    'category' => 'appointment',
                    'source' => 'Appointments',
                    'metadata' => ['appointment_reference' => $appointment->reference],
                ]);
            }
        }

        $adminRoleUsers = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($adminRoleUsers as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => 'Appointment Rescheduled',
                'message' => "Appointment {$appointment->reference} for {$appointment->patient} has been rescheduled to {$newDate} at {$newTime}.",
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }
    }

    /**
     * Check if an appointment booking collides with an existing appointment
     * or a blocked clinic/doctor schedule.
     */
    protected function checkAppointmentConflict(
        string $date,
        string $time,
        ?string $staff = null,
        ?string $patientId = null,
        ?int $ignoreAppointmentId = null
    ): ?JsonResponse {
        // 1. Check if the clinic schedule is blocked in UnavailableSchedule
        $isBlocked = UnavailableSchedule::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where(function ($q) use ($time) {
                $q->where('all_day', true)
                    ->orWhere(function ($sub) use ($time) {
                        $sub->whereNotNull('start_time')
                            ->whereNotNull('end_time')
                            ->where('start_time', '<=', $time)
                            ->where('end_time', '>=', $time);
                    });
            })
            ->exists();

        if ($isBlocked) {
            return response()->json([
                'message' => 'The selected date or time slot is unavailable due to a clinic schedule block.',
            ], 422);
        }

        // 2. Check if the staff/doctor already has an active appointment at that date & time
        $cleanStaff = trim((string) $staff);
        if ($cleanStaff !== '' && strtolower($cleanStaff) !== 'any available') {
            $staffConflict = Appointment::where('date', $date)
                ->where('time', $time)
                ->where('staff', $cleanStaff)
                ->whereIn('status', ['Pending', 'Under Review', 'Approved', 'Rescheduled'])
                ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
                ->exists();

            if ($staffConflict) {
                return response()->json([
                    'message' => "The selected doctor/staff ({$cleanStaff}) already has an appointment booked on {$date} at {$time}.",
                ], 422);
            }
        }

        // 3. Check if the patient already has an active appointment at that date & time
        if ($patientId) {
            $patientConflict = Appointment::where('date', $date)
                ->where('time', $time)
                ->where('patient_id', $patientId)
                ->whereIn('status', ['Pending', 'Under Review', 'Approved', 'Rescheduled'])
                ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
                ->exists();

            if ($patientConflict) {
                return response()->json([
                    'message' => "You already have an appointment scheduled for {$date} at {$time}.",
                ], 422);
            }
        }

        return null;
    }
}
