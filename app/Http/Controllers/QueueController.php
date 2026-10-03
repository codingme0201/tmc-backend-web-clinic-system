<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConsultationResource;
use App\Http\Resources\QueueEntryResource;
use App\Models\Appointment;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily patient queue (first in, first out).
 *
 * Approved appointments join the waiting line when the patient checks in.
 * The line is served in FIFO order — earliest scheduled time slot first,
 * then check-in order, then booking order — per assigned doctor (patients
 * without an assigned doctor share one line). A patient cannot be called
 * ahead of someone earlier in the same line.
 */
class QueueController extends Controller
{
    public const WAITING = 'Waiting';

    public const IN_CONSULTATION = 'In Consultation';

    public const EXPECTED = 'Expected';

    public const AWAITING_APPROVAL = 'Awaiting Approval';

    public const SERVED = 'Served';

    /**
     * The queue for a date (today by default), with each entry's queue
     * status and FIFO position.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $validated['date'] ?? now()->toDateString();

        $entries = $this->entriesFor($date);

        return response()->json([
            'data' => QueueEntryResource::collection($entries)->resolve(),
            'meta' => [
                'date' => $date,
                'waiting' => $entries->where('queue_status', self::WAITING)->count(),
                'inConsultation' => $entries->where('queue_status', self::IN_CONSULTATION)->count(),
                'expected' => $entries->where('queue_status', self::EXPECTED)->count(),
                'awaitingApproval' => $entries->where('queue_status', self::AWAITING_APPROVAL)->count(),
                'served' => $entries->where('queue_status', self::SERVED)->count(),
            ],
        ]);
    }

    /**
     * Check a patient in: they join the end of the waiting line with the
     * next queue number for the day.
     */
    public function checkIn(Appointment $appointment): JsonResponse
    {
        if ($appointment->status !== 'Approved') {
            return response()->json([
                'message' => 'Only approved appointments can be checked in. Approve the appointment first.',
            ], 422);
        }

        if ($appointment->date->format('Y-m-d') !== now()->toDateString()) {
            return response()->json([
                'message' => 'Patients can only be checked in on the day of their appointment.',
            ], 422);
        }

        if ($appointment->checked_in_at) {
            return response()->json([
                'message' => "{$appointment->patient} is already checked in (queue #{$appointment->queue_number}).",
            ], 409);
        }

        DB::transaction(function () use ($appointment) {
            $next = (int) Appointment::whereDate('date', $appointment->date)->lockForUpdate()->max('queue_number') + 1;
            $appointment->update(['queue_number' => $next, 'checked_in_at' => now()]);
        });

        return $this->entryResponse($appointment);
    }

    /**
     * Undo a check-in made by mistake (only while the patient is waiting).
     */
    public function undoCheckIn(Appointment $appointment): JsonResponse
    {
        if (! $appointment->checked_in_at) {
            return response()->json(['message' => 'This patient is not checked in.'], 422);
        }

        if ($appointment->consultations()->exists()) {
            return response()->json([
                'message' => 'The consultation for this patient has already started.',
            ], 409);
        }

        $appointment->update(['queue_number' => null, 'checked_in_at' => null]);

        return $this->entryResponse($appointment);
    }

    /**
     * Call the patient in: start their consultation. Enforces FIFO within
     * the patient's line (same assigned doctor, or the shared unassigned
     * line). The consultation keeps the appointment's time slot, doctor and
     * visit type.
     */
    public function serve(Request $request, Appointment $appointment): JsonResponse
    {
        $date = $appointment->date->format('Y-m-d');
        $entries = $this->entriesFor($date);
        $entry = $entries->firstWhere('id', $appointment->id);

        if (! $entry || $entry->queue_status !== self::WAITING) {
            return response()->json([
                'message' => 'Only checked-in patients who are waiting can be called in.',
            ], 422);
        }

        $ahead = $entries
            ->where('queue_status', self::WAITING)
            ->filter(fn ($e) => $e->staff_id === $appointment->staff_id)
            ->first();

        if ($ahead && $ahead->id !== $appointment->id) {
            return response()->json([
                'message' => "First in, first out: {$ahead->patient} (queue #{$ahead->queue_number}, {$ahead->time}) is ahead in this line and must be served first.",
                'nextAppointmentId' => $ahead->id,
            ], 409);
        }

        $user = $request->user();

        $consultation = DB::transaction(function () use ($appointment, $user) {
            if (! $appointment->staff_id && $user->isClinician()) {
                $appointment->update(['staff_id' => $user->id, 'staff' => $user->name]);
            }

            return Consultation::create([
                'reference' => Consultation::nextReference($appointment->date->format('Y-m-d'), true),
                'date' => $appointment->date->format('Y-m-d'),
                'time' => $appointment->time,
                'patient' => $appointment->patient,
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'staff_id' => $appointment->staff_id,
                'staff' => $appointment->staff ?? '',
                'status' => 'In Progress',
                'visit_type' => $appointment->visit_type ?? Appointment::VISIT_NEW,
                'previous_consultation_id' => $appointment->previous_consultation_id,
                'chief_complaint' => $appointment->reason,
                'vitals' => [
                    'temperature' => '', 'bloodPressure' => '', 'pulseRate' => '',
                    'respiratoryRate' => '', 'height' => '', 'weight' => '',
                ],
                'started_at' => now()->format('Y-m-d h:i A'),
            ]);
        });

        return (new ConsultationResource($consultation->load(['previousConsultation', 'followUpAppointment'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Today's (or the given date's) queue entries with computed status and
     * position, in FIFO order.
     */
    private function entriesFor(string $date): Collection
    {
        $appointments = Appointment::with(['staffUser.role', 'consultations', 'previousConsultation'])
            ->whereDate('date', $date)
            ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'Completed'])
            ->get();

        $entries = Appointment::sortFifo($appointments)->map(function (Appointment $appointment) {
            $appointment->queue_status = $this->statusOf($appointment);

            return $appointment;
        });

        $waiting = Appointment::sortFifo($entries->where('queue_status', self::WAITING));
        $position = 0;
        $lanePositions = [];
        foreach ($waiting as $appointment) {
            $lane = $appointment->staff_id ?? 0;
            $lanePositions[$lane] = ($lanePositions[$lane] ?? 0) + 1;
            $appointment->queue_position = ++$position;
            $appointment->lane_position = $lanePositions[$lane];
        }

        // Group by queue status; each group keeps its FIFO order.
        return collect([self::IN_CONSULTATION, self::WAITING, self::EXPECTED, self::AWAITING_APPROVAL, self::SERVED])
            ->flatMap(fn (string $status) => $status === self::WAITING
                ? $waiting
                : $entries->where('queue_status', $status)->values())
            ->values();
    }

    private function statusOf(Appointment $appointment): string
    {
        $consultation = $appointment->consultations->sortByDesc('id')->first();

        return match (true) {
            $appointment->status === 'Completed', $consultation?->status === 'Completed' => self::SERVED,
            $consultation !== null => self::IN_CONSULTATION,
            $appointment->status !== 'Approved' => self::AWAITING_APPROVAL,
            $appointment->checked_in_at !== null => self::WAITING,
            default => self::EXPECTED,
        };
    }

    private function entryResponse(Appointment $appointment): JsonResponse
    {
        $entry = $this->entriesFor($appointment->date->format('Y-m-d'))->firstWhere('id', $appointment->id);

        return response()->json(['data' => (new QueueEntryResource($entry))->resolve()]);
    }
}
