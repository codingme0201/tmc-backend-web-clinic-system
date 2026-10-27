<?php

namespace App\Services;

use App\Models\Appointment;
use App\Support\ClinicSchedule;
use App\Support\SyncState;
use Carbon\CarbonImmutable;

class AppointmentAutoStatus
{
    /** Persist overdue statuses using clinic local time; safe to run repeatedly or concurrently. */
    public function refresh(): int
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $changed = 0;

        try {
            $candidates = Appointment::query()
                ->whereIn('status', ['Pending', 'Approved'])
                ->whereNull('checked_in_at')
                ->where('date', '<=', $now->toDateString())
                ->lazyById(200);

            foreach ($candidates as $appointment) {
                $minutes = ClinicSchedule::toMinutes($appointment->time);
                // Do not guess the scheduled time of malformed legacy entries.
                if ($minutes === null) continue;
                $date = $appointment->date->format('Y-m-d');
                $scheduled = CarbonImmutable::parse($date, config('app.timezone'))
                    ->startOfDay()->addMinutes($minutes);
                if (! $scheduled->lessThan($now)) continue;

                // Compare-and-set: never overwrite a concurrent approval, check-in,
                // completion, cancellation or reschedule with an older snapshot.
                $changed += Appointment::query()->whereKey($appointment->id)
                    ->where('status', $appointment->status)
                    ->whereNull('checked_in_at')
                    ->where('date', $date)
                    ->where('time', $appointment->time)
                    ->update(['status' => $appointment->status === 'Pending' ? 'Expired' : 'No-Show']);
            }
        } finally {
            // Query updates bypass model events. Publish the change explicitly so
            // open dashboards, reports, queues and booking calendars refresh.
            if ($changed > 0) SyncState::bump('appointments');
        }

        return $changed;
    }
}
