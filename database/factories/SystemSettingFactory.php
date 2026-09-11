<?php

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemSettingFactory extends Factory
{
    protected $model = SystemSetting::class;

    public function definition(): array
    {
        return [
            'clinic_name' => fake()->company() . ' Clinic',
            'clinic_address' => fake()->address(),
            'clinic_phone' => fake()->phoneNumber(),
            'clinic_email' => fake()->safeEmail(),
            'clinic_hours' => '8:00 AM - 5:00 PM',
            'clinic_days' => 'Monday - Friday',
            'clinic_description' => fake()->sentence(),
            'emergency_hotline' => fake()->phoneNumber(),
            'online_appointments_enabled' => fake()->boolean(),
            'appointment_buffer_minutes' => fake()->numberBetween(10, 30),
            'max_daily_appointments' => fake()->numberBetween(20, 100),
        ];
    }
}
