<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Seeder;

class AppointmentsSeeder extends Seeder
{
    public function run(): void
    {
        $staffUsers = User::pluck('id', 'name');

        $appointments = [
            ['reference' => 'APT-2026-001', 'patient' => 'Angela Reyes', 'patient_id' => '2023-0104', 'type' => 'Check-up', 'reason' => 'Annual physical examination required by the registrar.', 'date' => '2026-07-31', 'time' => '08:30 AM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Pending', 'notes' => '', 'requested_on' => '2026-07-28'],
            ['reference' => 'APT-2026-002', 'patient' => 'Mark Dela Cruz', 'patient_id' => '2022-0941', 'type' => 'Dental concern', 'reason' => 'Persistent toothache on the upper right molar for 3 days.', 'date' => '2026-07-31', 'time' => '09:15 AM', 'staff' => 'Dr. S. Lopez', 'status' => 'Under Review', 'notes' => 'Awaiting dentist availability confirmation.', 'requested_on' => '2026-07-29'],
            ['reference' => 'APT-2026-003', 'patient' => 'Joanna Lim', 'patient_id' => '2021-1122', 'type' => 'Follow-up', 'reason' => 'Follow-up on allergic rhinitis medication.', 'date' => '2026-07-31', 'time' => '10:00 AM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Approved', 'notes' => '', 'requested_on' => '2026-07-27'],
            ['reference' => 'APT-2026-004', 'patient' => 'John Paul Santos', 'patient_id' => '2023-0881', 'type' => 'Fever', 'reason' => 'High fever (38.2°C) and body aches since morning.', 'date' => '2026-07-31', 'time' => '11:00 AM', 'staff' => 'Nurse C. Villanueva', 'status' => 'Approved', 'notes' => 'Requested an early slot due to afternoon classes.', 'requested_on' => '2026-07-31'],
            ['reference' => 'APT-2026-005', 'patient' => 'Patricia Mae Garcia', 'patient_id' => '2024-0012', 'type' => 'Vaccination', 'reason' => 'Hepatitis B second dose.', 'date' => '2026-07-31', 'time' => '01:30 PM', 'staff' => 'Nurse J. Santos', 'status' => 'Rejected', 'notes' => 'Rejected — Hep B vaccine supply is currently out of stock. Resubmit next month.', 'requested_on' => '2026-07-25'],
            ['reference' => 'APT-2026-006', 'patient' => 'Susan Clave', 'patient_id' => 'EMP-119', 'type' => 'Check-up', 'reason' => 'Blood pressure monitoring for hypertension management.', 'date' => '2026-08-03', 'time' => '02:30 PM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Rescheduled', 'notes' => 'Rescheduled from 2026-07-30 10:00 AM to 2026-08-03 02:30 PM — registrar meeting conflict.', 'requested_on' => '2026-07-26'],
            ['reference' => 'APT-2026-007', 'patient' => 'Dr. Alberto Ruiz', 'patient_id' => 'EMP-042', 'type' => 'Emergency', 'reason' => 'Acute back pain after lifting equipment.', 'date' => '2026-07-30', 'time' => '03:00 PM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Completed', 'notes' => 'Treated and released. Advised 1 day of rest.', 'requested_on' => '2026-07-30'],
            ['reference' => 'APT-2026-008', 'patient' => 'Angela Reyes', 'patient_id' => '2023-0104', 'type' => 'Follow-up', 'reason' => 'Review of migraine management plan.', 'date' => '2026-08-05', 'time' => '09:00 AM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Cancelled', 'notes' => 'Cancelled — patient requested to move to a later date.', 'requested_on' => '2026-07-29'],
            ['reference' => 'APT-2026-009', 'patient' => 'Joanna Lim', 'patient_id' => '2021-1122', 'type' => 'Check-up', 'reason' => 'Skin rash on forearms, possible contact dermatitis.', 'date' => '2026-08-04', 'time' => '10:30 AM', 'staff' => 'Nurse C. Villanueva', 'status' => 'Under Review', 'notes' => '', 'requested_on' => '2026-07-31'],
            ['reference' => 'APT-2026-010', 'patient' => 'Mark Dela Cruz', 'patient_id' => '2022-0941', 'type' => 'Follow-up', 'reason' => 'GERD follow-up and prescription refill.', 'date' => '2026-08-06', 'time' => '01:00 PM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Pending', 'notes' => '', 'requested_on' => '2026-07-30'],
            ['reference' => 'APT-2026-011', 'patient' => 'Angela Reyes', 'patient_id' => '2023-0104', 'type' => 'Consultation', 'reason' => 'Regular semester health checkup and wellness consultation.', 'date' => '2026-09-22', 'time' => '10:00 AM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Approved', 'notes' => 'Confirmed by clinic staff.', 'requested_on' => '2026-09-15'],
            ['reference' => 'APT-2026-012', 'patient' => 'Angela Reyes', 'patient_id' => '2023-0104', 'type' => 'Check-up', 'reason' => 'Headache and fatigue consultation.', 'date' => '2026-07-30', 'time' => '02:00 PM', 'staff' => 'Dr. R. Mendoza', 'status' => 'Completed', 'notes' => 'Consultation completed. Prescribed pain relief.', 'requested_on' => '2026-07-28'],
            ['reference' => 'APT-2026-013', 'patient' => 'Angela Reyes', 'patient_id' => '2023-0104', 'type' => 'Dental concern', 'reason' => 'Routine dental prophylaxis and checkup.', 'date' => '2026-09-25', 'time' => '01:30 PM', 'staff' => 'Dr. S. Lopez', 'status' => 'Rescheduled', 'notes' => 'Rescheduled due to exam schedule.', 'requested_on' => '2026-09-14'],
        ];

        foreach ($appointments as $appointment) {
            $staffId = $staffUsers[$appointment['staff']] ?? null;
            Appointment::firstOrCreate(['reference' => $appointment['reference']], [
                ...$appointment,
                'staff_id' => $staffId,
            ]);
        }
    }
}
