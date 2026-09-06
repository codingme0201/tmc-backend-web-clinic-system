<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteConsultationRequest;
use App\Http\Requests\StoreConsultationRequest;
use App\Http\Requests\UpdateConsultationRequest;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ConsultationController extends Controller
{
    /**
     * List consultations, optionally filtered by search/status.
     *
     * The frontend searches and filters client-side over this list, so the
     * API returns the full (filtered) collection; the query parameters mirror
     * the Appointments index and are available for a future server-side swap.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Consultation::query();

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

        return ConsultationResource::collection(
            $query->orderByDesc('date')->orderByDesc('time')->get(),
        );
    }

    /**
     * Show a single consultation.
     */
    public function show(Consultation $consultation): ConsultationResource
    {
        return new ConsultationResource($consultation);
    }

    /**
     * Log a new consultation.
     *
     * Accepts both the full consultation shape and the legacy shape the
     * Dashboard "Log Consultation" form sends (symptoms / vitals.bp / temp /
     * pulse), normalizing the latter exactly like the previous service did.
     */
    public function store(StoreConsultationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $today = now()->toDateString();
        $nowTime = now()->format('h:i A');

        $vitals = $validated['vitals'] ?? [];
        $vitals = [
            'temperature' => $vitals['temperature'] ?? $vitals['temp'] ?? '',
            'bloodPressure' => $vitals['bloodPressure'] ?? $vitals['bp'] ?? '',
            'pulseRate' => $vitals['pulseRate'] ?? $vitals['pulse'] ?? '',
            'respiratoryRate' => $vitals['respiratoryRate'] ?? '',
            'height' => $vitals['height'] ?? '',
            'weight' => $vitals['weight'] ?? '',
        ];

        $consultation = DB::transaction(function () use ($validated, $today, $nowTime, $vitals) {
            $staffName = $validated['staff'] ?? '';
            $staffId = $staffName ? User::where('name', $staffName)->value('id') : null;

            return Consultation::create([
                'reference' => Consultation::nextReference($today, true),
                'date' => $today,
                'time' => $nowTime,
                'patient' => $validated['patient'],
                'patient_id' => $validated['patient_id'] ?? null,
                'appointment_id' => $validated['appointment_id'] ?? null,
                'staff_id' => $staffId,
                'staff' => $validated['staff'] ?? '',
                'status' => 'Completed',
                'chief_complaint' => $validated['chiefComplaint'] ?? $validated['symptoms'] ?? '',
                'vitals' => $vitals,
                'clinical_findings' => $validated['clinicalFindings'] ?? '',
                'diagnosis' => $validated['diagnosis'] ?? '',
                'treatment' => $validated['treatment'] ?? '',
                'disposition' => $validated['disposition'] ?? '',
                'started_at' => $validated['startedAt'] ?? "$today $nowTime",
                'completed_at' => $validated['completedAt'] ?? "$today $nowTime",
            ]);
        });

        return (new ConsultationResource($consultation))->response()->setStatusCode(201);
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

        return new ConsultationResource($consultation);
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
            'staff' => $validated['staff'] ?? $consultation->staff,
            'chief_complaint' => $validated['chiefComplaint'] ?? $consultation->chief_complaint,
            'vitals' => $validated['vitals'] ?? $consultation->vitals,
            'clinical_findings' => $validated['clinicalFindings'] ?? $consultation->clinical_findings,
            'diagnosis' => $validated['diagnosis'] ?? $consultation->diagnosis,
            'treatment' => $validated['treatment'] ?? $consultation->treatment,
            'disposition' => $validated['disposition'] ?? $consultation->disposition,
        ]);

        return new ConsultationResource($consultation);
    }

    /**
     * Mark a consultation as Completed, persisting the final recorded data
     * (including chief complaint and staff). Completed records are locked.
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

        $consultation->update([
            'staff' => $validated['staff'] ?? $consultation->staff,
            'chief_complaint' => $validated['chiefComplaint'],
            'vitals' => $validated['vitals'] ?? $consultation->vitals,
            'clinical_findings' => $validated['clinicalFindings'] ?? $consultation->clinical_findings,
            'diagnosis' => $validated['diagnosis'],
            'treatment' => $validated['treatment'],
            'disposition' => $validated['disposition'] ?? $consultation->disposition,
            'status' => 'Completed',
            'completed_at' => now()->format('Y-m-d h:i A'),
        ]);

        return new ConsultationResource($consultation);
    }
}
