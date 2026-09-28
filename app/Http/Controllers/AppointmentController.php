<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Models\UnavailableSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        if (! SystemSetting::getInstance()->online_appointments_enabled) {
            return response()->json([
                'message' => 'Online appointment requests are currently disabled. Please contact or visit the clinic.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(Appointment::TYPES)],
            'reason' => ['required', 'string', 'max:1000'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', Rule::in(Appointment::TIME_SLOTS)],
            'staff_id' => ['nullable', 'integer'],
            'staff' => ['nullable', 'string', 'max:255'],
        ]);

        // Prefer the staff user ID (from /me/appointment-options); the name
        // field is kept for older app builds.
        $staffUser = null;
        if (! empty($validated['staff_id'])) {
            $staffUser = $this->bookableStaffQuery()->find($validated['staff_id']);
            if (! $staffUser) {
                return response()->json([
                    'message' => 'The selected doctor/nurse is not available for booking.',
                    'errors' => ['staff_id' => ['The selected doctor/nurse is invalid.']],
                ], 422);
            }
        } elseif (! empty($validated['staff'])) {
            $staffUser = $this->bookableStaffQuery()->where('name', $validated['staff'])->first();
        }
        $staffName = $staffUser?->name ?? ($validated['staff'] ?? '');

        if ($conflict = $this->checkAppointmentConflict(
            $validated['date'],
            $validated['time'],
            $staffName,
            $patient->patient_id,
            null,
            true,
        )) {
            return $conflict;
        }

        $appointment = DB::transaction(function () use ($validated, $patient, $staffName, $staffUser) {
            $staffId = $staffUser?->id;

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
     * Booking options for the mobile Request Appointment form: appointment
     * types, time slots and the active doctors/nurses (by user ID).
     */
    public function appointmentOptions(): JsonResponse
    {
        $settings = SystemSetting::getInstance();

        return response()->json([
            'data' => [
                'types' => Appointment::TYPES,
                'timeSlots' => Appointment::TIME_SLOTS,
                'doctors' => $this->bookableStaffQuery()->with('role')->orderBy('name')->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name])
                    ->values(),
                'onlineAppointmentsEnabled' => (bool) $settings->online_appointments_enabled,
            ],
        ]);
    }

    /**
     * Slot availability for a date (and optionally a doctor/nurse), so the
     * mobile app can disable taken, blocked and full slots before submitting.
     *
     * Only availability is exposed — never who holds a slot.
     */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
        ]);

        $date = $validated['date'];
        $patientId = $request->user()->patient?->patient_id;
        $settings = SystemSetting::getInstance();
        $buffer = (int) $settings->appointment_buffer_minutes;
        $staffName = ! empty($validated['staff_id'])
            ? $this->bookableStaffQuery()->whereKey($validated['staff_id'])->value('name')
            : null;

        $blocks = UnavailableSchedule::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();
        $active = $this->activeAppointmentsOn($date)->get(['time', 'staff', 'patient_id']);
        $isFull = $this->isDayFull($date, $settings);
        $isPast = $date < now()->toDateString();

        $slots = collect(Appointment::TIME_SLOTS)->map(function (string $slot) use ($blocks, $active, $staffName, $patientId, $buffer, $isFull, $isPast) {
            $minutes = self::timeToMinutes($slot);
            $reason = match (true) {
                $isPast => 'past',
                $blocks->contains(fn ($b) => self::blockCovers($b, $minutes)) => 'blocked',
                $patientId && $active->contains(fn ($a) => $a->patient_id === $patientId && self::timesOverlap($a->time, $slot, 0)) => 'yours',
                $staffName && $active->contains(fn ($a) => $a->staff === $staffName && self::timesOverlap($a->time, $slot, $buffer)) => 'booked',
                $isFull => 'full',
                default => null,
            };

            return ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
        })->values();

        return response()->json([
            'data' => [
                'date' => $date,
                'onlineAppointmentsEnabled' => (bool) $settings->online_appointments_enabled,
                'isFull' => $isFull,
                'slots' => $slots,
            ],
        ]);
    }

    /**
     * Active doctor/nurse accounts that students may book.
     */
    private function bookableStaffQuery()
    {
        return User::where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['doctor', 'nurse']));
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
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', Rule::in(Appointment::TIME_SLOTS)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $date = $validated['date'];
        $time = $validated['time'];
        $reason = $validated['reason'] ?? '';

        if ($conflict = $this->checkAppointmentConflict(
            $date,
            $time,
            $appointment->staff,
            $patient->patient_id,
            $appointment->id,
            true,
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
     * Statuses that occupy a slot.
     */
    private const ACTIVE_STATUSES = ['Pending', 'Under Review', 'Approved', 'Rescheduled'];

    /**
     * Check if an appointment booking collides with an existing appointment
     * or a blocked clinic/doctor schedule.
     *
     * Times are compared as minutes after midnight, so "01:00 PM" (slot
     * format) and "13:00" (block format) compare correctly. The staff check
     * honours the appointment buffer from System Settings, and the daily cap
     * is applied when $enforceDailyLimit is set (student self-service).
     */
    protected function checkAppointmentConflict(
        string $date,
        string $time,
        ?string $staff = null,
        ?string $patientId = null,
        ?int $ignoreAppointmentId = null,
        bool $enforceDailyLimit = false,
    ): ?JsonResponse {
        $settings = SystemSetting::getInstance();
        $minutes = self::timeToMinutes($time);

        // 1. Clinic schedule blocks
        $blocks = UnavailableSchedule::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();

        if ($blocks->contains(fn ($block) => self::blockCovers($block, $minutes))) {
            return response()->json([
                'message' => 'The selected date or time slot is unavailable due to a clinic schedule block.',
            ], 422);
        }

        // 2. Daily appointment cap
        if ($enforceDailyLimit && $this->isDayFull($date, $settings, $ignoreAppointmentId)) {
            return response()->json([
                'message' => "The clinic has reached its maximum number of appointments for {$date}. Please choose another date.",
            ], 422);
        }

        // 3. The staff member already has an active appointment within the buffer window
        $cleanStaff = trim((string) $staff);
        if ($cleanStaff !== '' && strtolower($cleanStaff) !== 'any available') {
            $staffConflict = $this->activeAppointmentsOn($date, $ignoreAppointmentId)
                ->where('staff', $cleanStaff)
                ->get(['time'])
                ->contains(fn ($a) => self::timesOverlap($a->time, $time, (int) $settings->appointment_buffer_minutes));

            if ($staffConflict) {
                return response()->json([
                    'message' => "The selected doctor/staff ({$cleanStaff}) already has an appointment booked on {$date} at {$time}.",
                ], 422);
            }
        }

        // 4. The patient already has an active appointment at that date & time
        if ($patientId) {
            $patientConflict = $this->activeAppointmentsOn($date, $ignoreAppointmentId)
                ->where('patient_id', $patientId)
                ->get(['time'])
                ->contains(fn ($a) => self::timesOverlap($a->time, $time, 0));

            if ($patientConflict) {
                return response()->json([
                    'message' => "You already have an appointment scheduled for {$date} at {$time}.",
                ], 422);
            }
        }

        return null;
    }

    /**
     * Active appointments on a date, optionally excluding one appointment.
     */
    private function activeAppointmentsOn(string $date, ?int $ignoreAppointmentId = null)
    {
        return Appointment::whereDate('date', $date)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId));
    }

    /**
     * Whether the date has reached System Settings' max_daily_appointments.
     */
    private function isDayFull(string $date, SystemSetting $settings, ?int $ignoreAppointmentId = null): bool
    {
        $max = (int) $settings->max_daily_appointments;

        return $max > 0 && $this->activeAppointmentsOn($date, $ignoreAppointmentId)->count() >= $max;
    }

    /**
     * Convert "01:30 PM", "13:30" or "13:30:00" to minutes after midnight.
     */
    private static function timeToMinutes(?string $time): ?int
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $parsed = date_parse($time);
        if ($parsed['error_count'] > 0 || $parsed['hour'] === false) {
            return null;
        }

        return $parsed['hour'] * 60 + (int) $parsed['minute'];
    }

    /**
     * Whether two appointment times fall within $bufferMinutes of each other
     * (exact match when the buffer is 0). Falls back to string equality when
     * either time cannot be parsed.
     */
    private static function timesOverlap(?string $a, ?string $b, int $bufferMinutes): bool
    {
        $am = self::timeToMinutes($a);
        $bm = self::timeToMinutes($b);

        if ($am === null || $bm === null) {
            return trim((string) $a) === trim((string) $b);
        }

        return abs($am - $bm) < max(1, $bufferMinutes);
    }

    /**
     * Whether a schedule block covers the given time. All-day blocks (or
     * blocks without both times) cover the whole day; timed blocks cover
     * [start, end).
     */
    private static function blockCovers(UnavailableSchedule $block, ?int $minutes): bool
    {
        if ($block->all_day || ! $block->start_time || ! $block->end_time) {
            return true;
        }

        $start = self::timeToMinutes($block->start_time);
        $end = self::timeToMinutes($block->end_time);

        if ($minutes === null || $start === null || $end === null) {
            return true;
        }

        return $minutes >= $start && $minutes < $end;
    }
}
