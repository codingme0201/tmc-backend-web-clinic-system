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
            ['time' => '10:45 AM', 'user' => 'Nurse C. Villanueva', 'action' => 'Logged consultation record for Joanna Lim (BS Education).'],
            ['time' => '10:00 AM', 'user' => 'Dr. R. Mendoza', 'action' => 'Updated medical profile of Angela Reyes (BS Computer Science).'],
            ['time' => '09:20 AM', 'user' => 'Nurse C. Villanueva', 'action' => 'Approved medical certificate request for Joanna Lim.'],
            ['time' => '08:35 AM', 'user' => 'System', 'action' => 'New online appointment requested by Angela Reyes.'],
        ];

        foreach ($entries as $entry) {
            ActivityLog::firstOrCreate(['action' => $entry['action']], [
                ...$entry,
                'user_id' => $staffUsers[$entry['user']] ?? null,
            ]);
        }
    }
}
