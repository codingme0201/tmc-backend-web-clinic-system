<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\StaffSchedule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ClinicDepartments;
use App\Support\ClinicSchedule;
use App\Support\AppointmentCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

/**
 * Booking rules shared by staff booking, student self-service, rescheduling,
 * doctor assignment and follow-up scheduling, so every entry point checks
 * the same doctor/nurse schedule and slot data.
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
        bool $enforceBookingWindow = true,
    ): ?string {
        if ($enforceBookingWindow && ($reason = app(AppointmentCalendar::class)->reason($date))) {
            return "{$reason}. Appointments cannot be booked on {$date}. Please choose a working day.";
        }
        if ($enforceBookingWindow && ClinicSchedule::isSlotClosed($date, $time)) {
            return "The {$time} slot on {$date} is no longer available. Same-day appointments can only be booked until "
                .ClinicSchedule::BOOKING_CUTOFF.' and for times that have not yet passed.';
        }

        $settings = SystemSetting::getInstance();

        if (($enforceDailyLimit || $staffId) && $this->isDayFull($date, $settings, $ignoreAppointmentId)) {
            return "The clinic has reached its maximum number of appointments for {$date}. Please choose another date.";
        }

        $staffName = trim((string) $staffName);
        if ($staffId || ($staffName !== '' && strtolower($staffName) !== 'any available')) {
            $label = $staffName !== '' ? $staffName : (User::whereKey($staffId)->value('name') ?? 'the selected doctor');

            if ($staffId && $this->isMarkedUnavailable($staffId, $date, $time)) {
                return "{$label} is marked unavailable on {$date} at {$time} in the doctor/nurse schedule.";
            }
            if ($staffId && ! $this->hasAvailableShift($staffId, $date, $time)) {
                return 'Doctor not available';
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
        if ($staff) app(WeeklyScheduleService::class)->ensureRange($date, $date, $staff->id);
        $settings = SystemSetting::getInstance();
        $active = $this->activeAppointmentsOn($date)->get(['time', 'staff', 'staff_id', 'patient_id']);
        $shifts = $staff ? StaffSchedule::where('user_id', $staff->id)->whereDate('date', $date)->get() : collect();

        return $this->slotsFor($date, $staff, $patientId, $active, $shifts, $settings, app(AppointmentCalendar::class)->reason($date));
    }

    /** Load a doctor's calendar in two queries, without exposing patient data. */
    public function monthAvailability(User $staff, int $year, int $month): array
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $last = $first->endOfMonth();
        app(WeeklyScheduleService::class)->ensureRange($first->toDateString(), $last->toDateString(), $staff->id);
        $shifts = StaffSchedule::where('user_id', $staff->id)->whereBetween('date', [$first->toDateString(), $last->toDateString()])->get()->groupBy(fn ($s) => $s->date->toDateString());
        $appointments = Appointment::whereBetween('date', [$first->toDateString(), $last->toDateString()])->whereIn('status', Appointment::ACTIVE_STATUSES)->get(['date', 'time', 'staff', 'staff_id', 'patient_id'])->groupBy(fn ($a) => $a->date->toDateString());
        $settings = SystemSetting::getInstance();
        $closures = (array) app(AppointmentCalendar::class)->year($year)['closedDates'];
        $days = [];
        for ($day = $first; $day->month === $month; $day = $day->addDay()) {
            $date = $day->toDateString();
            $slots = $this->slotsFor($date, $staff, null, $appointments->get($date, collect()), $shifts->get($date, collect()), $settings, $closures[$date] ?? null);
            $available = collect($slots)->contains('available', true);
            $days[$date] = ['available' => $available, 'reason' => $available ? null : ($closures[$date] ?? 'Doctor not available'), 'slots' => $slots];
        }

        return $days;
    }

    private function slotsFor(string $date, ?User $staff, ?string $patientId, Collection $active, Collection $shifts, SystemSetting $settings, ?string $closedReason): array
    {
        $buffer = (int) $settings->appointment_buffer_minutes;
        $isFull = $settings->max_daily_appointments > 0 && $active->count() >= $settings->max_daily_appointments;
        $isPast = $date < now()->toDateString();

        return collect(ClinicSchedule::TIME_SLOTS)->map(function (string $slot) use ($active, $staff, $patientId, $buffer, $isFull, $isPast, $date, $closedReason, $shifts) {
            $reason = match (true) {
                $closedReason !== null => $closedReason,
                $isPast, ClinicSchedule::isSlotClosed($date, $slot) => 'past',
                $patientId && $active->contains(fn ($a) => $a->patient_id === $patientId && $this->timesOverlap($a->time, $slot, 0)) => 'yours',
                $staff && $shifts->contains(fn ($s) => $s->status === 'Unavailable' && $this->shiftOverlapsSlot($s, $slot)) => 'blocked',
                $staff && ! $shifts->contains(fn ($s) => $s->status === 'Available' && $this->shiftCovers($s, $slot)) => 'not_scheduled',
                $staff && $active->contains(fn ($a) => ($a->staff_id === $staff->id || ($a->staff_id === null && $a->staff === $staff->name)) && $this->timesOverlap($a->time, $slot, $buffer)) => 'booked',
                $isFull => 'full',
                default => null,
            };

            return ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
        })->values()->all();
    }

    public function hasAvailableShift(int $staffId, string $date, string $time): bool
    {
        if (app(AppointmentCalendar::class)->reason($date) !== null || ! $this->clinicians()->whereKey($staffId)->exists()) return false;
        app(WeeklyScheduleService::class)->ensureRange($date, $date, $staffId);
        $shifts = StaffSchedule::where('user_id', $staffId)->whereDate('date', $date)->get();
        if ($shifts->contains(fn ($shift) => $shift->status === 'Unavailable' && $this->shiftOverlapsSlot($shift, $time))) return false;

        return $shifts->where('status', 'Available')
            ->contains(fn ($shift) => $this->shiftCovers($shift, $time));
    }

    /** Live status is derived from saved shifts; future shifts and absence overrides stay intact. */
    public function currentStaffStatus(User $staff, ?Collection $todayShifts = null): string
    {
        $date = now()->toDateString();
        if ($staff->status !== 'active' || ! $staff->isClinician() || $staff->isCredentialRejected()
            || app(AppointmentCalendar::class)->reason($date) !== null) return 'Unavailable';

        if ($todayShifts === null) {
            app(WeeklyScheduleService::class)->ensureRange($date, $date, $staff->id);
            $todayShifts = StaffSchedule::where('user_id', $staff->id)->whereDate('date', $date)->get();
        }
        $time = now()->format('h:i A');
        if ($todayShifts->contains(fn ($s) => $s->status === 'Unavailable' && $this->shiftCovers($s, $time, false))) return 'Unavailable';

        return $todayShifts->contains(fn ($s) => $s->status === 'Available' && $this->shiftCovers($s, $time, false))
            ? 'Available' : 'Unavailable';
    }

    private function shiftOverlapsSlot(StaffSchedule $shift, string $time): bool
    {
        $minutes = ClinicSchedule::toMinutes($time);

        return $minutes !== null && ClinicSchedule::rangesOverlap($shift->start_time, $shift->end_time, $time,
            ClinicSchedule::fromMinutes($minutes + ClinicSchedule::SLOT_MINUTES));
    }

    private function shiftCovers(StaffSchedule $shift, string $time, bool $wholeSlot = true): bool
    {
        $start = ClinicSchedule::toMinutes($shift->start_time);
        $end = ClinicSchedule::toMinutes($shift->end_time);
        $slot = ClinicSchedule::toMinutes($time);

        return $start !== null && $end !== null && $slot !== null && $slot >= $start && ($wholeSlot ? $slot + ClinicSchedule::SLOT_MINUTES <= $end : $slot < $end);
    }

    /**
     * Active doctor/nurse accounts that can be assigned or booked.
     */
    public function clinicians(): Builder
    {
        return User::clinicians();
    }

    /**
     * Why the clinician cannot take a visit of the given service type, or
     * null when they can: the account must be an active, non-rejected
     * doctor (or nurse when allowed) from the visit's department.
     */
    public function staffAssignmentError(?User $staff, ?string $type, bool $doctorsOnly = false): ?string
    {
        $eligible = $staff
            ? ($doctorsOnly ? User::assignableDoctors() : User::clinicians())->whereKey($staff->id)->exists()
            : false;

        if (! $eligible) {
            if ($staff?->isCredentialRejected()) {
                return "{$staff->name}'s medical staff application was rejected, so they cannot be assigned to patients.";
            }

            return $doctorsOnly ? 'Please select an active doctor.' : 'Please select an active doctor or nurse.';
        }

        $required = ClinicDepartments::forType($type);
        $department = $staff->clinicalDepartment();
        if ($type !== null && $department !== $required) {
            return "{$staff->name} belongs to the {$department} department and cannot be assigned to a {$required} visit ({$type}).";
        }

        return null;
    }

    /**
     * Whether the clinician has a schedule entry marked Unavailable covering
     * the slot (schedule times are stored in the shared hh:mm AM format).
     */
    public function isMarkedUnavailable(int $staffId, string $date, string $time): bool
    {
        app(WeeklyScheduleService::class)->ensureRange($date, $date, $staffId);
        return StaffSchedule::where('user_id', $staffId)
            ->whereDate('date', $date)
            ->where('status', 'Unavailable')
            ->get(['start_time', 'end_time'])
            ->contains(fn ($entry) => $this->shiftOverlapsSlot($entry, $time));
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
}
