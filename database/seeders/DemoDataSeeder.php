<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seeds comprehensive demo/mock records for development testing (a lot of example data).
     */
    public function run(): void
    {
        // 1. Foundational System Setup & Administrator
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SystemSettingSeeder::class);
        $this->call(AdminSeeder::class);

        $doctorRoleId = Role::where('name', 'doctor')->value('id');

        User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => 'password',
            'role_id' => $doctorRoleId,
            'status' => 'active',
        ]);

        $this->call(PatientsSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(StaffSeeder::class);
        $this->call(AppointmentsSeeder::class);
        $this->call(ConsultationsSeeder::class);
        $this->call(MedicalRecordsSeeder::class);
        $this->call(MedicalCertificatesSeeder::class);
        $this->call(PrescriptionsSeeder::class);
        $this->call(StaffSchedulesSeeder::class);
        $this->call(ClinicEventsSeeder::class);
        $this->call(UnavailableSchedulesSeeder::class);
        $this->call(ActivityLogsSeeder::class);
        $this->call(ClinicInsightsSeeder::class);
        $this->call(NotificationSeeder::class);
    }
}
