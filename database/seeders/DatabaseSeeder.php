<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SystemSettingSeeder::class);
        $this->call(PatientsSeeder::class);

        $doctorRoleId = Role::where('name', 'doctor')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');

        User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => 'password',
            'role_id' => $doctorRoleId,
        ]);

        User::firstOrCreate(['email' => 'admin@tmc.edu.ph'], [
            'name' => 'TMC Administrator',
            'password' => 'admin123',
            'role_id' => $adminRoleId,
        ]);

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
