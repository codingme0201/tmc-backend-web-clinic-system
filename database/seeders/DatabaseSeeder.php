<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $staffRoleId = Role::where('name', 'staff')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');

        // Idempotent user creation — keyed by email so re-seeding never
        // trips the unique constraint. Passwords are supplied explicitly
        // (hashed by the model's `hashed` cast) so a fresh database with
        // NOT NULL password columns seeds cleanly.
        User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => 'password',
            'role_id' => $staffRoleId,
        ]);

        // Default TMC administrator — matches the email shown on the login
        // page. The password is hashed by the model's `hashed` cast and is
        // never returned by the API.
        User::firstOrCreate(['email' => 'admin@tmc.edu.ph'], [
            'name' => 'TMC Administrator',
            'password' => 'admin123',
            'role_id' => $adminRoleId,
        ]);

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(AppointmentsSeeder::class);
        $this->call(StaffSeeder::class);
        $this->call(StaffSchedulesSeeder::class);
        $this->call(PatientsSeeder::class);
        $this->call(ConsultationsSeeder::class);
        $this->call(MedicalRecordsSeeder::class);
        $this->call(MedicalCertificatesSeeder::class);
        $this->call(PrescriptionsSeeder::class);
        $this->call(ClinicEventsSeeder::class);
        $this->call(ActivityLogsSeeder::class);
        $this->call(ClinicInsightsSeeder::class);
    }
}
