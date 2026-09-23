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
