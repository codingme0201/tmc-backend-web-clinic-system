<?php

namespace App\Http\Controllers;

use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): SystemSettingResource
    {
        return new SystemSettingResource(SystemSetting::getInstance());
    }

    /**
     * Public clinic details for the student mobile app.
     *
     * Internal configuration (notification settings, booking buffer, daily
     * cap) stays on the staff-only /settings endpoint.
     */
    public function publicInfo(): JsonResponse
    {
        $settings = SystemSetting::getInstance();

        return response()->json([
            'data' => [
                'clinicName' => $settings->clinic_name,
                'clinicAddress' => $settings->clinic_address,
                'clinicPhone' => $settings->clinic_phone,
                'clinicEmail' => $settings->clinic_email,
                'clinicHours' => $settings->clinic_hours,
                'clinicDays' => $settings->clinic_days,
                'clinicDescription' => $settings->clinic_description,
                'emergencyHotline' => $settings->emergency_hotline,
                'onlineAppointmentsEnabled' => (bool) $settings->online_appointments_enabled,
            ],
        ]);
    }

    public function update(Request $request): SystemSettingResource|JsonResponse
    {
        $mappings = [
            'clinicName' => 'clinic_name',
            'clinicAddress' => 'clinic_address',
            'clinicPhone' => 'clinic_phone',
            'clinicEmail' => 'clinic_email',
            'clinicHours' => 'clinic_hours',
            'clinicDays' => 'clinic_days',
            'clinicDescription' => 'clinic_description',
            'emergencyHotline' => 'emergency_hotline',
            'onlineAppointmentsEnabled' => 'online_appointments_enabled',
            'appointmentBufferMinutes' => 'appointment_buffer_minutes',
            'maxDailyAppointments' => 'max_daily_appointments',
            'notificationSettings' => 'notification_settings',
        ];

        $input = $request->all();
        foreach ($mappings as $camel => $snake) {
            if (array_key_exists($camel, $input) && !array_key_exists($snake, $input)) {
                $input[$snake] = $input[$camel];
            }
        }
        $request->merge($input);

        $validated = $request->validate([
            'clinic_name' => ['sometimes', 'string', 'max:255'],
            'clinic_address' => ['nullable', 'string', 'max:500'],
            'clinic_phone' => ['nullable', 'string', 'max:50'],
            'clinic_email' => ['nullable', 'email', 'max:255'],
            'clinic_hours' => ['sometimes', 'string', 'max:100'],
            'clinic_days' => ['sometimes', 'string', 'max:100'],
            'clinic_description' => ['nullable', 'string', 'max:1000'],
            'emergency_hotline' => ['nullable', 'string', 'max:50'],
            'online_appointments_enabled' => ['sometimes', 'boolean'],
            'appointment_buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'max_daily_appointments' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'notification_settings' => ['nullable', 'array'],
        ]);

        $settings = SystemSetting::getInstance();
        $settings->update($validated);

        return new SystemSettingResource($settings);
    }
}
