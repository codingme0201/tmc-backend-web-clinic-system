<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\MedicalCertificate;
use App\Models\Notification;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        $admin = $users->firstWhere('email', 'admin@tmc.edu.ph') ?? $users->first();
        $doctor = $users->firstWhere('email', 'rmendoza@tmc.edu.ph') ?? $admin;
        $nurse = $users->firstWhere('email', 'cvillanueva@tmc.edu.ph') ?? $admin;

        $notifications = [
            [
                'user_id' => $admin->id,
                'title' => 'System Update Completed',
                'message' => 'The TMC CareLink system has been updated to the latest version. All modules are operational.',
                'type' => 'system',
                'category' => 'system',
                'source' => 'System',
                'is_read' => false,
            ],
            [
                'user_id' => $admin->id,
                'title' => 'New Appointment Request',
                'message' => 'Angela Reyes has requested a Check-up appointment (APT-2026-001).',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => false,
                'metadata' => ['appointment_reference' => 'APT-2026-001'],
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Appointment Approved',
                'message' => 'Appointment APT-2026-003 for Joanna Lim (Follow-up) has been approved.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => true,
                'metadata' => ['appointment_reference' => 'APT-2026-003'],
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Patient Record Updated',
                'message' => 'Patient profile for Mark Dela Cruz has been updated with new emergency contact information.',
                'type' => 'patient',
                'category' => 'patient',
                'source' => 'Patients',
                'is_read' => false,
            ],
            [
                'user_id' => $doctor->id,
                'title' => 'Consultation Scheduled',
                'message' => 'You have a scheduled consultation with Mark Dela Cruz (CONS-2026-010).',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Consultations',
                'is_read' => false,
                'metadata' => ['consultation_reference' => 'CONS-2026-010'],
            ],
            [
                'user_id' => $doctor->id,
                'title' => 'Medical Certificate Pending Review',
                'message' => 'A medical certificate request from Angela Reyes (MC-2026-008) is pending your review.',
                'type' => 'clinic',
                'category' => 'medical_certificate',
                'source' => 'Medical Certificates',
                'is_read' => false,
                'metadata' => ['certificate_reference' => 'MC-2026-008'],
            ],
            [
                'user_id' => $nurse->id,
                'title' => 'Appointment Cancellation',
                'message' => 'Appointment APT-2026-008 for Angela Reyes (Follow-up) has been cancelled by the patient.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => true,
                'metadata' => ['appointment_reference' => 'APT-2026-008'],
            ],
            [
                'user_id' => $nurse->id,
                'title' => 'New Patient Registration',
                'message' => 'A new patient, Patricia Mae Garcia, has been registered in the system.',
                'type' => 'patient',
                'category' => 'patient',
                'source' => 'Patients',
                'is_read' => false,
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Clinic Schedule Reminder',
                'message' => "Reminder: The clinic will be closed from Oct 30 to Nov 2 for the All Saints' Day break.",
                'type' => 'system',
                'category' => 'schedule',
                'source' => 'Calendar',
                'is_read' => true,
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Appointment Rescheduled',
                'message' => 'Appointment APT-2026-006 for Susan Clave has been rescheduled.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => false,
                'metadata' => ['appointment_reference' => 'APT-2026-006'],
            ],
        ];

        // Each student only receives notifications about their own records.
        $patientUsers = User::whereNotNull('patient_id')->get();
        foreach ($patientUsers as $patientUser) {
            $appointment = Appointment::where('patient_id', $patientUser->patient_id)
                ->where('status', 'Approved')
                ->orderBy('date')
                ->first();
            if ($appointment) {
                $notifications[] = [
                    'user_id' => $patientUser->id,
                    'title' => 'Appointment Reminder',
                    'message' => "Your appointment {$appointment->reference} with {$appointment->staff} on "
                        .$appointment->date->format('M j').' at '.$appointment->time.' has been approved.',
                    'type' => 'appointment',
                    'category' => 'appointment',
                    'source' => 'Appointments',
                    'is_read' => false,
                    'metadata' => ['appointment_reference' => $appointment->reference],
                ];
            }

            $certificate = MedicalCertificate::where('patient_id', $patientUser->patient_id)
                ->where('status', 'Issued')
                ->orderBy('reference')
                ->first();
            if ($certificate) {
                $notifications[] = [
                    'user_id' => $patientUser->id,
                    'title' => 'Medical Certificate Issued',
                    'message' => "Your medical certificate {$certificate->reference} has been approved and issued.",
                    'type' => 'medical_certificate',
                    'category' => 'medical_certificate',
                    'source' => 'Medical Certificates',
                    'is_read' => false,
                    'metadata' => ['certificate_reference' => $certificate->reference],
                ];
            }

            $prescription = Prescription::where('patient_id', $patientUser->patient_id)
                ->orderBy('reference')
                ->first();
            if ($prescription) {
                $notifications[] = [
                    'user_id' => $patientUser->id,
                    'title' => 'Prescription Available',
                    'message' => "Prescription {$prescription->reference} has been recorded from your recent consultation.",
                    'type' => 'prescription',
                    'category' => 'prescription',
                    'source' => 'Prescriptions',
                    'is_read' => true,
                    'metadata' => ['prescription_reference' => $prescription->reference],
                ];
            }

            $notifications[] = [
                'user_id' => $patientUser->id,
                'title' => 'Clinic Announcement',
                'message' => 'The Flu Vaccination Drive runs from Oct 12 to Oct 16 at the TMC Expansion Clinic.',
                'type' => 'system',
                'category' => 'system',
                'source' => 'Clinic Calendar',
                'is_read' => true,
            ];
        }

        foreach ($notifications as $data) {
            Notification::firstOrCreate([
                'user_id' => $data['user_id'],
                'title' => $data['title'],
            ], $data);
        }
    }
}
