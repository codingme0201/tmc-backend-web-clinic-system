<?php

namespace Database\Seeders;

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
