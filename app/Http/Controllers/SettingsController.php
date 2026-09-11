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

    public function update(Request $request): SystemSettingResource|JsonResponse
    {
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
