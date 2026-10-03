<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignAppointmentStaffRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    /**
     * Relations the appointment screens display (assigned doctor, follow-up
     * source and linked consultation).
     */
    private const DETAIL_RELATIONS = ['staffUser.role', 'previousConsultation', 'consultations'];

    public function __construct(private readonly AppointmentScheduler $scheduler)
    {
    }

    /**
     * List appointments for the authenticated patient.
     */
    public function myAppointments(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $appointments = Appointment::with(self::DETAIL_RELATIONS)
            ->where('patient_id', $patient->patient_id)
            ->get();

        return AppointmentResource::collection(Appointment::sortFifo($appointments));
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
            'visit_type' => ['nullable', 'string', Rule::in(Appointment::VISIT_TYPES)],
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
            $staffUser = $this->scheduler->clinicians()->find($validated['staff_id']);
            if (! $staffUser) {
                return response()->json([
                    'message' => 'The selected doctor/nurse is not available for booking.',
                    'errors' => ['staff_id' => ['The selected doctor/nurse is invalid.']],
                ], 422);
            }
        } elseif (! empty($validated['staff'])) {
            $staffUser = $this->scheduler->clinicians()->where('name', $validated['staff'])->first();
        }
        $staffName = $staffUser?->name ?? ($validated['staff'] ?? '');

        if ($conflict = $this->conflictResponse(
            $validated['date'],
            $validated['time'],
            $staffUser?->id,
            $staffName,
            $patient->patient_id,
            null,
            true,
        )) {
            return $conflict;
        }

        $appointment = DB::transaction(fn () => Appointment::create([
            'patient' => $patient->name,
            'patient_id' => $patient->patient_id,
            'type' => $validated['type'],
            'visit_type' => Appointment::resolveVisitType($validated['visit_type'] ?? null, $validated['type']),
            'reason' => $validated['reason'],
            'date' => $validated['date'],
            'time' => $validated['time'],
            'staff' => $staffName,
            'staff_id' => $staffUser?->id,
            'reference' => Appointment::nextReference($validated['date'], true),
            'status' => 'Pending',
            'requested_on' => now()->toDateString(),
        ]));

        $this->notifyAdmins(
            'New Appointment Request',
            "{$patient->name} has requested a {$validated['type']} appointment for {$validated['date']} at {$validated['time']}.",
            $appointment,
        );

        return (new AppointmentResource($appointment->load(self::DETAIL_RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Booking options for the mobile Request Appointment form: appointment
     * types, visit types, time slots and the active doctors/nurses.
     */
    public function appointmentOptions(): JsonResponse
    {
        $settings = SystemSetting::getInstance();

        return response()->json([
            'data' => [
                'types' => Appointment::TYPES,
                'visitTypes' => Appointment::VISIT_TYPES,
                'timeSlots' => Appointment::TIME_SLOTS,
                'doctors' => $this->scheduler->clinicians()->with('role')->orderBy('name')->get()
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

        $settings = SystemSetting::getInstance();
        $staff = ! empty($validated['staff_id'])
            ? $this->scheduler->clinicians()->find($validated['staff_id'])
            : null;

        return response()->json([
            'data' => [
                'date' => $validated['date'],
                'onlineAppointmentsEnabled' => (bool) $settings->online_appointments_enabled,
                'isFull' => $this->scheduler->isDayFull($validated['date'], $settings),
                'slots' => $this->scheduler->slotAvailability(
                    $validated['date'],
                    $staff,
                    $request->user()->patient?->patient_id,
                ),
            ],
        ]);
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

        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
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

        if ($conflict = $this->conflictResponse(
            $date,
            $time,
            $appointment->staff_id,
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
            'queue_number' => null,
            'checked_in_at' => null,
            'notes' => $this->appendNote($appointment->notes, $rescheduleNote),
        ]);

        $this->notifyReschedule($appointment, $date, $time);

        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
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

        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
    }

    /**
     * List appointments, optionally filtered by search/status/date/visit type,
     * in first-in-first-out order (date, time slot, check-in, booking time).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::with(self::DETAIL_RELATIONS);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('patient', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        // 'All' is the client-side sentinel for "no filter".
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        if ($date = $request->query('date')) {
            $query->where('date', $date);
        }

        $visitType = $request->query('visit_type');
        if ($visitType && $visitType !== 'All') {
            $query->where('visit_type', $visitType);
        }

        if ($staffId = $request->query('staff_id')) {
            $query->where('staff_id', $staffId);
        }

        return AppointmentResource::collection(Appointment::sortFifo($query->get()));
    }

    /**
     * A patient's completed consultations, so a follow-up booking can be
     * linked to the visit the patient is returning for. Diagnoses are only
     * included for users who may view consultations.
     */
    public function followUpOptions(Request $request): JsonResponse
    {
        $validated = $request->validate(['patient_id' => ['required', 'string', 'max:50']]);
        $clinical = $request->user()->hasPermission('consultations.view');

        $consultations = Consultation::where('patient_id', $validated['patient_id'])
            ->where('status', 'Completed')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $consultations->map(fn (Consultation $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'date' => $c->date?->format('Y-m-d'),
                'staff' => $c->staff ?? '',
                'staffId' => $c->staff_id,
                'followUpRequired' => (bool) $c->follow_up_required,
                ...($clinical ? ['diagnosis' => $c->diagnosis ?? '', 'chiefComplaint' => $c->chief_complaint ?? ''] : []),
            ])->values(),
        ]);
    }

    /**
     * Show a single appointment.
     */
    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
    }

    /**
     * Book a new appointment (front desk / clinic staff).
     */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $staffUser = null;
        if (! empty($validated['staff_id'])) {
            $staffUser = $this->scheduler->clinicians()->find($validated['staff_id']);
            if (! $staffUser) {
                return $this->invalidStaffResponse();
            }
        } elseif (! empty($validated['staff'])) {
            $staffUser = User::where('name', $validated['staff'])->first();
        }
        $staffName = $staffUser?->name ?? ($validated['staff'] ?? '');

        $previous = null;
        if (! empty($validated['previous_consultation_id'])) {
            $previous = Consultation::find($validated['previous_consultation_id']);
            if ($previous && ! empty($validated['patient_id']) && $previous->patient_id !== $validated['patient_id']) {
                return response()->json([
                    'message' => 'The selected previous consultation belongs to a different patient.',
                    'errors' => ['previous_consultation_id' => ['The previous consultation does not match this patient.']],
                ], 422);
            }
        }

        if ($conflict = $this->conflictResponse(
            $validated['date'],
            $validated['time'],
            $staffUser?->id,
            $staffName,
            $validated['patient_id'] ?? null,
        )) {
            return $conflict;
        }

        // Locked reference generation inside a transaction so concurrent
        // bookings can never produce a duplicate reference.
        $appointment = DB::transaction(fn () => Appointment::create([
            ...$request->safe(['patient', 'patient_id', 'type', 'reason', 'date', 'time']),
            'visit_type' => Appointment::resolveVisitType($validated['visit_type'] ?? null, $validated['type'], $previous?->id),
            'previous_consultation_id' => $previous?->id,
            'staff' => $staffName,
            'staff_id' => $staffUser?->id,
            'reference' => Appointment::nextReference($validated['date'], true),
            'status' => 'Pending',
            'requested_on' => now()->toDateString(),
        ]));

        $this->notifyAdmins(
            'New Appointment Request',
            "{$validated['patient']} has requested a {$validated['type']} appointment for {$validated['date']} at {$validated['time']}.",
            $appointment,
        );

        return (new AppointmentResource($appointment->load(self::DETAIL_RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Assign (or unassign) the doctor/nurse for an appointment.
     *
     * The assignment is validated against the doctor's schedule and existing
     * bookings, and carried into any open consultation for the visit so the
     * queue, consultation and doctor schedule all show the same doctor.
     */
    public function assign(Appointment $appointment, AssignAppointmentStaffRequest $request): AppointmentResource|JsonResponse
    {
        if (! in_array($appointment->status, Appointment::ACTIVE_STATUSES, true)) {
            return response()->json([
                'message' => "A doctor cannot be assigned to an appointment in \"{$appointment->status}\" status.",
            ], 422);
        }

        $staffId = $request->validated('staff_id');
        $staffUser = null;

        if ($staffId) {
            $staffUser = $this->scheduler->clinicians()->find($staffId);
            if (! $staffUser) {
                return $this->invalidStaffResponse();
            }

            if ($conflict = $this->conflictResponse(
                $appointment->date->format('Y-m-d'),
                $appointment->time,
                $staffUser->id,
                $staffUser->name,
                null,
                $appointment->id,
            )) {
                return $conflict;
            }
        }

        DB::transaction(function () use ($appointment, $staffUser, $request) {
            $label = $staffUser?->name ?? 'Unassigned';
            $appointment->update([
                'staff_id' => $staffUser?->id,
                'staff' => $staffUser?->name ?? '',
                'notes' => $this->appendNote($appointment->notes, "Assigned to {$label} by {$request->user()->name}"),
            ]);

            $appointment->consultations()
                ->where('status', '!=', 'Completed')
                ->update(['staff_id' => $staffUser?->id, 'staff' => $staffUser?->name ?? '']);
        });

        if ($staffUser) {
            Notification::create([
                'user_id' => $staffUser->id,
                'title' => 'Patient Assigned',
                'message' => "You have been assigned to {$appointment->patient} ({$appointment->reference}) on {$appointment->date->format('Y-m-d')} at {$appointment->time}.",
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }

        return new AppointmentResource($appointment->fresh(self::DETAIL_RELATIONS));
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

        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
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

        if ($conflict = $this->conflictResponse(
            $date,
            $time,
            $appointment->staff_id,
            $appointment->staff,
            $appointment->patient_id,
            $appointment->id,
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
            'queue_number' => null,
            'checked_in_at' => null,
            'notes' => $this->appendNote($appointment->notes, $rescheduleNote),
        ]);

        $this->notifyReschedule($appointment, $date, $time);

        return new AppointmentResource($appointment->load(self::DETAIL_RELATIONS));
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

    private function conflictResponse(
        string $date,
        string $time,
        ?int $staffId,
        ?string $staffName,
        ?string $patientId,
        ?int $ignoreAppointmentId = null,
        bool $enforceDailyLimit = false,
    ): ?JsonResponse {
        $message = $this->scheduler->conflict($date, $time, $staffId, $staffName, $patientId, $ignoreAppointmentId, $enforceDailyLimit);

        return $message ? response()->json(['message' => $message], 422) : null;
    }

    private function invalidStaffResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The selected doctor/nurse is not an active clinician.',
            'errors' => ['staff_id' => ['Please select an active doctor or nurse.']],
        ], 422);
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

    private function notifyAdmins(string $title, string $message, Appointment $appointment): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => $title,
                'message' => $message,
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }
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

        $this->notifyPatient($appointment, "Appointment {$status}", $message);
        $this->notifyAdmins("Appointment {$status}", "Appointment {$appointment->reference} for {$appointment->patient} has been {$status}.", $appointment);
    }

    /**
     * Generate notification when appointment is rescheduled.
     */
    private function notifyReschedule(Appointment $appointment, string $newDate, string $newTime): void
    {
        $this->notifyPatient(
            $appointment,
            'Appointment Rescheduled',
            "Your appointment {$appointment->reference} ({$appointment->type}) has been rescheduled to {$newDate} at {$newTime}.",
        );
        $this->notifyAdmins(
            'Appointment Rescheduled',
            "Appointment {$appointment->reference} for {$appointment->patient} has been rescheduled to {$newDate} at {$newTime}.",
            $appointment,
        );
    }

    private function notifyPatient(Appointment $appointment, string $title, string $message): void
    {
        if (! $appointment->patient_id) {
            return;
        }

        $patientUser = User::where('patient_id', $appointment->patient_id)->first();
        if ($patientUser) {
            Notification::create([
                'user_id' => $patientUser->id,
                'title' => $title,
                'message' => $message,
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }
    }
}
