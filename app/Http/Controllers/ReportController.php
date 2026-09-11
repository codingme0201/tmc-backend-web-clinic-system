<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    /**
     * Generate appointment report with filters.
     */
    public function appointments(ReportFilterRequest $request): JsonResponse
    {
        $query = Appointment::query();

        $this->applyDateFilter($query, $request);
        $this->applyStatusFilter($query, $request);
        $this->applyPatientFilter($query, $request);
        $this->applyStaffFilter($query, $request);
        $this->applyTypeFilter($query, $request);

        $appointments = $query->orderBy('date', 'desc')->orderBy('time', 'desc')->get();

        return response()->json([
            'data' => $appointments->map(fn ($a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'patient' => $a->patient,
                'patientId' => $a->patient_id,
                'staff' => $a->staff ?: 'Unassigned',
                'type' => $a->type,
                'reason' => $a->reason,
                'date' => $a->date->format('Y-m-d'),
                'time' => $a->time,
                'status' => $a->status,
                'requestedOn' => $a->requested_on?->format('Y-m-d'),
            ]),
            'meta' => [
                'total' => $appointments->count(),
                'filters' => $this->activeFilters($request),
            ],
        ]);
    }

    /**
     * Generate consultation report with filters.
     */
    public function consultations(ReportFilterRequest $request): JsonResponse
    {
        $query = Consultation::query();

        $this->applyDateFilter($query, $request);
        $this->applyStatusFilter($query, $request);
        $this->applyPatientFilter($query, $request);
        $this->applyStaffFilter($query, $request);

        $consultations = $query->orderBy('date', 'desc')->orderBy('time', 'desc')->get();

        return response()->json([
            'data' => $consultations->map(fn ($c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'patient' => $c->patient,
                'patientId' => $c->patient_id,
                'staff' => $c->staff ?: 'Unassigned',
                'date' => $c->date->format('Y-m-d'),
                'time' => $c->time,
                'status' => $c->status,
                'chiefComplaint' => $c->chief_complaint,
                'diagnosis' => $c->diagnosis,
                'treatment' => $c->treatment,
                'vitals' => $c->vitals,
                'clinicalFindings' => $c->clinical_findings,
                'disposition' => $c->disposition,
                'startedAt' => $c->started_at?->toIso8601String(),
                'completedAt' => $c->completed_at?->toIso8601String(),
            ]),
            'meta' => [
                'total' => $consultations->count(),
                'filters' => $this->activeFilters($request),
            ],
        ]);
    }

    /**
     * Generate patient report with filters.
     */
    public function patients(ReportFilterRequest $request): JsonResponse
    {
        $query = Patient::query();

        if ($search = trim((string) $request->query('patient', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('patient_id', 'like', "%{$search}%")
                    ->orWhere('course_dept', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            if ($status !== 'All') {
                $query->where('status', $status);
            }
        }

        if ($type = $request->query('type')) {
            if ($type !== 'All') {
                $query->where('type', $type);
            }
        }

        $patients = $query->orderBy('name')->get();

        $typeBreakdown = $patients->groupBy('type')->map(fn ($group) => $group->count());
        $statusBreakdown = $patients->groupBy('status')->map(fn ($group) => $group->count());

        return response()->json([
            'data' => $patients->map(fn ($p) => [
                'id' => $p->id,
                'patientId' => $p->patient_id,
                'name' => $p->name,
                'type' => $p->type,
                'courseDept' => $p->course_dept,
                'contact' => $p->contact,
                'status' => $p->status,
                'appointmentsCount' => $p->appointments()->count(),
                'consultationsCount' => $p->consultations()->count(),
                'medicalCertificatesCount' => $p->medicalCertificates()->count(),
                'prescriptionsCount' => $p->prescriptions()->count(),
            ]),
            'meta' => [
                'total' => $patients->count(),
                'typeBreakdown' => $typeBreakdown,
                'statusBreakdown' => $statusBreakdown,
                'filters' => $this->activeFilters($request),
            ],
        ]);
    }

    /**
     * Generate medical certificate report with filters.
     */
    public function medicalCertificates(ReportFilterRequest $request): JsonResponse
    {
        $query = MedicalCertificate::query();

        if ($startDate = $request->query('start_date')) {
            $query->where('issue_date', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->where('issue_date', '<=', $endDate);
        }

        $this->applyStatusFilter($query, $request);
        $this->applyPatientFilter($query, $request);

        if ($staff = trim((string) $request->query('staff', ''))) {
            $query->where(function ($q) use ($staff) {
                $q->where('issued_by', 'like', "%{$staff}%")
                    ->orWhere('requested_by', 'like', "%{$staff}%");
            });
        }

        $certificates = $query->orderBy('issue_date', 'desc')->get();

        $statusBreakdown = $certificates->groupBy('status')->map(fn ($group) => $group->count());

        return response()->json([
            'data' => $certificates->map(fn ($mc) => [
                'id' => $mc->id,
                'reference' => $mc->reference,
                'patient' => $mc->patient,
                'patientId' => $mc->patient_id,
                'purpose' => $mc->purpose,
                'diagnosis' => $mc->diagnosis,
                'recommendation' => $mc->recommendation,
                'issuedBy' => $mc->issued_by,
                'requestedBy' => $mc->requested_by,
                'approvedBy' => $mc->approved_by,
                'issueDate' => $mc->issue_date instanceof Carbon ? $mc->issue_date->format('Y-m-d') : $mc->issue_date,
                'validUntil' => $mc->valid_until instanceof Carbon ? $mc->valid_until->format('Y-m-d') : $mc->valid_until,
                'status' => $mc->status,
                'approvedAt' => $mc->approved_at?->toIso8601String(),
                'rejectedAt' => $mc->rejected_at?->toIso8601String(),
                'rejectionReason' => $mc->rejection_reason,
            ]),
            'meta' => [
                'total' => $certificates->count(),
                'statusBreakdown' => $statusBreakdown,
                'filters' => $this->activeFilters($request),
            ],
        ]);
    }

    /**
     * Generate prescription report with filters.
     */
    public function prescriptions(ReportFilterRequest $request): JsonResponse
    {
        $query = Prescription::with('medications');

        if ($startDate = $request->query('start_date')) {
            $query->where('prescription_date', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->where('prescription_date', '<=', $endDate);
        }

        $this->applyPatientFilter($query, $request);

        if ($staff = trim((string) $request->query('staff', ''))) {
            $query->where('prescribed_by', 'like', "%{$staff}%");
        }

        $prescriptions = $query->orderBy('prescription_date', 'desc')->get();

        return response()->json([
            'data' => $prescriptions->map(fn ($rx) => [
                'id' => $rx->id,
                'reference' => $rx->reference,
                'patient' => $rx->patient,
                'patientId' => $rx->patient_id,
                'prescribedBy' => $rx->prescribed_by,
                'prescriptionDate' => $rx->prescription_date instanceof Carbon ? $rx->prescription_date->format('Y-m-d') : $rx->prescription_date,
                'medications' => $rx->medications->map(fn ($m) => [
                    'medicineName' => $m->medicine_name,
                    'dosage' => $m->dosage,
                    'frequency' => $m->frequency,
                    'duration' => $m->duration,
                    'instructions' => $m->instructions,
                ]),
            ]),
            'meta' => [
                'total' => $prescriptions->count(),
                'totalMedications' => $prescriptions->sum(fn ($rx) => $rx->medications->count()),
                'filters' => $this->activeFilters($request),
            ],
        ]);
    }

    /**
     * Get clinic statistics for a date range.
     */
    public function statistics(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $appointmentQuery = Appointment::query();
        $consultationQuery = Consultation::query();

        if ($startDate) {
            $appointmentQuery->where('date', '>=', $startDate);
            $consultationQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $appointmentQuery->where('date', '<=', $endDate);
            $consultationQuery->where('date', '<=', $endDate);
        }

        $totalAppointments = (clone $appointmentQuery)->count();
        $appointmentStatusCounts = (clone $appointmentQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalConsultations = (clone $consultationQuery)->count();
        $completedConsultations = (clone $consultationQuery)->where('status', 'Completed')->count();
        $consultationStatusCounts = (clone $consultationQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalPatients = Patient::count();
        $activePatients = Patient::where('status', 'Active')->count();

        $totalMedicalCertificates = MedicalCertificate::count();
        $issuedCertificates = MedicalCertificate::where('status', 'Issued')->count();

        $totalPrescriptions = Prescription::count();

        $appointmentTypeCounts = (clone $appointmentQuery)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        return response()->json([
            'data' => [
                'appointments' => [
                    'total' => $totalAppointments,
                    'byStatus' => $appointmentStatusCounts,
                    'byType' => $appointmentTypeCounts,
                ],
                'consultations' => [
                    'total' => $totalConsultations,
                    'completed' => $completedConsultations,
                    'byStatus' => $consultationStatusCounts,
                ],
                'patients' => [
                    'total' => $totalPatients,
                    'active' => $activePatients,
                ],
                'medicalCertificates' => [
                    'total' => $totalMedicalCertificates,
                    'issued' => $issuedCertificates,
                ],
                'prescriptions' => [
                    'total' => $totalPrescriptions,
                ],
                'dateRange' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
            ],
        ]);
    }

    /**
     * Export report data as CSV.
     */
    public function export(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'patient' => ['nullable', 'string'],
            'staff' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);

        $data = match ($type) {
            'appointments' => $this->getExportData('appointments', $validated),
            'consultations' => $this->getExportData('consultations', $validated),
            'patients' => $this->getExportData('patients', $validated),
            'medical-certificates' => $this->getExportData('medical-certificates', $validated),
            'prescriptions' => $this->getExportData('prescriptions', $validated),
            default => null,
        };

        if ($data === null) {
            return response()->json(['message' => 'Invalid report type.'], 422);
        }

        return response()->json([
            'data' => $data['rows'],
            'columns' => $data['columns'],
            'type' => $type,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private function applyDateFilter($query, ReportFilterRequest $request): void
    {
        if ($startDate = $request->query('start_date')) {
            $query->where('date', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->where('date', '<=', $endDate);
        }
    }

    private function applyStatusFilter($query, ReportFilterRequest $request): void
    {
        if ($status = $request->query('status')) {
            if ($status !== 'All') {
                $query->where('status', $status);
            }
        }
    }

    private function applyPatientFilter($query, ReportFilterRequest $request): void
    {
        if ($patient = trim((string) $request->query('patient', ''))) {
            $query->where('patient', 'like', "%{$patient}%");
        }
    }

    private function applyStaffFilter($query, ReportFilterRequest $request): void
    {
        if ($staff = trim((string) $request->query('staff', ''))) {
            $query->where('staff', 'like', "%{$staff}%");
        }
    }

    private function applyTypeFilter($query, ReportFilterRequest $request): void
    {
        if ($type = $request->query('type')) {
            if ($type !== 'All') {
                $query->where('type', $type);
            }
        }
    }

    private function activeFilters(ReportFilterRequest $request): array
    {
        $filters = [];
        if ($request->query('start_date')) $filters['start_date'] = $request->query('start_date');
        if ($request->query('end_date')) $filters['end_date'] = $request->query('end_date');
        if ($request->query('status')) $filters['status'] = $request->query('status');
        if ($request->query('patient')) $filters['patient'] = $request->query('patient');
        if ($request->query('staff')) $filters['staff'] = $request->query('staff');
        if ($request->query('type')) $filters['type'] = $request->query('type');
        return $filters;
    }

    private function getExportData(string $type, array $filters): ?array
    {
        return match ($type) {
            'appointments' => $this->exportAppointments($filters),
            'consultations' => $this->exportConsultations($filters),
            'patients' => $this->exportPatients($filters),
            'medical-certificates' => $this->exportMedicalCertificates($filters),
            'prescriptions' => $this->exportPrescriptions($filters),
            default => null,
        };
    }

    private function exportAppointments(array $filters): array
    {
        $query = Appointment::query();
        if (! empty($filters['start_date'])) $query->where('date', '>=', $filters['start_date']);
        if (! empty($filters['end_date'])) $query->where('date', '<=', $filters['end_date']);
        if (! empty($filters['status']) && $filters['status'] !== 'All') $query->where('status', $filters['status']);
        if (! empty($filters['patient'])) $query->where('patient', 'like', "%{$filters['patient']}%");
        if (! empty($filters['staff'])) $query->where('staff', 'like', "%{$filters['staff']}%");
        if (! empty($filters['type']) && $filters['type'] !== 'All') $query->where('type', $filters['type']);

        $rows = $query->orderBy('date', 'desc')->get()->map(fn ($a) => [
            'Reference' => $a->reference,
            'Patient' => $a->patient,
            'Staff' => $a->staff ?: 'Unassigned',
            'Type' => $a->type,
            'Reason' => $a->reason,
            'Date' => $a->date->format('Y-m-d'),
            'Time' => $a->time,
            'Status' => $a->status,
        ]);

        return [
            'columns' => ['Reference', 'Patient', 'Staff', 'Type', 'Reason', 'Date', 'Time', 'Status'],
            'rows' => $rows,
        ];
    }

    private function exportConsultations(array $filters): array
    {
        $query = Consultation::query();
        if (! empty($filters['start_date'])) $query->where('date', '>=', $filters['start_date']);
        if (! empty($filters['end_date'])) $query->where('date', '<=', $filters['end_date']);
        if (! empty($filters['status']) && $filters['status'] !== 'All') $query->where('status', $filters['status']);
        if (! empty($filters['patient'])) $query->where('patient', 'like', "%{$filters['patient']}%");
        if (! empty($filters['staff'])) $query->where('staff', 'like', "%{$filters['staff']}%");

        $rows = $query->orderBy('date', 'desc')->get()->map(fn ($c) => [
            'Reference' => $c->reference,
            'Patient' => $c->patient,
            'Staff' => $c->staff ?: 'Unassigned',
            'Date' => $c->date->format('Y-m-d'),
            'Time' => $c->time,
            'Status' => $c->status,
            'Chief Complaint' => $c->chief_complaint,
            'Diagnosis' => $c->diagnosis,
            'Treatment' => $c->treatment,
        ]);

        return [
            'columns' => ['Reference', 'Patient', 'Staff', 'Date', 'Time', 'Status', 'Chief Complaint', 'Diagnosis', 'Treatment'],
            'rows' => $rows,
        ];
    }

    private function exportPatients(array $filters): array
    {
        $query = Patient::query();
        if (! empty($filters['patient'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['patient']}%")
                    ->orWhere('patient_id', 'like', "%{$filters['patient']}%");
            });
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') $query->where('status', $filters['status']);
        if (! empty($filters['type']) && $filters['type'] !== 'All') $query->where('type', $filters['type']);

        $rows = $query->orderBy('name')->get()->map(fn ($p) => [
            'Patient ID' => $p->patient_id,
            'Name' => $p->name,
            'Type' => $p->type,
            'Course/Dept' => $p->course_dept,
            'Contact' => $p->contact,
            'Status' => $p->status,
        ]);

        return [
            'columns' => ['Patient ID', 'Name', 'Type', 'Course/Dept', 'Contact', 'Status'],
            'rows' => $rows,
        ];
    }

    private function exportMedicalCertificates(array $filters): array
    {
        $query = MedicalCertificate::query();
        if (! empty($filters['start_date'])) $query->where('issue_date', '>=', $filters['start_date']);
        if (! empty($filters['end_date'])) $query->where('issue_date', '<=', $filters['end_date']);
        if (! empty($filters['status']) && $filters['status'] !== 'All') $query->where('status', $filters['status']);
        if (! empty($filters['patient'])) $query->where('patient', 'like', "%{$filters['patient']}%");

        $rows = $query->orderBy('issue_date', 'desc')->get()->map(fn ($mc) => [
            'Reference' => $mc->reference,
            'Patient' => $mc->patient,
            'Purpose' => $mc->purpose,
            'Diagnosis' => $mc->diagnosis,
            'Issued By' => $mc->issued_by,
            'Issue Date' => $mc->issue_date instanceof Carbon ? $mc->issue_date->format('Y-m-d') : $mc->issue_date,
            'Valid Until' => $mc->valid_until instanceof Carbon ? $mc->valid_until->format('Y-m-d') : $mc->valid_until,
            'Status' => $mc->status,
        ]);

        return [
            'columns' => ['Reference', 'Patient', 'Purpose', 'Diagnosis', 'Issued By', 'Issue Date', 'Valid Until', 'Status'],
            'rows' => $rows,
        ];
    }

    private function exportPrescriptions(array $filters): array
    {
        $query = Prescription::with('medications');
        if (! empty($filters['start_date'])) $query->where('prescription_date', '>=', $filters['start_date']);
        if (! empty($filters['end_date'])) $query->where('prescription_date', '<=', $filters['end_date']);
        if (! empty($filters['patient'])) $query->where('patient', 'like', "%{$filters['patient']}%");
        if (! empty($filters['staff'])) $query->where('prescribed_by', 'like', "%{$filters['staff']}%");

        $rows = $query->orderBy('prescription_date', 'desc')->get()->flatMap(fn ($rx) => $rx->medications->map(fn ($m) => [
            'Reference' => $rx->reference,
            'Patient' => $rx->patient,
            'Prescribed By' => $rx->prescribed_by,
            'Date' => $rx->prescription_date instanceof Carbon ? $rx->prescription_date->format('Y-m-d') : $rx->prescription_date,
            'Medicine' => $m->medicine_name,
            'Dosage' => $m->dosage,
            'Frequency' => $m->frequency,
            'Duration' => $m->duration,
            'Instructions' => $m->instructions,
        ]));

        return [
            'columns' => ['Reference', 'Patient', 'Prescribed By', 'Date', 'Medicine', 'Dosage', 'Frequency', 'Duration', 'Instructions'],
            'rows' => $rows,
        ];
    }
}
