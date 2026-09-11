<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'clinic_name', 'clinic_address', 'clinic_phone', 'clinic_email',
    'clinic_hours', 'clinic_days', 'clinic_description', 'emergency_hotline',
    'online_appointments_enabled', 'appointment_buffer_minutes',
    'max_daily_appointments', 'notification_settings',
])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return [
            'online_appointments_enabled' => 'boolean',
            'appointment_buffer_minutes' => 'integer',
            'max_daily_appointments' => 'integer',
            'notification_settings' => 'array',
        ];
    }

    public static function getInstance(): static
    {
        return static::firstOrCreate([], [
            'clinic_name' => 'Trinidad Municipal College Clinic',
            'clinic_hours' => '8:00 AM - 5:00 PM',
            'clinic_days' => 'Monday - Friday',
            'online_appointments_enabled' => true,
            'appointment_buffer_minutes' => 15,
            'max_daily_appointments' => 50,
        ]);
    }
}
