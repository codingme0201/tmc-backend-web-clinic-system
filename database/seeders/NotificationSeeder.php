<?php

namespace Database\Seeders;

use App\Models\Notification;
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

        $admin = $users->firstWhere('name', 'Admin User') ?? $users->first();
        $doctor = $users->firstWhere('name', 'Dr. Maria Santos') ?? $users->first();
        $nurse = $users->firstWhere('name', 'Nurse Ana Reyes') ?? $users->last();

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
                'message' => 'Juan Dela Cruz has requested a Check-up appointment for tomorrow at 10:00 AM.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => false,
                'metadata' => ['appointment_reference' => 'APT-2026-001'],
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Appointment Approved',
                'message' => 'Appointment APT-2026-002 for Maria Garcia (Follow-up) has been approved.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => true,
                'metadata' => ['appointment_reference' => 'APT-2026-002'],
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Patient Record Updated',
                'message' => 'Patient profile for Juan Dela Cruz has been updated with new emergency contact information.',
                'type' => 'patient',
                'category' => 'patient',
                'source' => 'Patients',
                'is_read' => false,
            ],
            [
                'user_id' => $doctor->id,
                'title' => 'Consultation Scheduled',
                'message' => 'You have a scheduled consultation with Juan Dela Cruz today at 2:00 PM.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Consultations',
                'is_read' => false,
                'metadata' => ['consultation_reference' => 'CONS-2026-001'],
            ],
            [
                'user_id' => $doctor->id,
                'title' => 'Medical Certificate Pending Review',
                'message' => 'A medical certificate request from Maria Garcia is pending your approval.',
                'type' => 'clinic',
                'category' => 'medical_certificate',
                'source' => 'Medical Certificates',
                'is_read' => false,
            ],
            [
                'user_id' => $nurse->id,
                'title' => 'Appointment Cancellation',
                'message' => 'Appointment APT-2026-003 for Pedro Santos (Dental concern) has been cancelled by the patient.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => true,
                'metadata' => ['appointment_reference' => 'APT-2026-003'],
            ],
            [
                'user_id' => $nurse->id,
                'title' => 'New Patient Registration',
                'message' => 'A new patient, Ana Reyes, has been registered in the system.',
                'type' => 'patient',
                'category' => 'patient',
                'source' => 'Patients',
                'is_read' => false,
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Clinic Schedule Reminder',
                'message' => 'Reminder: The clinic will be closed on Monday for a holiday.',
                'type' => 'system',
                'category' => 'schedule',
                'source' => 'Calendar',
                'is_read' => true,
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Appointment Rescheduled',
                'message' => 'Appointment APT-2026-004 for Pedro Santos has been rescheduled to next week.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => false,
                'metadata' => ['appointment_reference' => 'APT-2026-004'],
            ],
        ];

        // Seed notifications for patient users (such as Angela Reyes / demo user)
        $patientUsers = User::whereNotNull('patient_id')->get();
        foreach ($patientUsers as $patientUser) {
            $notifications[] = [
                'user_id' => $patientUser->id,
                'title' => 'Appointment Reminder',
                'message' => 'You have an upcoming consultation with Dr. R. Mendoza on Sep 22 at 10:00 AM.',
                'type' => 'appointment',
                'category' => 'appointment',
                'source' => 'Appointments',
                'is_read' => false,
                'metadata' => ['appointment_reference' => 'APT-2026-011'],
            ];
            $notifications[] = [
                'user_id' => $patientUser->id,
                'title' => 'Medical Certificate Issued',
                'message' => 'Your medical certificate MC-2026-001 has been approved and issued.',
                'type' => 'medical_certificate',
                'category' => 'medical_certificate',
                'source' => 'Medical Certificates',
                'is_read' => false,
                'metadata' => ['certificate_reference' => 'MC-2026-001'],
            ];
            $notifications[] = [
                'user_id' => $patientUser->id,
                'title' => 'Prescription Available',
                'message' => 'Prescription RX-2026-001 has been recorded from your recent consultation.',
                'type' => 'prescription',
                'category' => 'prescription',
                'source' => 'Prescriptions',
                'is_read' => true,
                'metadata' => ['prescription_reference' => 'RX-2026-001'],
            ];
            $notifications[] = [
                'user_id' => $patientUser->id,
                'title' => 'Clinic Announcement',
                'message' => 'Annual Student Physical Checkup Drive starts on Sep 01 at the TMC Main Clinic.',
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
