<?php

namespace Database\Seeders;

use App\Models\ClinicEvent;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClinicEventsSeeder extends Seeder
{
    /**
     * Seed clinic events with full calendar data. Idempotent — keyed by title.
     */
    public function run(): void
    {
        $adminId = User::where('email', 'admin@tmc.edu.ph')->value('id');

        $events = [
            [
                'date' => 'Sep 01, 2026',
                'title' => 'Annual Student Physical Checkup Drive',
                'description' => 'Mandatory medical evaluation for incoming first-year college students.',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-05',
                'start_time' => null,
                'end_time' => null,
                'all_day' => true,
                'type' => 'Activity',
                'status' => 'Scheduled',
                'created_by' => $adminId,
            ],
            [
                'date' => 'Sep 10, 2026',
                'title' => 'Campus Blood Donation Campaign',
                'description' => 'Organized in collaboration with the Philippine Red Cross at the gymnasium.',
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-10',
                'start_time' => '8:00 AM',
                'end_time' => '4:00 PM',
                'all_day' => false,
                'type' => 'Activity',
                'status' => 'Scheduled',
                'created_by' => $adminId,
            ],
            [
                'date' => 'Sep 15, 2026',
                'title' => 'Mental Health & Wellness Seminar',
                'description' => 'A seminar on stress management and academic support for college students.',
                'start_date' => '2026-09-15',
                'end_date' => '2026-09-15',
                'start_time' => '1:00 PM',
                'end_time' => '4:00 PM',
                'all_day' => false,
                'type' => 'Seminar',
                'status' => 'Scheduled',
                'created_by' => $adminId,
            ],
            [
                'date' => 'Sep 21, 2026',
                'title' => 'Clinic Staff Meeting',
                'description' => 'Monthly staff meeting to discuss clinic operations and upcoming events.',
                'start_date' => '2026-09-21',
                'end_date' => '2026-09-21',
                'start_time' => '10:00 AM',
                'end_time' => '11:30 AM',
                'all_day' => false,
                'type' => 'Meeting',
                'status' => 'Scheduled',
                'created_by' => $adminId,
            ],
            [
                'date' => 'Oct 15, 2026',
                'title' => 'Flu Vaccination Drive',
                'description' => 'Free flu vaccination for all enrolled students and clinic staff.',
                'start_date' => '2026-10-15',
                'end_date' => '2026-10-17',
                'start_time' => null,
                'end_time' => null,
                'all_day' => true,
                'type' => 'Activity',
                'status' => 'Scheduled',
                'created_by' => $adminId,
            ],
        ];

        foreach ($events as $event) {
            ClinicEvent::firstOrCreate(['title' => $event['title']], $event);
        }
    }
}
