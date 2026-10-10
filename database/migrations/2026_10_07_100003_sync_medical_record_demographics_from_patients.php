<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Medical records kept the age (0) from when the account was created, so
     * ages students entered later in the mobile app never showed. Copy the
     * current profile demographics onto every existing record.
     */
    public function up(): void
    {
        DB::table('patients')->orderBy('id')->each(function ($patient) {
            $values = array_filter([
                'name' => $patient->name,
                'age' => $patient->age,
                'course_dept' => $patient->course_dept,
                'contact' => $patient->contact,
                'emergency_contact' => $patient->emergency_contact,
            ], fn ($value) => $value !== null && $value !== '');

            if ($values) {
                DB::table('medical_records')->where('patient_id', $patient->patient_id)->update($values);
            }
        });
    }

    public function down(): void
    {
        // Data fix only.
    }
};
