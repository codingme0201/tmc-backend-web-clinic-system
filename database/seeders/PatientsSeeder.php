<?php

namespace Database\Seeders;

use App\Models\Patient;
use Illuminate\Database\Seeder;

class PatientsSeeder extends Seeder
{
    /**
     * Seed the patient registry. Idempotent — keyed by patient_id.
     */
    public function run(): void
    {
        $patients = [
            ['patient_id' => '24-021128', 'name' => 'Angela Reyes', 'type' => 'Student', 'course_dept' => 'BS Computer Science', 'contact' => '+63 912 345 6789', 'emergency_contact' => 'Maria Reyes (Mother) - +63 912 345 6780', 'allergies' => 'Peanuts, Penicillin', 'history' => 'Mild Asthma, Migraines', 'status' => 'Active'],
            ['patient_id' => '22-010941', 'name' => 'Mark Dela Cruz', 'type' => 'Student', 'course_dept' => 'BS Information Technology', 'contact' => '+63 922 876 5432', 'emergency_contact' => 'Tomas Dela Cruz (Father) - +63 922 876 5431', 'allergies' => 'None', 'history' => 'Gastroesophageal reflux disease (GERD)', 'status' => 'Active'],
            ['patient_id' => '21-011122', 'name' => 'Joanna Lim', 'type' => 'Student', 'course_dept' => 'BEED Elementary Education', 'contact' => '+63 933 222 1111', 'emergency_contact' => 'Lim Sian (Father) - +63 933 222 0000', 'allergies' => 'Sulfa drugs', 'history' => 'Allergic Rhinitis', 'status' => 'Active'],
            ['patient_id' => 'EMP-042', 'name' => 'Dr. Alberto Ruiz', 'type' => 'Faculty', 'course_dept' => 'College of Engineering', 'contact' => '+63 944 123 9876', 'emergency_contact' => 'Elena Ruiz (Wife) - +63 944 123 9870', 'allergies' => 'Aspirin', 'history' => 'Hypertension', 'status' => 'Active'],
            ['patient_id' => 'EMP-119', 'name' => 'Susan Clave', 'type' => 'Staff', 'course_dept' => 'Registrar Office', 'contact' => '+63 955 456 7890', 'emergency_contact' => 'Robert Clave (Husband) - +63 955 456 7891', 'allergies' => 'Seafood', 'history' => 'None', 'status' => 'Active'],
            ['patient_id' => '23-010881', 'name' => 'John Paul Santos', 'type' => 'Student', 'course_dept' => 'BS Business Administration', 'contact' => '+63 977 123 4567', 'emergency_contact' => 'Lorna Santos (Mother) - +63 977 123 4568', 'allergies' => 'None', 'history' => 'None', 'status' => 'Active'],
            ['patient_id' => '24-010012', 'name' => 'Patricia Mae Garcia', 'type' => 'Student', 'course_dept' => 'BS Hospitality Management', 'contact' => '+63 998 765 4321', 'emergency_contact' => 'Leon Garcia (Father) - +63 998 765 4320', 'allergies' => 'Dust Mites', 'history' => 'Eczema', 'status' => 'Active'],
        ];

        foreach ($patients as $patient) {
            Patient::firstOrCreate(['patient_id' => $patient['patient_id']], $patient);
        }
    }
}
