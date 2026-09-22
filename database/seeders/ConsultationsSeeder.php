<?php

namespace Database\Seeders;

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Seeder;

class ConsultationsSeeder extends Seeder
{
    public function run(): void
    {
        $staffUsers = User::pluck('id', 'name');

        $consultations = [
            [
                'reference' => 'CONS-2026-010', 'date' => '2026-08-03', 'time' => '09:00 AM',
                'patient' => 'Mark Dela Cruz', 'patient_id' => '2022-0941', 'staff' => 'Dr. R. Mendoza',
                'status' => 'Scheduled', 'chief_complaint' => 'GERD follow-up and prescription refill',
                'vitals' => ['temperature' => '', 'bloodPressure' => '', 'pulseRate' => '', 'respiratoryRate' => '', 'height' => '', 'weight' => ''],
                'clinical_findings' => '', 'diagnosis' => '', 'treatment' => '', 'disposition' => '',
                'started_at' => null, 'completed_at' => null,
            ],
            [
                'reference' => 'CONS-2026-011', 'date' => '2026-08-04', 'time' => '10:30 AM',
                'patient' => 'Joanna Lim', 'patient_id' => '21-011122', 'staff' => 'Nurse C. Villanueva',
                'status' => 'Scheduled', 'chief_complaint' => 'Skin rash on forearms, possible contact dermatitis',
                'vitals' => ['temperature' => '', 'bloodPressure' => '', 'pulseRate' => '', 'respiratoryRate' => '', 'height' => '', 'weight' => ''],
                'clinical_findings' => '', 'diagnosis' => '', 'treatment' => '', 'disposition' => '',
                'started_at' => null, 'completed_at' => null,
            ],
            [
                'reference' => 'CONS-2026-012', 'date' => '2026-08-05', 'time' => '01:00 PM',
                'patient' => 'Angela Reyes', 'patient_id' => '24-021128', 'staff' => 'Dr. R. Mendoza',
                'status' => 'Scheduled', 'chief_complaint' => 'Migraine management plan review',
                'vitals' => ['temperature' => '', 'bloodPressure' => '', 'pulseRate' => '', 'respiratoryRate' => '', 'height' => '', 'weight' => ''],
                'clinical_findings' => '', 'diagnosis' => '', 'treatment' => '', 'disposition' => '',
                'started_at' => null, 'completed_at' => null,
            ],
            [
                'reference' => 'CONS-2026-013', 'date' => '2026-08-01', 'time' => '11:00 AM',
                'patient' => 'John Paul Santos', 'patient_id' => '23-010881', 'staff' => 'Nurse C. Villanueva',
                'status' => 'In Progress', 'chief_complaint' => 'High fever (38.2°C) and body aches since morning',
                'vitals' => ['temperature' => '38.2°C', 'bloodPressure' => '118/76', 'pulseRate' => '92 bpm', 'respiratoryRate' => '20 /min', 'height' => '168 cm', 'weight' => '61 kg'],
                'clinical_findings' => 'Flushed skin, mild dehydration. Throat slightly red.',
                'diagnosis' => '', 'treatment' => '', 'disposition' => '',
                'started_at' => '2026-08-01 11:05 AM', 'completed_at' => null,
            ],
            [
                'reference' => 'CONS-2026-001', 'date' => '2026-07-30', 'time' => '08:45 AM',
                'patient' => 'Angela Reyes', 'patient_id' => '24-021128', 'staff' => 'Dr. R. Mendoza',
                'status' => 'Completed', 'chief_complaint' => 'Severe headache and nausea for 2 days',
                'vitals' => ['temperature' => '36.8°C', 'bloodPressure' => '110/70', 'pulseRate' => '72 bpm', 'respiratoryRate' => '16 /min', 'height' => '162 cm', 'weight' => '54 kg'],
                'clinical_findings' => 'Mild pericranial muscle tenderness. No focal neurological deficits.',
                'diagnosis' => 'Tension Headache due to fatigue',
                'treatment' => 'Paracetamol 500mg, 1 tab. Rest in clinic for 1 hour. Hydrate well.',
                'disposition' => 'Sent to Class',
                'started_at' => '2026-07-30 08:50 AM', 'completed_at' => '2026-07-30 09:40 AM',
            ],
            [
                'reference' => 'CONS-2026-002', 'date' => '2026-07-30', 'time' => '10:15 AM',
                'patient' => 'Joanna Lim', 'patient_id' => '21-011122', 'staff' => 'Nurse C. Villanueva',
                'status' => 'Completed', 'chief_complaint' => 'Slight fever and runny nose',
                'vitals' => ['temperature' => '37.9°C', 'bloodPressure' => '120/80', 'pulseRate' => '84 bpm', 'respiratoryRate' => '18 /min', 'height' => '158 cm', 'weight' => '49 kg'],
                'clinical_findings' => 'Congested nasal passages, mild pharyngeal erythema.',
                'diagnosis' => 'Mild Flu Symptoms',
                'treatment' => 'Paracetamol 500mg every 4 hours, Cetirizine 10mg. Oral rehydration.',
                'disposition' => 'Sent Home',
                'started_at' => '2026-07-30 10:20 AM', 'completed_at' => '2026-07-30 11:00 AM',
            ],
            [
                'reference' => 'CONS-2026-003', 'date' => '2026-07-29', 'time' => '02:00 PM',
                'patient' => 'Mark Dela Cruz', 'patient_id' => '22-010941', 'staff' => 'Dr. R. Mendoza',
                'status' => 'Completed', 'chief_complaint' => 'Acid reflux and burning sensation in chest',
                'vitals' => ['temperature' => '36.5°C', 'bloodPressure' => '120/75', 'pulseRate' => '76 bpm', 'respiratoryRate' => '17 /min', 'height' => '173 cm', 'weight' => '68 kg'],
                'clinical_findings' => 'Epigastric tenderness on palpation.',
                'diagnosis' => 'Acid Reflux / GERD Flare-up',
                'treatment' => 'Antacid liquid 10ml. Avoid spicy food and late meals.',
                'disposition' => 'Sent to Class',
                'started_at' => '2026-07-29 02:05 PM', 'completed_at' => '2026-07-29 02:35 PM',
            ],
            [
                'reference' => 'CONS-2026-004', 'date' => '2026-07-29', 'time' => '11:30 AM',
                'patient' => 'Susan Clave', 'patient_id' => '23-010119', 'staff' => 'Nurse C. Villanueva',
                'status' => 'Completed', 'chief_complaint' => 'Accidental slip, minor ankle sprain',
                'vitals' => ['temperature' => '36.4°C', 'bloodPressure' => '130/80', 'pulseRate' => '88 bpm', 'respiratoryRate' => '18 /min', 'height' => '160 cm', 'weight' => '58 kg'],
                'clinical_findings' => 'Mild swelling and tenderness over right lateral ankle.',
                'diagnosis' => 'Grade 1 Right Ankle Sprain',
                'treatment' => 'R.I.C.E. protocol, elastic bandage applied. Ibuprofen 400mg.',
                'disposition' => 'Referred to Hospital',
                'started_at' => '2026-07-29 11:35 AM', 'completed_at' => '2026-07-29 12:05 PM',
            ],
            [
                'reference' => 'CONS-2026-005', 'date' => '2026-07-28', 'time' => '09:15 AM',
                'patient' => 'Patricia Mae Garcia', 'patient_id' => '24-010012', 'staff' => 'Dr. S. Lopez',
                'status' => 'Completed', 'chief_complaint' => 'Persistent toothache on upper right molar',
                'vitals' => ['temperature' => '36.9°C', 'bloodPressure' => '115/75', 'pulseRate' => '78 bpm', 'respiratoryRate' => '16 /min', 'height' => '165 cm', 'weight' => '55 kg'],
                'clinical_findings' => 'Caries noted on upper right first molar.',
                'diagnosis' => 'Dental Caries',
                'treatment' => 'Temporary filling applied. Schedule root canal follow-up. Analgesic as needed.',
                'disposition' => 'Sent to Class',
                'started_at' => '2026-07-28 09:20 AM', 'completed_at' => '2026-07-28 09:55 AM',
            ],
        ];

        foreach ($consultations as $consultation) {
            $staffId = $staffUsers[$consultation['staff']] ?? null;
            Consultation::firstOrCreate(['reference' => $consultation['reference']], [
                ...$consultation,
                'staff_id' => $staffId,
            ]);
        }
    }
}
