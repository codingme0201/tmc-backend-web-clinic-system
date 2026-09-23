<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePatientStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\MedicalCertificateResource;
use App\Http\Resources\MedicalRecordResource;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
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
     * Update the authenticated user's patient profile.
     */
    public function updateMyProfile(Request $request): PatientResource
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            // Auto-create a linked patient record if one doesn't exist yet
            $patient = Patient::create([
                'patient_id' => 'STU-' . date('y') . '-' . str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT),
                'name' => $request->user()->name,
                'type' => 'Student',
                'course_dept' => 'General',
                'status' => 'Active',
            ]);
            $request->user()->update(['patient_id' => $patient->patient_id]);
        }

        $validated = $request->validate([
            'firstName' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'studentId' => ['nullable', 'string', 'max:50'],
            'student_id' => ['nullable', 'string', 'max:50'],
            'course' => ['nullable', 'string', 'max:150'],
            'courseDept' => ['nullable', 'string', 'max:150'],
            'course_dept' => ['nullable', 'string', 'max:150'],
            'block' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'emergencyContactName' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergencyContactPhone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergencyContact' => ['nullable', 'string', 'max:255'],
        ]);

        $firstName = $validated['firstName'] ?? $validated['first_name'] ?? $patient->first_name;
        $middleName = $validated['middleName'] ?? $validated['middle_name'] ?? $patient->middle_name;
        $lastName = $validated['lastName'] ?? $validated['last_name'] ?? $patient->last_name;
        $studentId = $validated['studentId'] ?? $validated['student_id'] ?? null;
        $course = $validated['course'] ?? $validated['courseDept'] ?? $validated['course_dept'] ?? $patient->course_dept;
        $phone = $validated['phone'] ?? $validated['contact'] ?? $patient->contact;
        $emergName = $validated['emergencyContactName'] ?? $validated['emergency_contact_name'] ?? $patient->emergency_contact_name;
        $emergPhone = $validated['emergencyContactPhone'] ?? $validated['emergency_contact_phone'] ?? $patient->emergency_contact_phone;

        $updateData = [];
        if ($firstName !== null) $updateData['first_name'] = $firstName;
        if ($middleName !== null) $updateData['middle_name'] = $middleName;
        if ($lastName !== null) $updateData['last_name'] = $lastName;
        if (array_key_exists('age', $validated)) $updateData['age'] = $validated['age'];
        if ($course !== null) $updateData['course_dept'] = $course;
        if (array_key_exists('block', $validated)) $updateData['block'] = $validated['block'];
        if (array_key_exists('address', $validated)) $updateData['address'] = $validated['address'];
        if (array_key_exists('nationality', $validated)) $updateData['nationality'] = $validated['nationality'];
        if ($phone !== null) $updateData['contact'] = $phone;
        if ($emergName !== null) $updateData['emergency_contact_name'] = $emergName;
        if ($emergPhone !== null) $updateData['emergency_contact_phone'] = $emergPhone;

        // Sync emergency contact string representation
        if ($emergName || $emergPhone) {
            $updateData['emergency_contact'] = trim(($emergName ?? '') . ($emergPhone ? " ({$emergPhone})" : ''));
        } elseif (array_key_exists('emergencyContact', $validated)) {
            $updateData['emergency_contact'] = $validated['emergencyContact'] ?? '';
        }

        // Sync composite name if first/last are provided
        if ($firstName && $lastName) {
            $fullName = trim($firstName . ($middleName ? " {$middleName} " : ' ') . $lastName);
            $updateData['name'] = $fullName;
            $request->user()->update(['name' => $fullName]);
        }

        // Update studentId if provided and unique
        if ($studentId && $studentId !== $patient->patient_id) {
            $exists = Patient::where('patient_id', $studentId)->where('id', '!=', $patient->id)->exists();
            if (! $exists) {
                $updateData['patient_id'] = $studentId;
                $request->user()->update(['patient_id' => $studentId]);
            }
        }

        if (! empty($updateData)) {
            $patient->update($updateData);
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
     * Get the authenticated user's aggregated chronological timeline.
     */
    public function myRecordHistory(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        return $this->recordHistory($patient);
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

    /**
     * Search patient's own records across appointments, certificates, prescriptions, and medical records.
     */
    public function search(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([
                'data' => [
                    'appointments' => [],
                    'certificates' => [],
                    'prescriptions' => [],
                    'medicalRecord' => null,
                ],
            ]);
        }

        $pid = $patient->patient_id;

        $appointments = Appointment::where('patient_id', $pid)
            ->where(function ($query) use ($q) {
                $query->where('reference', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%")
                    ->orWhere('reason', 'like', "%{$q}%")
                    ->orWhere('staff', 'like', "%{$q}%");
            })
            ->get();

        $certificates = MedicalCertificate::where('patient_id', $pid)
            ->where(function ($query) use ($q) {
                $query->where('reference', 'like', "%{$q}%")
                    ->orWhere('purpose', 'like', "%{$q}%")
                    ->orWhere('diagnosis', 'like', "%{$q}%");
            })
            ->get();

        $prescriptions = Prescription::where('patient_id', $pid)
            ->where(function ($query) use ($q) {
                $query->where('reference', 'like', "%{$q}%")
                    ->orWhere('prescribed_by', 'like', "%{$q}%")
                    ->orWhereHas('medications', fn ($m) => $m->where('medicine_name', 'like', "%{$q}%"));
            })
            ->with('medications')
            ->get();

        $medicalRecord = MedicalRecord::where('patient_id', $pid)
            ->with(['histories', 'conditions', 'allergies', 'medications'])
            ->first();

        return response()->json([
            'data' => [
                'appointments' => AppointmentResource::collection($appointments),
                'certificates' => MedicalCertificateResource::collection($certificates),
                'prescriptions' => PrescriptionResource::collection($prescriptions),
                'medicalRecord' => $medicalRecord ? (new MedicalRecordResource($medicalRecord))->resolve() : null,
            ],
        ]);
    }

    /**
     * Submit a support concern from the mobile application.
     */
    public function submitSupport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        // Notify admins about the support concern
        $admins = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => "Support Concern: {$validated['subject']}",
                'message' => "Patient {$user->name} ({$user->email}) submitted: {$validated['message']}",
                'type' => 'system',
                'category' => 'support',
                'source' => 'Support',
                'is_read' => false,
            ]);
        }

        return response()->json([
            'message' => 'Your support concern has been submitted successfully.',
        ], 201);
    }
}
