<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinicName' => $this->clinic_name,
            'clinicAddress' => $this->clinic_address,
            'clinicPhone' => $this->clinic_phone,
            'clinicEmail' => $this->clinic_email,
            'clinicHours' => $this->clinic_hours,
            'clinicDays' => $this->clinic_days,
            'clinicDescription' => $this->clinic_description,
            'emergencyHotline' => $this->emergency_hotline,
            'onlineAppointmentsEnabled' => $this->online_appointments_enabled,
            'appointmentBufferMinutes' => $this->appointment_buffer_minutes,
            'maxDailyAppointments' => $this->max_daily_appointments,
            'notificationSettings' => $this->notification_settings,
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
