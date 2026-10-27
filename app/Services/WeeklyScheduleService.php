<?php

namespace App\Services;

use App\Models\StaffSchedule;
use App\Models\WeeklyStaffSchedule;
use App\Support\AppointmentCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Dated occurrences keep all existing schedule consumers using the same records. */
class WeeklyScheduleService
{
    public function ensureRange(string $from, string $to, ?int $userId = null): void
    {
        DB::transaction(function () use ($from, $to, $userId) {
            $rules = WeeklyStaffSchedule::when($userId, fn ($q) => $q->where('user_id', $userId))
                ->orderBy('id')->lockForUpdate()->get();
            if ($rules->isEmpty()) return;

            $calendar = app(AppointmentCalendar::class);
            $workingDays = $calendar->workingDays(\App\Models\SystemSetting::getInstance()->clinic_days);
            $holidays = [];
            $existing = StaffSchedule::whereIn('user_id', $rules->pluck('user_id'))
                ->whereBetween('date', [$from, $to])->get()
                ->groupBy(fn ($s) => $s->user_id.':'.$s->date->toDateString());
            foreach ($rules as $rule) {
                for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                    $date = $day->toDateString();
                    $holidays[$day->year] ??= $calendar->holidays($day->year);
                    if ($day->dayOfWeekIso !== $rule->weekday || $date < $rule->effective_from->toDateString()
                        || in_array($date, $rule->excluded_dates ?? [], true) || $calendar->reason($date, $holidays[$day->year], $workingDays) !== null
                        || $existing->has($rule->user_id.':'.$date)) continue;

                    StaffSchedule::create([
                        'user_id' => $rule->user_id, 'date' => $date,
                        'start_time' => $rule->start_time, 'end_time' => $rule->end_time,
                        'status' => 'Available', 'weekly_staff_schedule_id' => $rule->id,
                    ]);
                }
            }
        });
    }

    /** Replace regular weekdays; preserve all dated exceptions and historical entries. */
    public function save(int $userId, array $days): void
    {
        DB::transaction(function () use ($userId, $days) {
            // Serialize regular-schedule updates for this staff member.
            \App\Models\User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $rules = WeeklyStaffSchedule::where('user_id', $userId)->lockForUpdate()->get();
            foreach ($rules as $rule) {
                if (! in_array($rule->weekday, array_column($days, 'weekday'), true)) {
                    StaffSchedule::where('weekly_staff_schedule_id', $rule->id)->where('is_override', false)
                        ->whereDate('date', '>=', now()->toDateString())->delete();
                    $rule->delete();
                }
            }
            foreach ($days as $day) {
                $rule = WeeklyStaffSchedule::updateOrCreate(['user_id' => $userId, 'weekday' => $day['weekday']], [
                    'start_time' => $day['start_time'], 'end_time' => $day['end_time'],
                    'effective_from' => $rules->firstWhere('weekday', $day['weekday'])?->effective_from ?? now()->toDateString(),
                ]);
                StaffSchedule::where('weekly_staff_schedule_id', $rule->id)->where('is_override', false)
                    ->whereDate('date', '>=', now()->toDateString())
                    ->update(['start_time' => $day['start_time'], 'end_time' => $day['end_time']]);
            }
            $this->ensureRange(now()->toDateString(), now()->addDays(35)->toDateString(), $userId);
            \App\Support\SyncState::bump('staff_schedules');
        });
    }
}
