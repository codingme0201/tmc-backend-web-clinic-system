<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        SystemSetting::firstOrCreate([], [
            'clinic_name' => 'Trinidad Municipal College Clinic',
            'clinic_address' => 'Tagum Norte, Trinidad, Bohol, Philippines',
            'clinic_phone' => '+63 42 123 4567',
            'clinic_email' => 'clinic@trinidadmc.edu.ph',
            'clinic_hours' => '8:00 AM - 5:00 PM',
            'clinic_days' => 'Monday - Friday',
            'clinic_description' => 'Primary healthcare facility serving students and clinic personnel of Trinidad Municipal College.',
            'emergency_hotline' => '+63 917 123 4567',
            'online_appointments_enabled' => true,
            'appointment_buffer_minutes' => 15,
            'max_daily_appointments' => 50,
        ]);
    }
}
