<?php

namespace Database\Seeders;

use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Database\Seeder;

class MedicalRecordsSeeder extends Seeder
{
    public function run(): void
    {
        $prescribers = User::pluck('id', 'name');

        $records = [
            [
                'patient_id' => '24-021128', 'name' => 'Angela Reyes', 'age' => 20, 'sex' => 'Female',
                'type' => 'Student', 'course_dept' => 'BS Computer Science', 'contact' => '+63 912 345 6789',
                'emergency_contact' => 'Maria Reyes (Mother) — +63 912 345 6780', 'status' => 'Active',
                'last_updated' => '2026-08-05',
                'histories' => [
                    ['date' => '2025-06-10', 'condition' => 'Allergic Rhinitis', 'notes' => 'Recurring seasonal symptoms; managed with antihistamines.'],
                    ['date' => '2024-03-22', 'condition' => 'Recurring headaches', 'notes' => 'Occasional migraines triggered by stress and lack of sleep.'],
                    ['date' => '2023-11-05', 'condition' => 'Respiratory infection', 'notes' => 'Upper respiratory tract infection, recovered fully.'],
                ],
                'conditions' => [
                    ['name' => 'Mild Asthma', 'status' => 'Active', 'diagnosed_date' => '2023-11-12', 'notes' => 'Exercise-induced; rescue inhaler advised.'],
                    ['name' => 'Migraines', 'status' => 'Active', 'diagnosed_date' => '2024-04-02', 'notes' => 'Patient advised to monitor triggers and maintain regular sleep.'],
                ],
                'allergies' => [
                    ['allergen' => 'Peanuts', 'reaction' => 'Swelling and hives', 'severity' => 'Severe', 'date_recorded' => '2023-06-15', 'notes' => 'Carries emergency antihistamine.'],
                    ['allergen' => 'Penicillin', 'reaction' => 'Skin rash', 'severity' => 'Moderate', 'date_recorded' => '2024-01-20', 'notes' => 'Avoid penicillin-based antibiotics.'],
                ],
                'medications' => [
                    ['name' => 'Paracetamol', 'dosage' => '500 mg', 'frequency' => 'Every 6 hours as needed', 'route' => 'Oral', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2026-07-30', 'start_date' => '2026-07-30', 'end_date' => null, 'status' => 'Completed', 'instructions' => 'Take with food if stomach upset occurs.'],
                    ['name' => 'Salbutamol Inhaler', 'dosage' => '100 mcg', 'frequency' => 'As needed (max 2 puffs)', 'route' => 'Inhalation', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2026-05-10', 'start_date' => '2026-05-10', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Use before exercise or when wheezing.'],
                ],
            ],
            [
                'patient_id' => '22-010941', 'name' => 'Mark Dela Cruz', 'age' => 21, 'sex' => 'Male',
                'type' => 'Student', 'course_dept' => 'BS Information Technology', 'contact' => '+63 922 876 5432',
                'emergency_contact' => 'Tomas Dela Cruz (Father) — +63 922 876 5431', 'status' => 'Active',
                'last_updated' => '2026-08-03',
                'histories' => [
                    ['date' => '2023-02-20', 'condition' => 'Gastroesophageal reflux disease (GERD)', 'notes' => 'Managed with dietary changes and proton pump inhibitors.'],
                    ['date' => '2022-08-14', 'condition' => 'Dengue fever', 'notes' => 'Admitted for observation; recovered without complications.'],
                ],
                'conditions' => [
                    ['name' => 'GERD', 'status' => 'Active', 'diagnosed_date' => '2023-02-20', 'notes' => 'Avoid spicy food and late meals; follow-up refills on file.'],
                ],
                'allergies' => [],
                'medications' => [
                    ['name' => 'Omeprazole', 'dosage' => '20 mg', 'frequency' => 'Once daily before breakfast', 'route' => 'Oral', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2026-07-29', 'start_date' => '2026-07-29', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Take 30 minutes before a meal.'],
                    ['name' => 'Antacid Liquid', 'dosage' => '10 ml', 'frequency' => 'As needed for heartburn', 'route' => 'Oral', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2026-07-29', 'start_date' => '2026-07-29', 'end_date' => null, 'status' => 'Completed', 'instructions' => 'Shake well before use.'],
                ],
            ],
            [
                'patient_id' => '21-011122', 'name' => 'Joanna Lim', 'age' => 23, 'sex' => 'Female',
                'type' => 'Student', 'course_dept' => 'BEED Elementary Education', 'contact' => '+63 933 222 1111',
                'emergency_contact' => 'Lim Sian (Father) — +63 933 222 0000', 'status' => 'Active',
                'last_updated' => '2026-08-04',
                'histories' => [
                    ['date' => '2025-06-10', 'condition' => 'Allergic Rhinitis', 'notes' => 'Seasonal; controlled with oral antihistamines.'],
                    ['date' => '2024-09-15', 'condition' => 'Contact dermatitis', 'notes' => 'Forearm rash from fabric; resolved with topical steroid.'],
                ],
                'conditions' => [
                    ['name' => 'Allergic Rhinitis', 'status' => 'Active', 'diagnosed_date' => '2025-06-10', 'notes' => 'Seasonal triggers; antihistamine on standing order.'],
                    ['name' => 'Contact Dermatitis', 'status' => 'Inactive', 'diagnosed_date' => '2024-09-15', 'notes' => 'Rash resolved; avoid known irritants.'],
                ],
                'allergies' => [
                    ['allergen' => 'Sulfa Drugs', 'reaction' => 'Skin rash', 'severity' => 'Moderate', 'date_recorded' => '2024-01-20', 'notes' => 'Do not prescribe sulfonamide antibiotics.'],
                ],
                'medications' => [
                    ['name' => 'Cetirizine', 'dosage' => '10 mg', 'frequency' => 'Once daily', 'route' => 'Oral', 'prescribed_by' => 'Nurse C. Villanueva', 'prescribed_date' => '2026-06-15', 'start_date' => '2026-06-15', 'end_date' => null, 'status' => 'Active', 'instructions' => 'May cause drowsiness; take at night if needed.'],
                    ['name' => 'Paracetamol', 'dosage' => '500 mg', 'frequency' => 'Every 4 hours as needed', 'route' => 'Oral', 'prescribed_by' => 'Nurse C. Villanueva', 'prescribed_date' => '2026-07-30', 'start_date' => '2026-07-30', 'end_date' => '2026-08-02', 'status' => 'Completed', 'instructions' => 'Do not exceed 4 doses in 24 hours.'],
                ],
            ],
            [
                'patient_id' => '23-010119', 'name' => 'Susan Clave', 'age' => 21, 'sex' => 'Female',
                'type' => 'Student', 'course_dept' => 'BS Business Administration', 'contact' => '+63 955 456 7890',
                'emergency_contact' => 'Robert Clave (Father) — +63 955 456 7891', 'status' => 'Active',
                'last_updated' => '2026-07-29',
                'histories' => [
                    ['date' => '2025-07-10', 'condition' => 'Right ankle sprain', 'notes' => 'Recovered with R.I.C.E. protocol and physiotherapy.'],
                    ['date' => '2024-02-18', 'condition' => 'Urinary tract infection', 'notes' => 'Resolved with a course of antibiotics.'],
                ],
                'conditions' => [
                    ['name' => 'Right Ankle Sprain', 'status' => 'Resolved', 'diagnosed_date' => '2025-07-10', 'notes' => 'Full range of motion restored.'],
                ],
                'allergies' => [
                    ['allergen' => 'Seafood', 'reaction' => 'Hives and itching', 'severity' => 'Mild', 'date_recorded' => '2024-03-02', 'notes' => 'Mild reaction; antihistamine sufficient.'],
                ],
                'medications' => [
                    ['name' => 'Ibuprofen', 'dosage' => '400 mg', 'frequency' => 'Every 8 hours for 3 days', 'route' => 'Oral', 'prescribed_by' => 'Nurse C. Villanueva', 'prescribed_date' => '2026-07-29', 'start_date' => '2026-07-29', 'end_date' => '2026-08-01', 'status' => 'Completed', 'instructions' => 'Take with food.'],
                ],
            ],
            [
                'patient_id' => '23-010881', 'name' => 'John Paul Santos', 'age' => 20, 'sex' => 'Male',
                'type' => 'Student', 'course_dept' => 'BS Business Administration', 'contact' => '+63 977 123 4567',
                'emergency_contact' => 'Lorna Santos (Mother) — +63 977 123 4568', 'status' => 'Active',
                'last_updated' => '2026-08-01',
                'histories' => [
                    ['date' => '2024-07-19', 'condition' => 'Dengue fever', 'notes' => 'Hospitalized for observation; platelet count normalized.'],
                    ['date' => '2023-12-01', 'condition' => 'Bronchitis', 'notes' => 'Treated with rest and fluids; resolved in one week.'],
                ],
                'conditions' => [],
                'allergies' => [],
                'medications' => [
                    ['name' => 'Paracetamol', 'dosage' => '500 mg', 'frequency' => 'Every 6 hours as needed', 'route' => 'Oral', 'prescribed_by' => 'Nurse C. Villanueva', 'prescribed_date' => '2026-08-01', 'start_date' => '2026-08-01', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Use for fever and body aches.'],
                    ['name' => 'Oral Rehydration Salts', 'dosage' => '1 sachet', 'frequency' => 'Every 3 hours', 'route' => 'Oral', 'prescribed_by' => 'Nurse C. Villanueva', 'prescribed_date' => '2026-08-01', 'start_date' => '2026-08-01', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Dissolve in 200 ml of water.'],
                ],
            ],
            [
                'patient_id' => '24-010012', 'name' => 'Patricia Mae Garcia', 'age' => 19, 'sex' => 'Female',
                'type' => 'Student', 'course_dept' => 'BS Hospitality Management', 'contact' => '+63 998 765 4321',
                'emergency_contact' => 'Leon Garcia (Father) — +63 998 765 4320', 'status' => 'Active',
                'last_updated' => '2026-07-28',
                'histories' => [
                    ['date' => '2024-01-30', 'condition' => 'Eczema', 'notes' => 'Recurring dry patches on arms; controlled with moisturizers.'],
                    ['date' => '2023-10-12', 'condition' => 'Dental caries', 'notes' => 'Treated with temporary filling; follow-up scheduled.'],
                ],
                'conditions' => [
                    ['name' => 'Eczema', 'status' => 'Active', 'diagnosed_date' => '2024-01-30', 'notes' => 'Avoid dust mites and harsh soaps.'],
                    ['name' => 'Dental Caries', 'status' => 'Resolved', 'diagnosed_date' => '2023-10-12', 'notes' => 'Temporary filling applied; root canal follow-up advised.'],
                ],
                'allergies' => [
                    ['allergen' => 'Dust Mites', 'reaction' => 'Sneezing and nasal congestion', 'severity' => 'Mild', 'date_recorded' => '2024-02-05', 'notes' => 'Trigger for eczema flare-ups.'],
                ],
                'medications' => [
                    ['name' => 'Hydrocortisone Cream', 'dosage' => '1%', 'frequency' => 'Twice daily on affected areas', 'route' => 'Topical', 'prescribed_by' => 'Dr. S. Lopez', 'prescribed_date' => '2026-04-18', 'start_date' => '2026-04-18', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Apply a thin layer; avoid broken skin.'],
                    ['name' => 'Analgesic', 'dosage' => '500 mg', 'frequency' => 'Every 6 hours as needed', 'route' => 'Oral', 'prescribed_by' => 'Dr. S. Lopez', 'prescribed_date' => '2026-07-28', 'start_date' => '2026-07-28', 'end_date' => '2026-07-30', 'status' => 'Completed', 'instructions' => 'For post-dental procedure pain.'],
                ],
            ],
            [
                'patient_id' => '23-010042', 'name' => 'Alberto Ruiz', 'age' => 22, 'sex' => 'Male',
                'type' => 'Student', 'course_dept' => 'BS Mechanical Engineering', 'contact' => '+63 944 123 9876',
                'emergency_contact' => 'Elena Ruiz (Mother) — +63 944 123 9870', 'status' => 'Active',
                'last_updated' => '2026-07-15',
                'histories' => [
                    ['date' => '2022-03-14', 'condition' => 'Hypertension', 'notes' => 'Lifestyle modification and daily medication; monitored regularly.'],
                    ['date' => '2019-08-30', 'condition' => 'Gastritis', 'notes' => 'Resolved with short-term acid suppression.'],
                ],
                'conditions' => [
                    ['name' => 'Hypertension', 'status' => 'Active', 'diagnosed_date' => '2022-03-14', 'notes' => 'Advise regular BP monitoring and low-sodium diet.'],
                ],
                'allergies' => [
                    ['allergen' => 'Aspirin', 'reaction' => 'Stomach pain', 'severity' => 'Moderate', 'date_recorded' => '2020-11-22', 'notes' => 'Avoid NSAIDs; use paracetamol instead.'],
                ],
                'medications' => [
                    ['name' => 'Amlodipine', 'dosage' => '5 mg', 'frequency' => 'Once daily', 'route' => 'Oral', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2022-03-14', 'start_date' => '2022-03-14', 'end_date' => null, 'status' => 'Active', 'instructions' => 'Take at the same time each day.'],
                    ['name' => 'Esomeprazole', 'dosage' => '20 mg', 'frequency' => 'Once daily for 14 days', 'route' => 'Oral', 'prescribed_by' => 'Dr. R. Mendoza', 'prescribed_date' => '2019-08-30', 'start_date' => '2019-08-30', 'end_date' => '2019-09-13', 'status' => 'Completed', 'instructions' => 'Complete the full course.'],
                ],
            ],
        ];

        foreach ($records as $record) {
            $created = MedicalRecord::firstOrCreate(
                ['patient_id' => $record['patient_id']],
                collect($record)->except(['histories', 'conditions', 'allergies', 'medications'])->all(),
            );

            if ($created->wasRecentlyCreated) {
                foreach ($record['histories'] as $child) {
                    $created->histories()->create($child);
                }
                foreach ($record['conditions'] as $child) {
                    $created->conditions()->create($child);
                }
                foreach ($record['allergies'] as $child) {
                    $created->allergies()->create($child);
                }
                foreach ($record['medications'] as $child) {
                    $prescriberName = $child['prescribed_by'] ?? '';
                    $created->medications()->create([
                        ...$child,
                        'prescribed_by_id' => $prescribers[$prescriberName] ?? null,
                    ]);
                }
            }
        }
    }
}
