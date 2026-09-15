<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Seeder;

class ActivityLogsSeeder extends Seeder
{
    public function run(): void
    {
        $staffUsers = User::pluck('id', 'name');

        $entries = [
            ['time' => '10:45 AM', 'user' => 'Nurse C. Villanueva', 'module' => 'Consultations', 'action' => 'Logged consultation record for Joanna Lim (BS Education).'],
            ['time' => '10:00 AM', 'user' => 'Dr. R. Mendoza', 'module' => 'Medical Records', 'action' => 'Updated medical profile of Angela Reyes (BS Computer Science).'],
            ['time' => '09:20 AM', 'user' => 'Nurse C. Villanueva', 'module' => 'Medical Certificates', 'action' => 'Approved medical certificate request for Joanna Lim.'],
            ['time' => '08:35 AM', 'user' => 'System', 'module' => 'Appointments', 'action' => 'New online appointment requested by Angela Reyes.'],
            ['time' => '08:15 AM', 'user' => 'Dr. R. Mendoza', 'module' => 'Prescriptions', 'action' => 'Created prescription for patient Mark Dela Cruz (BS Nursing).'],
            ['time' => '07:50 AM', 'user' => 'Admin A. Santos', 'module' => 'Users', 'action' => 'Created new user account for Nurse B. Reyes.'],
            ['time' => '07:30 AM', 'user' => 'Admin A. Santos', 'module' => 'Roles & Permissions', 'action' => 'Updated permissions for the Nurse role.'],
            ['time' => '04:45 PM', 'user' => 'Nurse C. Villanueva', 'module' => 'Patients', 'action' => 'Registered new patient record for Sofia Garcia (BS Psychology).'],
            ['time' => '04:20 PM', 'user' => 'Dr. R. Mendoza', 'module' => 'Consultations', 'action' => 'Completed consultation for patient Ethan Bautista. Diagnosis: Upper respiratory infection.'],
            ['time' => '03:55 PM', 'user' => 'Admin A. Santos', 'module' => 'System Settings', 'action' => 'Updated clinic operating hours from 8:00 AM - 5:00 PM to 7:30 AM - 5:00 PM.'],
            ['time' => '03:30 PM', 'user' => 'Nurse C. Villanueva', 'module' => 'Appointments', 'action' => 'Rescheduled appointment for Sofia Garcia to September 15, 2026.'],
            ['time' => '02:45 PM', 'user' => 'Dr. R. Mendoza', 'module' => 'Medical Certificates', 'action' => 'Issued medical certificate for Ethan Bautista (Fitness to Engage in Sports).'],
            ['time' => '02:00 PM', 'user' => 'Admin A. Santos', 'module' => 'Notifications', 'action' => 'Sent system-wide notification about clinic schedule on September 15.'],
            ['time' => '01:30 PM', 'user' => 'Nurse C. Villanueva', 'module' => 'Consultations', 'action' => 'Started consultation session with patient Sofia Garcia.'],
            ['time' => '12:00 PM', 'user' => 'System', 'module' => 'System', 'action' => 'Daily backup completed successfully.'],
        ];

        foreach ($entries as $entry) {
            ActivityLog::create([
                ...$entry,
                'user_id' => $staffUsers[$entry['user']] ?? null,
            ]);
        }
    }
}
