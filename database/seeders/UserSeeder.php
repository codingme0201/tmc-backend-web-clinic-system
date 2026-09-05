<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed realistic administrative and clinic personnel accounts.
     * Idempotent — keyed by email so re-seeding never trips unique constraints.
     */
    public function run(): void
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $doctorRoleId = Role::where('name', 'doctor')->value('id');
        $nurseRoleId = Role::where('name', 'nurse')->value('id');
        $staffRoleId = Role::where('name', 'staff')->value('id');

        $users = [
            [
                'name' => 'Dr. R. Mendoza',
                'email' => 'rmendoza@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $doctorRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse C. Villanueva',
                'email' => 'cvillanueva@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'msantos@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $staffRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Dr. Ana Cruz',
                'email' => 'acruz@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $doctorRoleId,
                'status' => 'active',
            ],
            [
                'name' => 'Nurse Juan Reyes',
                'email' => 'jreyes@tmc.edu.ph',
                'password' => 'password',
                'role_id' => $nurseRoleId,
                'status' => 'inactive',
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(['email' => $user['email']], $user);
        }
    }
}
