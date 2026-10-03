<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteConsultationRequest;
use App\Http\Requests\ScheduleFollowUpRequest;
use App\Http\Requests\StoreConsultationRequest;
use App\Http\Requests\UpdateConsultationRequest;
use App\Http\Resources\ConsultationResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ConsultationController extends Controller
{
    private const DETAIL_RELATIONS = ['previousConsultation', 'followUpAppointment'];

    public function __construct(private readonly AppointmentScheduler $scheduler)
    {
    }

    /**
     * List consultations, optionally filtered by search/status/visit type.
     *
     * The frontend searches and filters client-side over this list, so the
     * API returns the full (filtered) collection; the query parameters mirror
     * the Appointments index and are available for a future server-side swap.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Consultation::with(self::DETAIL_RELATIONS);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('patient', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('chief_complaint', 'like', "%{$search}%")
                    ->orWhere('diagnosis', 'like', "%{$search}%")
                    ->orWhere('staff', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        // 'All' is the client-side sentinel for "no filter".
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        $visitType = $request->query('visit_type');
        if ($visitType && $visitType !== 'All') {
            $query->where('visit_type', $visitType);
        }

        if ($patientId = $request->query('patient_id')) {
            $query->where('patient_id', $patientId);
        }

        return ConsultationResource::collection($this->newestFirst($query->get()));
    }

    /**
     * Show a single consultation.
     */
    public function show(Consultation $consultation): ConsultationResource
    {
        return new ConsultationResource($consultation->load(self::DETAIL_RELATIONS));
    }

    /**
     * List consultations for the authenticated patient.
     */
    public function myConsultations(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $consultations = Consultation::with(self::DETAIL_RELATIONS)
            ->where('patient_id', $patient->patient_id)
            ->get();

        return ConsultationResource::collection($this->newestFirst($consultations));
    }

    /**
     * Show a specific consultation for the authenticated patient.
     */
    public function myConsultationShow(Consultation $consultation, Request $request): ConsultationResource|JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient || $consultation->patient_id !== $patient->patient_id) {
            abort(403, 'You do not have permission to view this consultation.');
        }

        return new ConsultationResource($consultation->load(self::DETAIL_RELATIONS));
    }

    /**
     * Log a new consultation.
     *
     * Accepts both the full consultation shape and the legacy shape the
     * Dashboard "Log Consultation" form sends (symptoms / vitals.bp / temp /
     * pulse), normalizing the latter exactly like the previous service did.
     * The time is always one of the shared clinic slots; a consultation
     * started from an appointment keeps the appointment's slot and doctor.
     */
    public function store(StoreConsultationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $appointment = ! empty($validated['appointment_id']) ? Appointment::find($validated['appointment_id']) : null;

        $today = $validated['date'] ?? $appointment?->date?->format('Y-m-d') ?? now()->toDateString();
        $nowTime = $validated['time'] ?? $appointment?->time ?? ClinicSchedule::currentSlot();

        $vitals = $validated['vitals'] ?? [];
        $vitals = [
            'temperature' => $vitals['temperature'] ?? $vitals['temp'] ?? '',
            'bloodPressure' => $vitals['bloodPressure'] ?? $vitals['bp'] ?? '',
            'pulseRate' => $vitals['pulseRate'] ?? $vitals['pulse'] ?? '',
            'respiratoryRate' => $vitals['respiratoryRate'] ?? '',
            'height' => $vitals['height'] ?? '',
            'weight' => $vitals['weight'] ?? '',
        ];

        // Status defaults to 'Scheduled' unless explicitly specified or already has diagnosis
        $status = $validated['status'] ?? (! empty($validated['diagnosis']) ? 'Completed' : 'Scheduled');

        $staffUser = $this->resolveStaff($validated['staff_id'] ?? null, $validated['staff'] ?? null)
            ?? $appointment?->staffUser;
        $staffName = $staffUser?->name ?? ($validated['staff'] ?? ($appointment?->staff ?? ''));

        $previousId = $validated['previous_consultation_id'] ?? $appointment?->previous_consultation_id;
        $visitType = $validated['visit_type']
            ?? $appointment?->visit_type
            ?? ($previousId ? Appointment::VISIT_FOLLOW_UP : Appointment::VISIT_NEW);

        $consultation = DB::transaction(function () use ($validated, $today, $nowTime, $vitals, $status, $staffUser, $staffName, $appointment, $previousId, $visitType) {
            $patientId = $validated['patient_id'] ?? $appointment?->patient_id ?? Patient::where('name', $validated['patient'])->value('patient_id');
            $stamp = "$today $nowTime";

            return Consultation::create([
                'reference' => Consultation::nextReference($today, true),
                'date' => $today,
                'time' => $nowTime,
                'patient' => $validated['patient'],
                'patient_id' => $patientId,
                'appointment_id' => $appointment?->id,
                'staff_id' => $staffUser?->id,
                'staff' => $staffName,
                'status' => $status,
                'visit_type' => $visitType,
                'previous_consultation_id' => $previousId,
                'chief_complaint' => $validated['chiefComplaint'] ?? $validated['symptoms'] ?? $appointment?->reason ?? '',
                'vitals' => $vitals,
                'clinical_findings' => $validated['clinicalFindings'] ?? '',
                'diagnosis' => $validated['diagnosis'] ?? '',
                'treatment' => $validated['treatment'] ?? '',
                'disposition' => $validated['disposition'] ?? '',
                'started_at' => $validated['startedAt'] ?? (in_array($status, ['In Progress', 'Completed'], true) ? $stamp : null),
                'completed_at' => $validated['completedAt'] ?? ($status === 'Completed' ? $stamp : null),
            ]);
        });

        return (new ConsultationResource($consultation->load(self::DETAIL_RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Move a Scheduled consultation into In Progress.
     */
    public function start(Consultation $consultation): ConsultationResource|JsonResponse
    {
        if ($consultation->status !== 'Scheduled') {
            return response()->json([
                'message' => "Only scheduled consultations can be started (current status: {$consultation->status}).",
            ], 409);
        }

        $consultation->update([
            'status' => 'In Progress',
            'started_at' => now()->format('Y-m-d h:i A'),
        ]);

        return new ConsultationResource($consultation->load(self::DETAIL_RELATIONS));
    }

    /**
     * Persist draft consultation information (chief complaint, vitals, staff,
     * etc.). Completed consultations are read-only.
     */
    public function update(UpdateConsultationRequest $request, Consultation $consultation): ConsultationResource|JsonResponse
    {
        if ($consultation->status === 'Completed') {
            return response()->json([
                'message' => 'Completed consultations are read-only.',
            ], 409);
        }

        $validated = $request->validated();

        $consultation->update([
            ...$this->staffAttributes($consultation, $validated),
            'chief_complaint' => $validated['chiefComplaint'] ?? $consultation->chief_complaint,
            'vitals' => $validated['vitals'] ?? $consultation->vitals,
            'clinical_findings' => $validated['clinicalFindings'] ?? $consultation->clinical_findings,
            'diagnosis' => $validated['diagnosis'] ?? $consultation->diagnosis,
            'treatment' => $validated['treatment'] ?? $consultation->treatment,
            'disposition' => $validated['disposition'] ?? $consultation->disposition,
            'follow_up_required' => $validated['followUpRequired'] ?? $consultation->follow_up_required,
            'follow_up_notes' => $validated['followUpNotes'] ?? $consultation->follow_up_notes,
        ]);

        return new ConsultationResource($consultation->load(self::DETAIL_RELATIONS));
    }

    /**
     * Mark a consultation as Completed, persisting the final recorded data
     * (including chief complaint and staff). Completed records are locked.
     *
     * When the doctor marks a follow-up as required and picks a date and
     * time slot, the follow-up appointment is booked in the same step. The
     * appointment the consultation came from is marked Completed.
     */
    public function complete(CompleteConsultationRequest $request, Consultation $consultation): ConsultationResource|JsonResponse
    {
        if ($consultation->status === 'Completed') {
            return response()->json([
                'message' => 'This consultation is already completed.',
            ], 409);
        }

        if ($consultation->status !== 'In Progress') {
            return response()->json([
                'message' => "Only in-progress consultations can be completed (current status: {$consultation->status}).",
            ], 409);
        }

        $validated = $request->validated();
        $followUpRequired = (bool) ($validated['followUpRequired'] ?? false);
        $staffAttributes = $this->staffAttributes($consultation, $validated);

        if ($followUpRequired && ! empty($validated['followUpDate']) && ! empty($validated['followUpTime'])) {
            $message = $this->scheduler->conflict(
                $validated['followUpDate'],
                $validated['followUpTime'],
                $staffAttributes['staff_id'],
                $staffAttributes['staff'],
                $consultation->patient_id,
            );
            if ($message) {
                return response()->json([
                    'message' => "Follow-up could not be scheduled: {$message}",
                    'errors' => ['followUpTime' => [$message]],
                ], 422);
            }
        }

        DB::transaction(function () use ($consultation, $validated, $followUpRequired, $staffAttributes, $request) {
            $consultation->update([
                ...$staffAttributes,
                'chief_complaint' => $validated['chiefComplaint'],
                'vitals' => $validated['vitals'] ?? $consultation->vitals,
                'clinical_findings' => $validated['clinicalFindings'] ?? $consultation->clinical_findings,
                'diagnosis' => $validated['diagnosis'],
                'treatment' => $validated['treatment'],
                'disposition' => $validated['disposition'] ?? $consultation->disposition,
                'follow_up_required' => $followUpRequired,
                'follow_up_date' => $followUpRequired ? ($validated['followUpDate'] ?? null) : null,
                'follow_up_notes' => $followUpRequired ? ($validated['followUpNotes'] ?? null) : null,
                'status' => 'Completed',
                'completed_at' => now()->format('Y-m-d h:i A'),
            ]);

            if ($followUpRequired && ! empty($validated['followUpDate']) && ! empty($validated['followUpTime'])) {
                $this->bookFollowUp($consultation, $validated['followUpDate'], $validated['followUpTime'], $request->user());
            }

            $this->completeLinkedAppointment($consultation);
            $this->recordInMedicalHistory($consultation);
        });

        return new ConsultationResource($consultation->fresh(self::DETAIL_RELATIONS));
    }

    /**
     * Schedule the follow-up appointment for a completed consultation (when
     * the date was not picked at completion time, or to add one later).
     */
    public function scheduleFollowUp(ScheduleFollowUpRequest $request, Consultation $consultation): ConsultationResource|JsonResponse
    {
        if ($consultation->status !== 'Completed') {
            return response()->json([
                'message' => 'A follow-up can only be scheduled after the consultation is completed.',
            ], 409);
        }

        $existing = $consultation->followUpAppointment;
        if ($existing && in_array($existing->status, Appointment::ACTIVE_STATUSES, true)) {
            return response()->json([
                'message' => "A follow-up is already scheduled ({$existing->reference} on {$existing->date->format('Y-m-d')} at {$existing->time}).",
            ], 409);
        }

        $validated = $request->validated();
        $staffUser = $this->resolveStaff($validated['staff_id'] ?? null, null) ?? $consultation->staffUser;

        if ($message = $this->scheduler->conflict(
            $validated['date'],
            $validated['time'],
            $staffUser?->id,
            $staffUser?->name ?? $consultation->staff,
            $consultation->patient_id,
        )) {
            return response()->json(['message' => $message, 'errors' => ['time' => [$message]]], 422);
        }

        DB::transaction(function () use ($consultation, $validated, $staffUser, $request) {
            $consultation->update([
                'follow_up_required' => true,
                'follow_up_date' => $validated['date'],
                'follow_up_notes' => $validated['notes'] ?? $consultation->follow_up_notes,
            ]);
            $this->bookFollowUp($consultation, $validated['date'], $validated['time'], $request->user(), $staffUser);
        });

        return new ConsultationResource($consultation->fresh(self::DETAIL_RELATIONS));
    }

    /**
     * Book the follow-up visit: an approved appointment linked back to this
     * consultation, with the same doctor, so the doctor sees why the
     * patient is returning.
     */
    private function bookFollowUp(Consultation $consultation, string $date, string $time, User $bookedBy, ?User $staffUser = null): Appointment
    {
        $staffId = $staffUser?->id ?? $consultation->staff_id;
        $staffName = $staffUser?->name ?? $consultation->staff;
        $originalType = $consultation->appointment?->type;
        $reasonParts = array_filter([
            $consultation->diagnosis ? "Follow-up for {$consultation->diagnosis}" : 'Follow-up consultation',
            $consultation->follow_up_notes,
        ]);

        $appointment = Appointment::create([
            'reference' => Appointment::nextReference($date, true),
            'patient' => $consultation->patient,
            'patient_id' => $consultation->patient_id,
            'type' => $originalType && $originalType !== 'Follow-up' ? $originalType : 'Check-up',
            'visit_type' => Appointment::VISIT_FOLLOW_UP,
            'previous_consultation_id' => $consultation->id,
            'reason' => implode(' — ', $reasonParts),
            'date' => $date,
            'time' => $time,
            'staff_id' => $staffId,
            'staff' => $staffName ?? '',
            'status' => 'Approved',
            'notes' => "Follow-up scheduled by {$bookedBy->name} from {$consultation->reference}",
            'requested_on' => now()->toDateString(),
        ]);

        $consultation->update(['follow_up_appointment_id' => $appointment->id, 'follow_up_date' => $date]);

        $patientUser = $consultation->patient_id ? User::where('patient_id', $consultation->patient_id)->first() : null;
        if ($patientUser) {
            Notification::create([
                'user_id' => $patientUser->id,
                'title' => 'Follow-up Appointment Scheduled',
                'message' => "Your follow-up visit ({$appointment->reference}) is scheduled on {$date} at {$time}".($staffName ? " with {$staffName}." : '.'),
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Consultations',
                'metadata' => ['appointment_reference' => $appointment->reference],
            ]);
        }

        return $appointment;
    }

    /**
     * The appointment this consultation came from is finished once the
     * consultation is completed.
     */
    private function completeLinkedAppointment(Consultation $consultation): void
    {
        $appointment = $consultation->appointment;
        if ($appointment && $appointment->canTransitionTo('Completed')) {
            $appointment->update(['status' => 'Completed']);
        }
    }

    /**
     * Staff columns for an update: prefer the user ID, fall back to a name
     * (older clients), and keep the current staff otherwise.
     *
     * @return array{staff_id: ?int, staff: ?string}
     */
    private function staffAttributes(Consultation $consultation, array $validated): array
    {
        if (! array_key_exists('staff_id', $validated) && ! array_key_exists('staff', $validated)) {
            return ['staff_id' => $consultation->staff_id, 'staff' => $consultation->staff];
        }

        $user = $this->resolveStaff($validated['staff_id'] ?? null, $validated['staff'] ?? null);

        if ($user) {
            return ['staff_id' => $user->id, 'staff' => $user->name];
        }

        if (! empty($validated['staff'])) {
            return ['staff_id' => null, 'staff' => $validated['staff']];
        }

        return ['staff_id' => $consultation->staff_id, 'staff' => $consultation->staff];
    }

    private function resolveStaff(?int $staffId, ?string $staffName): ?User
    {
        if ($staffId) {
            return User::find($staffId);
        }

        return $staffName ? User::where('name', $staffName)->first() : null;
    }

    /**
     * Newest first; times are compared as minutes so PM slots sort after AM.
     */
    private function newestFirst($consultations)
    {
        return $consultations->sort(fn (Consultation $a, Consultation $b) => [
            $b->date?->format('Y-m-d'),
            ClinicSchedule::toMinutes($b->time) ?? -1,
            $b->id,
        ] <=> [
            $a->date?->format('Y-m-d'),
            ClinicSchedule::toMinutes($a->time) ?? -1,
            $a->id,
        ])->values();
    }

    /**
     * Add the completed consultation to the patient's medical record history
     * (creating the record if needed), so the record reflects clinic visits.
     */
    private function recordInMedicalHistory(Consultation $consultation): void
    {
        if (! $consultation->patient_id) {
            return;
        }

        $patient = Patient::where('patient_id', $consultation->patient_id)->first();
        if (! $patient) {
            return;
        }

        $record = $patient->ensureMedicalRecord();
        $notes = collect([
            $consultation->reference ? "Consultation {$consultation->reference}" : null,
            $consultation->isFollowUp() && $consultation->previousConsultation
                ? "Follow-up of {$consultation->previousConsultation->reference}" : null,
            $consultation->chief_complaint ? "Complaint: {$consultation->chief_complaint}" : null,
            $consultation->treatment ? "Treatment: {$consultation->treatment}" : null,
            $consultation->follow_up_required
                ? 'Follow-up required'.($consultation->follow_up_date ? " on {$consultation->follow_up_date->format('Y-m-d')}" : '') : null,
            $consultation->staff ? "Attended by {$consultation->staff}" : null,
        ])->filter()->implode("\n");

        $record->histories()->create([
            'date' => $consultation->date?->format('Y-m-d') ?? now()->toDateString(),
            'condition' => $consultation->diagnosis ?: ($consultation->chief_complaint ?: 'Clinic consultation'),
            'notes' => $notes,
        ]);
        $record->update(['last_updated' => now()->toDateString()]);
    }
}
