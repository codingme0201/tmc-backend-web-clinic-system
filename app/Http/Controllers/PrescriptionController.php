<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePrescriptionRequest;
use App\Http\Requests\UpdatePrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\MedicalRecord;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    /**
     * List prescriptions with server-side search, filters, and pagination.
     *
     * Query parameters (all optional):
     *   ?search=<reference | patient | registry id | prescriber | medicine>
     *   ?patient=<registry id or patient name>   — patient prescription history
     *   ?date=YYYY-MM-DD
     *   ?page=N   ?per_page=N (default 8, capped at 50)
     *
     * Returns Laravel's paginated payload ({ data, links, meta }) so the
     * frontend drives the shared Pagination component from `meta`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Prescription::query()->with(['medications', 'consultation']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('patient', 'like', "%{$search}%")
                    ->orWhere('patient_id', 'like', "%{$search}%")
                    ->orWhere('prescribed_by', 'like', "%{$search}%")
                    ->orWhereHas('medications', fn ($m) => $m->where('medicine_name', 'like', "%{$search}%"));
            });
        }

        $patient = trim((string) $request->query('patient', ''));
        if ($patient !== '') {
            $query->where(function ($q) use ($patient) {
                $q->where('patient_id', $patient)
                    ->orWhere('patient', 'like', "%{$patient}%");
            });
        }

        $date = $request->query('date');
        if ($date) {
            $query->whereDate('prescription_date', $date);
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 8)));

        return PrescriptionResource::collection(
            $query->orderByDesc('prescription_date')->orderByDesc('id')->paginate($perPage)->withQueryString(),
        );
    }

    /**
     * Show a single prescription with its medication lines and consultation.
     */
    public function show(Prescription $prescription): PrescriptionResource
    {
        return new PrescriptionResource(
            $prescription->loadMissing(['medications', 'consultation']),
        );
    }

    /**
     * List prescriptions for the authenticated patient.
     */
    public function myPrescriptions(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            abort(404, 'No patient record associated with this user.');
        }

        $prescriptions = Prescription::where('patient_id', $patient->patient_id)
            ->with(['medications', 'consultation'])
            ->orderByDesc('prescription_date')
            ->orderByDesc('id')
            ->get();

        return PrescriptionResource::collection($prescriptions);
    }

    /**
     * Show a specific prescription for the authenticated patient.
     */
    public function myPrescriptionShow(Prescription $prescription, Request $request): PrescriptionResource
    {
        $patient = $request->user()->patient;

        if (! $patient || $prescription->patient_id !== $patient->patient_id) {
            abort(403, 'You do not have permission to view this prescription.');
        }

        return new PrescriptionResource(
            $prescription->loadMissing(['medications', 'consultation']),
        );
    }

    /**
     * Create a prescription record (during or after a consultation).
     *
     * The reference is assigned sequentially for the prescription date inside
     * the transaction so concurrent requests cannot collide. The prescription
     * is composed into the patient's medical record when one exists (the
     * medical record keeps no copy of the prescription — it is linked, not
     * duplicated).
     */
    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $date = $validated['date'] ?? now()->toDateString();

        $prescription = DB::transaction(function () use ($request, $validated, $date) {
            $prescriberName = ($validated['prescribed_by'] ?? '') ?: $request->user()->name;
            $prescriberId = User::where('name', $prescriberName)->value('id');

            $prescription = Prescription::create([
                'reference' => Prescription::nextReference($date, true),
                'patient' => $validated['patient'],
                'patient_id' => $validated['patient_id'] ?? null,
                'consultation_id' => $validated['consultation_id'] ?? null,
                'medical_record_id' => $validated['medical_record_id']
                    ?? MedicalRecord::where('patient_id', $validated['patient_id'] ?? null)->value('id'),
                'prescribed_by_id' => $prescriberId,
                'prescribed_by' => $prescriberName,
                'prescription_date' => $date,
            ]);

            $this->syncMedications($prescription, $validated['medications']);

            return $prescription;
        });

        return (new PrescriptionResource($prescription->loadMissing(['medications', 'consultation'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update prescription information.
     *
     * Nullable links (consultation / medical record / patient id) clear when
     * the payload sends an explicit null; the remaining fields coalesce. When
     * `medications` is present it replaces the list wholesale — the form
     * payload is the source of truth for the medication lines.
     */
    public function update(UpdatePrescriptionRequest $request, Prescription $prescription): PrescriptionResource
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated, $prescription) {
            foreach (['patient_id', 'consultation_id', 'medical_record_id'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $prescription->{$field} = $validated[$field];
                }
            }

            // When the patient changes (and no medical record was pinned),
            // re-compose the prescription into the new patient's medical
            // record so the link never points at a different patient's file
            // (same auto-link rule as store).
            if (! array_key_exists('medical_record_id', $validated)
                && (array_key_exists('patient', $validated) || array_key_exists('patient_id', $validated))) {
                $prescription->medical_record_id = MedicalRecord::where('patient_id', $prescription->patient_id)->value('id');
            }

            if (array_key_exists('patient', $validated)) {
                $prescription->patient = $validated['patient'];
            }
            if (array_key_exists('date', $validated)) {
                $prescription->prescription_date = $validated['date'];
            }
            if (array_key_exists('prescribed_by', $validated) && $validated['prescribed_by'] !== null) {
                $prescription->prescribed_by = $validated['prescribed_by'] ?: $prescription->prescribed_by;
            }

            if (isset($validated['medications'])) {
                $this->syncMedications($prescription, $validated['medications']);
            }

            $prescription->save();
        });

        return new PrescriptionResource($prescription->loadMissing(['medications', 'consultation']));
    }

    /**
     * Replace a prescription's medication lines (create or update).
     *
     * Rows have no stable client identity, so the submitted list is the
     * source of truth; `sort_order` preserves the form's row order.
     */
    private function syncMedications(Prescription $prescription, array $medications): void
    {
        $prescription->medications()->delete();

        $rows = array_values($medications);
        foreach ($rows as $index => $row) {
            $rows[$index]['sort_order'] = $index + 1;
        }

        $prescription->medications()->createMany($rows);
    }
}
