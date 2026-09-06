<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $roster = [
            ['name' => 'Dr. R. Mendoza', 'role' => 'School Physician', 'shift' => '8:00 AM - 4:00 PM', 'status' => 'On duty'],
            ['name' => 'Dr. S. Lopez', 'role' => 'School Dentist', 'shift' => '9:00 AM - 3:00 PM', 'status' => 'Break'],
            ['name' => 'Nurse C. Villanueva', 'role' => 'Clinic Nurse', 'shift' => '7:30 AM - 3:30 PM', 'status' => 'On duty'],
            ['name' => 'Nurse J. Santos', 'role' => 'Medical Assistant', 'shift' => '10:00 AM - 6:00 PM', 'status' => 'Off duty'],
        ];

        foreach ($roster as $member) {
            $user = User::where('name', $member['name'])->first();
            Staff::firstOrCreate(['name' => $member['name']], [
                ...$member,
                'user_id' => $user?->id,
            ]);
        }
    }
}
