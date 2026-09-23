<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminOnlySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed 1 Administrator only alongside foundational roles and system settings.
     * Essential clean startup data:
     * - Roles & Permissions catalog
     * - Clinic System Settings
     * - 1 Primary Administrator account (admin@tmc.edu.ph)
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SystemSettingSeeder::class);
        $this->call(AdminSeeder::class);
    }
}
