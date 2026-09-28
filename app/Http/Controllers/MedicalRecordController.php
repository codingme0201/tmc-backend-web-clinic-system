<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMedicalRecordAllergyRequest;
use App\Http\Requests\StoreMedicalRecordConditionRequest;
use App\Http\Requests\UpdateMedicalRecordAllergyRequest;
use App\Http\Requests\UpdateMedicalRecordConditionRequest;
use App\Http\Requests\UpdateMedicalRecordStatusRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordAllergy;
use App\Models\MedicalRecordCondition;
use App\Models\MedicalRecordHistory;
use App\Models\MedicalRecordMedication;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MedicalRecordController extends Controller
{
    /**
     * List medical records with their clinical child sections loaded.
     *
     * Optional query params mirror the Appointments/Consultations lists so
     * the frontend can move search/filtering server-side later:
     *   ?search=<name|patient id|condition|allergen>
     *   ?status=Active|Archived
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MedicalRecord::with(['histories', 'conditions', 'allergies', 'medications']);

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('patient_id', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhereHas('conditions', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('allergies', fn ($a) => $a->where('allergen', 'like', "%{$search}%"));
            });
        }

        $status = $request->query('status');
        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        return MedicalRecordResource::collection($query->orderBy('name')->get());
    }

    /**
     * Show a single medical record with all clinical sections.
     */
    public function show(MedicalRecord $record): MedicalRecordResource
    {
        return $this->resourceWithChildren($record);
    }

    /**
     * Create the medical record for a registered patient.
     *
     * Each patient has at most one record (patient_id is unique), so this
     * returns 409 when one already exists. Demographics are copied from the
     * patient registry; age/sex can be supplied when the registry lacks them.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patientId' => ['required', 'string', 'exists:patients,patient_id'],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'sex' => ['nullable', 'string', 'in:Male,Female,Unspecified'],
        ]);

        if (MedicalRecord::where('patient_id', $validated['patientId'])->exists()) {
            return response()->json(['message' => 'This patient already has a medical record.'], 409);
        }

        $patient = Patient::where('patient_id', $validated['patientId'])->firstOrFail();
        $record = $patient->ensureMedicalRecord();

        $overrides = array_filter([
            'age' => $validated['age'] ?? null,
            'sex' => $validated['sex'] ?? null,
        ], fn ($v) => $v !== null);
        if ($overrides) {
            $record->update($overrides);
        }

        return $this->resourceWithChildren($record)->response()->setStatusCode(201);
    }

    /**
     * Update the archive/active status of a medical record.
     */
    public function updateStatus(
        UpdateMedicalRecordStatusRequest $request,
        MedicalRecord $record,
    ): JsonResponse {
        $record->update([
            'status' => $request->validated('status'),
            'last_updated' => now()->toDateString(),
        ]);

        return $this->resourceWithChildren($record)->response();
    }

    // ---------- Medical conditions ----------

    /**
     * Add a diagnosed condition to a record; returns the full updated record.
     */
    public function storeCondition(StoreMedicalRecordConditionRequest $request, MedicalRecord $record): JsonResponse
    {
        $validated = $request->validated();

        $record->conditions()->create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'Active',
            'diagnosed_date' => $validated['diagnosedDate'] ?? now()->toDateString(),
            'notes' => $validated['notes'] ?? '',
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Update a condition on a record; returns the full updated record.
     */
    public function updateCondition(
        UpdateMedicalRecordConditionRequest $request,
        MedicalRecord $record,
        MedicalRecordCondition $condition,
    ): JsonResponse {
        if ($condition->medical_record_id !== $record->id) {
            abort(404, 'Condition not found on this record.');
        }

        $validated = $request->validated();

        $condition->update([
            'name' => $validated['name'] ?? $condition->name,
            'status' => $validated['status'] ?? $condition->status,
            'diagnosed_date' => $validated['diagnosedDate'] ?? $condition->diagnosed_date?->format('Y-m-d'),
            'notes' => $validated['notes'] ?? $condition->notes,
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Remove a condition from a record; returns the full updated record.
     */
    public function destroyCondition(
        MedicalRecord $record,
        MedicalRecordCondition $condition,
    ): JsonResponse {
        if ($condition->medical_record_id !== $record->id) {
            abort(404, 'Condition not found on this record.');
        }

        $condition->delete();
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    // ---------- Allergies ----------

    /**
     * Record an allergy on a record; returns the full updated record.
     */
    public function storeAllergy(StoreMedicalRecordAllergyRequest $request, MedicalRecord $record): JsonResponse
    {
        $validated = $request->validated();

        $record->allergies()->create([
            'allergen' => $validated['allergen'],
            'reaction' => $validated['reaction'] ?? '',
            'severity' => $validated['severity'] ?? 'Moderate',
            'date_recorded' => $validated['dateRecorded'] ?? now()->toDateString(),
            'notes' => $validated['notes'] ?? '',
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Update an allergy on a record; returns the full updated record.
     */
    public function updateAllergy(
        UpdateMedicalRecordAllergyRequest $request,
        MedicalRecord $record,
        MedicalRecordAllergy $allergy,
    ): JsonResponse {
        if ($allergy->medical_record_id !== $record->id) {
            abort(404, 'Allergy not found on this record.');
        }

        $validated = $request->validated();

        $allergy->update([
            'allergen' => $validated['allergen'] ?? $allergy->allergen,
            'reaction' => $validated['reaction'] ?? $allergy->reaction,
            'severity' => $validated['severity'] ?? $allergy->severity,
            'date_recorded' => $validated['dateRecorded'] ?? $allergy->date_recorded?->format('Y-m-d'),
            'notes' => $validated['notes'] ?? $allergy->notes,
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Remove an allergy from a record; returns the full updated record.
     */
    public function destroyAllergy(
        MedicalRecord $record,
        MedicalRecordAllergy $allergy,
    ): JsonResponse {
        if ($allergy->medical_record_id !== $record->id) {
            abort(404, 'Allergy not found on this record.');
        }

        $allergy->delete();
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    // ---------- Medications ----------

    /**
     * Add a medication to a record; returns the full updated record.
     */
    public function storeMedication(Request $request, MedicalRecord $record): JsonResponse
    {
        $validated = $request->validate($this->medicationRules(true));

        $record->medications()->create([
            ...$this->medicationColumns($validated),
            'status' => $validated['status'] ?? 'Active',
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Update a medication on a record; returns the full updated record.
     */
    public function updateMedication(Request $request, MedicalRecord $record, MedicalRecordMedication $medication): JsonResponse
    {
        if ($medication->medical_record_id !== $record->id) {
            abort(404, 'Medication not found on this record.');
        }

        $validated = $request->validate($this->medicationRules(false));

        $medication->update($this->medicationColumns($validated));
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Remove a medication from a record; returns the full updated record.
     */
    public function destroyMedication(MedicalRecord $record, MedicalRecordMedication $medication): JsonResponse
    {
        if ($medication->medical_record_id !== $record->id) {
            abort(404, 'Medication not found on this record.');
        }

        $medication->delete();
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function medicationRules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'prescribedBy' => ['nullable', 'string', 'max:255'],
            'prescribedDate' => ['nullable', 'date_format:Y-m-d'],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'status' => ['sometimes', 'string', 'in:Active,Completed,Discontinued'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Map validated camelCase medication input to its columns.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function medicationColumns(array $validated): array
    {
        $map = [
            'name' => 'name', 'dosage' => 'dosage', 'frequency' => 'frequency', 'route' => 'route',
            'prescribedBy' => 'prescribed_by', 'prescribedDate' => 'prescribed_date',
            'startDate' => 'start_date', 'endDate' => 'end_date', 'status' => 'status',
            'instructions' => 'instructions',
        ];

        $columns = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $columns[$column] = $validated[$input];
            }
        }

        return $columns;
    }

    // ---------- Medical history ----------

    /**
     * Add a medical history entry; returns the full updated record.
     */
    public function storeHistory(Request $request, MedicalRecord $record): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'condition' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $record->histories()->create([
            'date' => $validated['date'] ?? now()->toDateString(),
            'condition' => $validated['condition'],
            'notes' => $validated['notes'] ?? '',
        ]);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Update a medical history entry; returns the full updated record.
     */
    public function updateHistory(Request $request, MedicalRecord $record, MedicalRecordHistory $history): JsonResponse
    {
        if ($history->medical_record_id !== $record->id) {
            abort(404, 'History entry not found on this record.');
        }

        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'condition' => ['sometimes', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $history->update($validated);
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Remove a medical history entry; returns the full updated record.
     */
    public function destroyHistory(MedicalRecord $record, MedicalRecordHistory $history): JsonResponse
    {
        if ($history->medical_record_id !== $record->id) {
            abort(404, 'History entry not found on this record.');
        }

        $history->delete();
        $record->update(['last_updated' => now()->toDateString()]);

        return $this->resourceWithChildren($record)->response();
    }

    /**
     * Reload the record with children and return its resource instance.
     *
     * The resource response wraps the record in { data: ... }, matching what
     * the frontend services expect from every other mutation endpoint.
     */
    private function resourceWithChildren(MedicalRecord $record): MedicalRecordResource
    {
        return new MedicalRecordResource(
            $record->fresh(['histories', 'conditions', 'allergies', 'medications']),
        );
    }
}
