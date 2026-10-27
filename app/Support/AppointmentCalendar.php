<?php

namespace App\Support;

use App\Models\SystemSetting;
use Carbon\CarbonImmutable;

class AppointmentCalendar
{
    public function holidays(int $year): array
    {
        $holidays = [];
        foreach ([
            '01-01' => "New Year's Day", '04-09' => 'Araw ng Kagitingan',
            '05-01' => 'Labor Day', '06-12' => 'Independence Day',
            '08-21' => 'Ninoy Aquino Day', '11-01' => "All Saints' Day",
            '11-30' => 'Bonifacio Day', '12-08' => 'Feast of the Immaculate Conception of Mary',
            '12-25' => 'Christmas Day', '12-30' => 'Rizal Day', '12-31' => 'Last Day of the Year',
        ] as $day => $name) {
            $holidays["{$year}-{$day}"] = $name;
        }
        // Gregorian Easter (Meeus/Jones/Butcher); no PHP calendar extension needed.
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;
        $easter = CarbonImmutable::create($year, intdiv($n, 31), $n % 31 + 1);
        $holidays[$easter->subDays(3)->toDateString()] = 'Maundy Thursday';
        $holidays[$easter->subDays(2)->toDateString()] = 'Good Friday';
        $heroes = CarbonImmutable::create($year, 8, 31);
        while (! $heroes->isMonday()) {
            $heroes = $heroes->subDay();
        }
        $holidays[$heroes->toDateString()] = 'National Heroes Day';

        return array_replace($holidays, config("appointment_calendar.holidays.{$year}", []));
    }

    /** Accept the existing clinic-days setting: ranges or comma-separated days. */
    public function workingDays(?string $description): array
    {
        $description = strtolower(trim((string) $description));
        if (in_array($description, ['closed', 'none'], true)) {
            return [];
        }
        $days = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $description = str_replace(['–', '—'], '-', $description);
        preg_match_all('/(sun(?:day)?|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?)(?:\s*(?:-|to)\s*(sun(?:day)?|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?))?/', $description, $matches, PREG_SET_ORDER);
        $working = [];
        foreach ($matches as $match) {
            $start = $days[substr($match[1], 0, 3)];
            $end = isset($match[2]) ? $days[substr($match[2], 0, 3)] : $start;
            for ($day = $start; ; $day = ($day + 1) % 7) {
                $working[] = $day;
                if ($day === $end) break;
            }
        }

        return $matches ? array_values(array_unique($working)) : [1, 2, 3, 4, 5];
    }

    public function reason(string $date, ?array $holidays = null, ?array $workingDays = null): ?string
    {
        $day = CarbonImmutable::parse($date);
        $holidays ??= $this->holidays($day->year);
        if (isset($holidays[$date])) return 'Holiday: '.$holidays[$date];
        if ($day->isWeekend()) return 'Weekend';
        if (array_key_exists($date, config('appointment_calendar.closed_dates', []))) return 'Clinic Closed';
        $workingDays ??= $this->workingDays(SystemSetting::getInstance()->clinic_days);

        return in_array($day->dayOfWeek, $workingDays, true) ? null : 'Clinic Closed';
    }

    public function year(int $year): array
    {
        $holidays = $this->holidays($year);
        $workingDays = $this->workingDays(SystemSetting::getInstance()->clinic_days);
        $closed = [];
        for ($day = CarbonImmutable::create($year, 1, 1); $day->year === $year; $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($reason = $this->reason($date, $holidays, $workingDays)) $closed[$date] = $reason;
        }

        return ['year' => $year, 'closedDates' => (object) $closed];
    }
}
