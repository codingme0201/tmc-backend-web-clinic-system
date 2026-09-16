<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePatientStatusRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Http\Resources\PatientResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PatientController extends Controller
{
    /**
     * Get the authenticated user's patient profile.
     */
    public function myProfile(Request $request): PatientResource
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $patient->loadCount(['appointments', 'consultations', 'medicalCertificates', 'prescriptions']);

        return new PatientResource($patient);
    }

    /**
     * Get the authenticated user's medical records.
     */
    public function myMedicalRecords(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        return $this->medicalInformation($patient);
    }

    /**
     * List patients with optional search.
     *
     * Search matches patient_id, name, type, or course_dept.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Patient::withCount(['appointments', 'consultations', 'medicalCertificates', 'prescriptions']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('patient_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('course_dept', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return PatientResource::collection($query->orderBy('name')->get());
    }

    /**
     * Show a single patient profile.
     */
    public function show(Patient $patient): PatientResource
    {
        $patient->loadCount(['appointments', 'consultations', 'medicalCertificates', 'prescriptions']);

        return new PatientResource($patient);
    }

    /**
     * Get the patient's medical information.
     *
     * Returns the medical record with all clinical child sections.
     */
    public function medicalInformation(Patient $patient): JsonResponse
    {
        $record = MedicalRecord::with(['histories', 'conditions', 'allergies', 'medications'])
            ->where('patient_id', $patient->patient_id)
            ->first();

        if (! $record) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => (new MedicalRecordResource($record))->resolve()]);
    }

    /**
     * Get the patient's aggregated record history.
     *
     * Combines appointments, consultations, medical records, medical
     * certificates, and prescriptions into a single chronological list.
     */
    public function recordHistory(Patient $patient): JsonResponse
    {
        $pid = $patient->patient_id;
        $records = collect();

        $appointments = Appointment::where('patient_id', $pid)
            ->orderByDesc('date')
            ->get(['id', 'reference', 'date', 'type', 'reason', 'status', 'time', 'staff'])
            ->map(fn ($a) => [
                'type' => 'Appointment',
                'reference' => $a->reference,
                'date' => $a->date->format('Y-m-d'),
                'title' => $a->type,
                'summary' => $a->reason,
                'status' => $a->status,
                'extra' => "{$a->time} · {$a->staff}",
                'sortDate' => $a->date->format('Y-m-d'),
            ]);
        $records = $records->concat($appointments);

        $consultations = Consultation::where('patient_id', $pid)
            ->orderByDesc('date')
            ->get(['id', 'reference', 'date', 'status', 'chief_complaint', 'diagnosis', 'staff'])
            ->map(fn ($c) => [
                'type' => 'Consultation',
                'reference' => $c->reference,
                'date' => $c->date->format('Y-m-d'),
                'title' => $c->chief_complaint ?: 'Consultation',
                'summary' => $c->diagnosis ?: 'No diagnosis recorded',
                'status' => $c->status,
                'extra' => $c->staff ?: '',
                'sortDate' => $c->date->format('Y-m-d'),
            ]);
        $records = $records->concat($consultations);

        $medicalRecords = MedicalRecord::where('patient_id', $pid)
            ->get(['id', 'patient_id', 'name', 'status', 'last_updated'])
            ->map(fn ($mr) => [
                'type' => 'Medical Record',
                'reference' => "MR-{$mr->id}",
                'date' => $mr->last_updated?->format('Y-m-d') ?? $mr->created_at->format('Y-m-d'),
                'title' => $mr->name,
                'summary' => 'Patient medical record',
                'status' => $mr->status,
                'extra' => '',
                'sortDate' => $mr->last_updated?->format('Y-m-d') ?? $mr->created_at->format('Y-m-d'),
            ]);
        $records = $records->concat($medicalRecords);

        $certificates = MedicalCertificate::where('patient_id', $pid)
            ->orderByDesc('issue_date')
            ->get(['id', 'reference', 'issue_date', 'purpose', 'status', 'issued_by'])
            ->map(fn ($mc) => [
                'type' => 'Medical Certificate',
                'reference' => $mc->reference,
                'date' => $mc->issue_date->format('Y-m-d'),
                'title' => $mc->purpose,
                'summary' => $mc->issued_by ? "Issued by {$mc->issued_by}" : '',
                'status' => $mc->status,
                'extra' => '',
                'sortDate' => $mc->issue_date->format('Y-m-d'),
            ]);
        $records = $records->concat($certificates);

        $prescriptions = Prescription::where('patient_id', $pid)
            ->orderByDesc('prescription_date')
            ->get(['id', 'reference', 'prescription_date', 'prescribed_by', 'patient'])
            ->map(fn ($p) => [
                'type' => 'Prescription',
                'reference' => $p->reference,
                'date' => $p->prescription_date->format('Y-m-d'),
                'title' => 'Prescription',
                'summary' => $p->prescribed_by ? "Prescribed by {$p->prescribed_by}" : '',
                'status' => 'Active',
                'extra' => '',
                'sortDate' => $p->prescription_date->format('Y-m-d'),
            ]);
        $records = $records->concat($prescriptions);

        $sorted = $records->sortByDesc('sortDate')->values()->all();

        return response()->json(['data' => $sorted]);
    }

    /**
     * Activate or deactivate a patient.
     */
    public function updateStatus(UpdatePatientStatusRequest $request, Patient $patient): PatientResource
    {
        $patient->update(['status' => $request->validated('status')]);

        $patient->loadCount(['appointments', 'consultations', 'medicalCertificates', 'prescriptions']);

        return new PatientResource($patient);
    }

    /**
     * Register a new patient.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'courseDept' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'emergencyContact' => ['nullable', 'string', 'max:255'],
            'allergies' => ['nullable', 'string', 'max:255'],
            'history' => ['nullable', 'string', 'max:255'],
        ]);

        $patient = Patient::create([
            'patient_id' => $validated['id'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'course_dept' => $validated['courseDept'] ?? '',
            'contact' => $validated['contact'] ?? '',
            'emergency_contact' => $validated['emergencyContact'] ?? '',
            'allergies' => $validated['allergies'] ?? 'None',
            'history' => $validated['history'] ?? 'None',
            'status' => 'Active',
        ]);

        return (new PatientResource($patient))->response()->setStatusCode(201);
    }
}
