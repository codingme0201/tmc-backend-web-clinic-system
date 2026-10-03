<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClinicEvent;
use App\Models\StaffSchedule;
use App\Models\SystemSetting;
use App\Models\UnavailableSchedule;
use App\Models\User;
use App\Support\ClinicSchedule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Booking rules shared by staff booking, student self-service, rescheduling,
 * doctor assignment and follow-up scheduling, so every entry point checks
 * the same calendar, schedule and slot data.
 */
class AppointmentScheduler
{
    /**
     * Return a validation message when the slot cannot be booked, or null.
     */
    public function conflict(
        string $date,
        string $time,
        ?int $staffId = null,
        ?string $staffName = null,
        ?string $patientId = null,
        ?int $ignoreAppointmentId = null,
        bool $enforceDailyLimit = false,
    ): ?string {
        $settings = SystemSetting::getInstance();
        $minutes = ClinicSchedule::toMinutes($time);

        if ($closure = ClinicEvent::nonWorkingOn($date)->first()) {
            return "The clinic is closed on {$date} ({$closure->type}: {$closure->title}). Please choose another date.";
        }

        $blocks = UnavailableSchedule::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();

        if ($blocks->contains(fn ($block) => $this->blockCovers($block, $minutes))) {
            return 'The selected date or time slot is unavailable due to a clinic schedule block.';
        }

        if ($enforceDailyLimit && $this->isDayFull($date, $settings, $ignoreAppointmentId)) {
            return "The clinic has reached its maximum number of appointments for {$date}. Please choose another date.";
        }

        $staffName = trim((string) $staffName);
        if ($staffId || ($staffName !== '' && strtolower($staffName) !== 'any available')) {
            $label = $staffName !== '' ? $staffName : (User::whereKey($staffId)->value('name') ?? 'the selected doctor');

            if ($staffId && $this->isMarkedUnavailable($staffId, $date, $time)) {
                return "{$label} is marked unavailable on {$date} at {$time} in the doctor/nurse schedule.";
            }

            $buffer = (int) $settings->appointment_buffer_minutes;
            $taken = $this->activeAppointmentsOn($date, $ignoreAppointmentId)
                ->where(fn ($q) => $this->whereStaff($q, $staffId, $staffName))
                ->get(['time'])
                ->contains(fn ($a) => $this->timesOverlap($a->time, $time, $buffer));

            if ($taken) {
                return "The selected doctor/staff ({$label}) already has an appointment booked on {$date} at {$time}.";
            }
        }

        if ($patientId) {
            $patientTaken = $this->activeAppointmentsOn($date, $ignoreAppointmentId)
                ->where('patient_id', $patientId)
                ->get(['time'])
                ->contains(fn ($a) => $this->timesOverlap($a->time, $time, 0));

            if ($patientTaken) {
                return "You already have an appointment scheduled for {$date} at {$time}.";
            }
        }

        return null;
    }

    /**
     * Per-slot availability for a date (mobile booking form).
     *
     * @return array<int, array{time: string, available: bool, reason: ?string}>
     */
    public function slotAvailability(string $date, ?User $staff, ?string $patientId): array
    {
        $settings = SystemSetting::getInstance();
        $buffer = (int) $settings->appointment_buffer_minutes;
        $closed = ClinicEvent::nonWorkingOn($date)->exists();
        $blocks = UnavailableSchedule::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();
        $active = $this->activeAppointmentsOn($date)->get(['time', 'staff', 'staff_id', 'patient_id']);
        $isFull = $this->isDayFull($date, $settings);
        $isPast = $date < now()->toDateString();

        return collect(ClinicSchedule::TIME_SLOTS)->map(function (string $slot) use ($closed, $blocks, $active, $staff, $patientId, $buffer, $isFull, $isPast, $date) {
            $minutes = ClinicSchedule::toMinutes($slot);
            $reason = match (true) {
                $isPast => 'past',
                $closed, $blocks->contains(fn ($b) => $this->blockCovers($b, $minutes)) => 'blocked',
                $patientId && $active->contains(fn ($a) => $a->patient_id === $patientId && $this->timesOverlap($a->time, $slot, 0)) => 'yours',
                $staff && $this->isMarkedUnavailable($staff->id, $date, $slot) => 'blocked',
                $staff && $active->contains(fn ($a) => ($a->staff_id === $staff->id || $a->staff === $staff->name) && $this->timesOverlap($a->time, $slot, $buffer)) => 'booked',
                $isFull => 'full',
                default => null,
            };

            return ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
        })->values()->all();
    }

    /**
     * Active doctor/nurse accounts that can be assigned or booked.
     */
    public function clinicians(): Builder
    {
        return User::clinicians();
    }

    /**
     * Whether the clinician has a schedule entry marked Unavailable covering
     * the slot (schedule times are stored in the shared hh:mm AM format).
     */
    public function isMarkedUnavailable(int $staffId, string $date, string $time): bool
    {
        $minutes = ClinicSchedule::toMinutes($time);
        if ($minutes === null) {
            return false;
        }

        return StaffSchedule::where('user_id', $staffId)
            ->whereDate('date', $date)
            ->where('status', 'Unavailable')
            ->get(['start_time', 'end_time'])
            ->contains(function ($entry) use ($minutes) {
                $start = ClinicSchedule::toMinutes($entry->start_time);
                $end = ClinicSchedule::toMinutes($entry->end_time);

                return $start !== null && $end !== null && $minutes >= $start && $minutes < $end;
            });
    }

    /**
     * Active appointments on a date, optionally excluding one appointment.
     */
    public function activeAppointmentsOn(string $date, ?int $ignoreAppointmentId = null): Builder
    {
        return Appointment::whereDate('date', $date)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId));
    }

    public function isDayFull(string $date, SystemSetting $settings, ?int $ignoreAppointmentId = null): bool
    {
        $max = (int) $settings->max_daily_appointments;

        return $max > 0 && $this->activeAppointmentsOn($date, $ignoreAppointmentId)->count() >= $max;
    }

    private function whereStaff(Builder $query, ?int $staffId, string $staffName): Builder
    {
        if ($staffId) {
            $query->where('staff_id', $staffId);
            if ($staffName !== '') {
                $query->orWhere(fn ($q) => $q->whereNull('staff_id')->where('staff', $staffName));
            }

            return $query;
        }

        return $query->where('staff', $staffName);
    }

    /**
     * Whether two appointment times fall within $bufferMinutes of each other
     * (exact match when the buffer is 0).
     */
    private function timesOverlap(?string $a, ?string $b, int $bufferMinutes): bool
    {
        $am = ClinicSchedule::toMinutes($a);
        $bm = ClinicSchedule::toMinutes($b);

        if ($am === null || $bm === null) {
            return trim((string) $a) === trim((string) $b);
        }

        return abs($am - $bm) < max(1, $bufferMinutes);
    }

    /**
     * All-day blocks (or blocks without both times) cover the whole day;
     * timed blocks cover [start, end).
     */
    private function blockCovers(UnavailableSchedule $block, ?int $minutes): bool
    {
        if ($block->all_day || ! $block->start_time || ! $block->end_time) {
            return true;
        }

        $start = ClinicSchedule::toMinutes($block->start_time);
        $end = ClinicSchedule::toMinutes($block->end_time);

        if ($minutes === null || $start === null || $end === null) {
            return true;
        }

        return $minutes >= $start && $minutes < $end;
    }
}
