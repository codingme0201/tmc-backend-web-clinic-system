<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Seed the primary Administrator account for TMC CareLink.
     * Idempotent — keyed by email.
     */
    public function run(): void
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');

        User::firstOrCreate(
            ['email' => 'admin@tmc.edu.ph'],
            [
                'name' => 'TMC Administrator',
                'password' => 'admin123',
                'role_id' => $adminRoleId,
                'status' => 'active',
            ]
        );
    }
}
