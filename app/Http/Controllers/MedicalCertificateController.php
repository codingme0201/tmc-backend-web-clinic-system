<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewMedicalCertificateRequest;
use App\Http\Requests\StoreMedicalCertificateRequest;
use App\Http\Requests\UpdateMedicalCertificateRequest;
use App\Http\Resources\MedicalCertificateResource;
use App\Models\MedicalCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MedicalCertificateController extends Controller
{
    /**
     * List medical certificates, optionally filtered by search/status.
     *
     * The frontend searches/filters/paginates client-side over this list
     * (the established module convention); the query parameters mirror the
     * other module indexes and are available for a future server-side swap.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MedicalCertificate::query();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('patient', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhere('diagnosis', 'like', "%{$search}%")
                    ->orWhere('issued_by', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        // 'All' is the client-side sentinel for "no filter".
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return MedicalCertificateResource::collection(
            $query->orderByDesc('issue_date')->orderByDesc('id')->get(),
        );
    }

    /**
     * Show a single medical certificate.
     */
    public function show(MedicalCertificate $certificate): MedicalCertificateResource
    {
        return new MedicalCertificateResource($certificate);
    }

    /**
     * Submit a new medical certificate request.
     *
     * Reuses existing patient/consultation/medical-record data; the reference
     * is assigned sequentially for the issue date (MC-YYYY-NNN) inside the
     * transaction so concurrent requests cannot collide. The certificate is
     * created as a `Pending` request awaiting review and approval.
     */
    public function store(StoreMedicalCertificateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $issueDate = $validated['issue_date'] ?? now()->toDateString();

        $certificate = DB::transaction(function () use ($request, $validated, $issueDate) {
            $user = $request->user();

            return MedicalCertificate::create([
                'reference' => MedicalCertificate::nextReference($issueDate, true),
                'patient' => $validated['patient'],
                'patient_id' => $validated['patient_id'] ?? null,
                'consultation_id' => $validated['consultation_id'] ?? null,
                'medical_record_id' => $validated['medical_record_id'] ?? null,
                'issued_by_id' => null,
                'requested_by_id' => $user->id,
                'approved_by_id' => null,
                'rejected_by_id' => null,
                'issued_by' => $validated['issued_by'] ?? '',
                'requested_by' => $validated['requested_by'] ?? $user->name,
                'purpose' => $validated['purpose'],
                'diagnosis' => $validated['diagnosis'] ?? '',
                'recommendation' => $validated['recommendation'] ?? '',
                'issue_date' => $issueDate,
                'valid_until' => $validated['valid_until'] ?? null,
                'status' => 'Pending',
            ]);
        });

        return (new MedicalCertificateResource($certificate))->response()->setStatusCode(201);
    }

    /**
     * Approve a pending certificate request.
     *
     * Records who approved and when for the audit trail. Only requests in
     * `Pending` can be approved — the state machine lives in the model.
     */
    public function approve(MedicalCertificate $certificate, ReviewMedicalCertificateRequest $request): MedicalCertificateResource|JsonResponse
    {
        if (! $certificate->canTransitionTo('Approved')) {
            return response()->json([
                'message' => "Cannot approve a certificate in \"{$certificate->status}\" status.",
            ], 422);
        }

        $certificate->update([
            'status' => 'Approved',
            'approved_by_id' => $request->user()->id,
            'approved_by' => $request->user()->name,
            'approved_at' => now(),
        ]);

        return new MedicalCertificateResource($certificate);
    }

    /**
     * Reject a pending certificate request.
     *
     * The optional rejection reason is kept so the requester can understand
     * why the request was turned down. Only `Pending` requests can be rejected.
     */
    public function reject(MedicalCertificate $certificate, ReviewMedicalCertificateRequest $request): MedicalCertificateResource|JsonResponse
    {
        if (! $certificate->canTransitionTo('Rejected')) {
            return response()->json([
                'message' => "Cannot reject a certificate in \"{$certificate->status}\" status.",
            ], 422);
        }

        $certificate->update([
            'status' => 'Rejected',
            'rejected_by_id' => $request->user()->id,
            'rejected_by' => $request->user()->name,
            'rejected_at' => now(),
            'rejection_reason' => $request->validated('rejection_reason') ?? '',
        ]);

        return new MedicalCertificateResource($certificate);
    }

    /**
     * Issue an approved certificate.
     *
     * Finalizes the printable document: the issuing clinician and the printed
     * issue date can be adjusted at issue time and otherwise fall back to the
     * request data / approving user. Only `Approved` certificates can be issued.
     */
    public function issue(MedicalCertificate $certificate, ReviewMedicalCertificateRequest $request): MedicalCertificateResource|JsonResponse
    {
        if (! $certificate->canTransitionTo('Issued')) {
            return response()->json([
                'message' => "Cannot issue a certificate in \"{$certificate->status}\" status.",
            ], 422);
        }

        $issuedByName = $request->validated('issued_by') ?: ($certificate->issued_by ?: $request->user()->name);
        $issuedById = \App\Models\User::where('name', $issuedByName)->value('id');

        $certificate->update([
            'status' => 'Issued',
            'issue_date' => $request->validated('issue_date') ?? $certificate->issue_date->format('Y-m-d'),
            'issued_by_id' => $issuedById,
            'issued_by' => $issuedByName,
        ]);

        return new MedicalCertificateResource($certificate);
    }

    /**
     * Update certificate details (purpose, diagnosis, dates, status, etc.).
     */
    public function update(UpdateMedicalCertificateRequest $request, MedicalCertificate $certificate): MedicalCertificateResource
    {
        $validated = $request->validated();

        // Nullable fields use array_key_exists so an explicit `null` in the
        // payload actually CLEARS the stored value (e.g. removing a validity
        // window) instead of being treated as "not provided".
        foreach (['patient_id', 'consultation_id', 'medical_record_id', 'issued_by', 'diagnosis', 'recommendation', 'valid_until'] as $field) {
            if (array_key_exists($field, $validated)) {
                $certificate->{$field} = $validated[$field];
            }
        }

        // Fields that are never cleared keep the coalescing preserve.
        foreach (['patient', 'purpose', 'issue_date', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $certificate->{$field} = $validated[$field];
            }
        }

        $certificate->save();

        return new MedicalCertificateResource($certificate);
    }

    /**
     * Delete a medical certificate.
     */
    public function destroy(MedicalCertificate $certificate): JsonResponse
    {
        $certificate->delete();

        return response()->json(['message' => 'Medical certificate deleted.']);
    }
}
