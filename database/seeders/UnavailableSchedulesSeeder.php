<?php

namespace Database\Seeders;

use App\Models\UnavailableSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;

class UnavailableSchedulesSeeder extends Seeder
{
    /**
     * Seed blocked/unavailable periods for the clinic calendar.
     */
    public function run(): void
    {
        $adminId = User::where('email', 'admin@tmc.edu.ph')->value('id');

        if (!$adminId) {
            return;
        }

        $blocks = [
            [
                'start_date' => '2026-09-25',
                'end_date' => '2026-09-25',
                'start_time' => null,
                'end_time' => null,
                'all_day' => true,
                'reason' => 'University Foundation Day — clinic closed',
                'created_by' => $adminId,
            ],
            [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-01',
                'start_time' => '12:00 PM',
                'end_time' => '5:00 PM',
                'all_day' => false,
                'reason' => 'System maintenance — no appointments in the afternoon',
                'created_by' => $adminId,
            ],
        ];

        foreach ($blocks as $block) {
            UnavailableSchedule::firstOrCreate(
                ['start_date' => $block['start_date'], 'start_time' => $block['start_time']],
                $block
            );
        }
    }
}
