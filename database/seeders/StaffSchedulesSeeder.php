<?php

namespace Database\Seeders;

use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSchedulesSeeder extends Seeder
{
    /**
     * Seed realistic staff schedules for doctors and nurses.
     * Idempotent — checks for existing schedules before creating.
     */
    public function run(): void
    {
        // Get eligible staff (doctors and nurses)
        $doctors = User::whereHas('role', fn ($q) => $q->where('name', 'doctor'))
            ->where('status', 'active')
            ->get();

        $nurses = User::whereHas('role', fn ($q) => $q->where('name', 'nurse'))
            ->where('status', 'active')
            ->get();

        $staff = $doctors->concat($nurses);

        if ($staff->isEmpty()) {
            return;
        }

        // Seed schedules for the current week and next week
        $today = now()->startOfWeek();
        $schedules = [];

        foreach ($staff as $member) {
            // Each staff member gets 5 weekday schedules (Mon-Fri)
            for ($day = 0; $day < 5; $day++) {
                $date = $today->copy()->addDays($day);

                // Doctors: 8 AM - 4 PM or 9 AM - 5 PM
                // Nurses: 7:30 AM - 3:30 PM or 10 AM - 6 PM
                if ($member->role->name === 'doctor') {
                    $start = $day % 2 === 0 ? '8:00 AM' : '9:00 AM';
                    $end = $day % 2 === 0 ? '4:00 PM' : '5:00 PM';
                } else {
                    $start = $day % 2 === 0 ? '7:30 AM' : '10:00 AM';
                    $end = $day % 2 === 0 ? '3:30 PM' : '6:00 PM';
                }

                $schedules[] = [
                    'user_id' => $member->id,
                    'date' => $date->format('Y-m-d'),
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => 'Available',
                    'notes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insert using firstOrCreate pattern to avoid duplicates
        foreach ($schedules as $schedule) {
            StaffSchedule::firstOrCreate(
                [
                    'user_id' => $schedule['user_id'],
                    'date' => $schedule['date'],
                    'start_time' => $schedule['start_time'],
                ],
                $schedule
            );
        }

        // Add a couple of unavailable entries for demo purposes
        $firstDoctor = $doctors->first();
        if ($firstDoctor) {
            $nextMonday = $today->copy()->addWeek();
            StaffSchedule::firstOrCreate(
                [
                    'user_id' => $firstDoctor->id,
                    'date' => $nextMonday->format('Y-m-d'),
                    'start_time' => '1:00 PM',
                ],
                [
                    'end_time' => '5:00 PM',
                    'status' => 'Unavailable',
                    'notes' => 'Personal appointment',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
