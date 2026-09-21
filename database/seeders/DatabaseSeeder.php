<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's starting database.
     * Seeds essential beginning data:
     * - Roles and permissions catalog
     * - Clinic system settings
     * - Primary administrator account
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SystemSettingSeeder::class);
        $this->call(AdminSeeder::class);
        $this->call(PatientsSeeder::class);
        $this->call(UserSeeder::class);
    }
}
