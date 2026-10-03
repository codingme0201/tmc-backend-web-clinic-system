<?php

namespace App\Support;

/**
 * Single source of truth for clinic time settings.
 *
 * Appointments, consultations, the queue, staff schedules and calendar
 * entries all use the same "hh:mm AM/PM" format and the same 30-minute
 * slot grid, so an 08:00 AM appointment is exactly the 08:00 AM
 * consultation. Working hours start at 8:00 AM.
 */
class ClinicSchedule
{
    public const WORK_START = '08:00 AM';

    public const WORK_END = '05:00 PM';

    public const LUNCH_START = '12:00 PM';

    public const LUNCH_END = '01:00 PM';

    public const SLOT_MINUTES = 30;

    /**
     * Bookable patient slots (appointments and consultations).
     */
    public const TIME_SLOTS = [
        '08:00 AM', '08:30 AM', '09:00 AM', '09:30 AM', '10:00 AM', '10:30 AM',
        '11:00 AM', '11:30 AM', '01:00 PM', '01:30 PM', '02:00 PM', '02:30 PM',
        '03:00 PM', '03:30 PM', '04:00 PM', '04:30 PM',
    ];

    /**
     * Start/end options for staff shifts and calendar entries.
     */
    public static function shiftTimes(): array
    {
        $times = [];
        for ($m = self::toMinutes(self::WORK_START); $m <= self::toMinutes(self::WORK_END); $m += self::SLOT_MINUTES) {
            $times[] = self::fromMinutes($m);
        }

        return $times;
    }

    /**
     * Convert "01:30 PM", "1:30 PM", "13:30" or "13:30:00" to minutes after
     * midnight, or null when the value cannot be parsed.
     */
    public static function toMinutes(?string $time): ?int
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $parsed = date_parse(trim($time));
        if ($parsed['error_count'] > 0 || $parsed['hour'] === false) {
            return null;
        }

        return $parsed['hour'] * 60 + (int) $parsed['minute'];
    }

    public static function fromMinutes(int $minutes): string
    {
        $hour = intdiv($minutes, 60) % 24;
        $minute = $minutes % 60;
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%02d:%02d %s', $displayHour, $minute, $suffix);
    }

    /**
     * Normalize any parseable time to the canonical "hh:mm AM/PM" format.
     */
    public static function normalize(?string $time): ?string
    {
        $minutes = self::toMinutes($time);

        return $minutes === null ? $time : self::fromMinutes($minutes);
    }

    public static function isSlot(?string $time): bool
    {
        return in_array(self::normalize($time), self::TIME_SLOTS, true);
    }

    /**
     * Whether the time falls inside working hours (8:00 AM – 5:00 PM).
     */
    public static function withinWorkingHours(?string $time): bool
    {
        $minutes = self::toMinutes($time);

        return $minutes !== null
            && $minutes >= self::toMinutes(self::WORK_START)
            && $minutes <= self::toMinutes(self::WORK_END);
    }

    /**
     * The slot a walk-in arriving now belongs to: the latest slot at or
     * before the current time, clamped to the first/last slot of the day.
     */
    public static function currentSlot(?int $minutesNow = null): string
    {
        $minutesNow ??= (int) now()->format('G') * 60 + (int) now()->format('i');
        $current = self::TIME_SLOTS[0];

        foreach (self::TIME_SLOTS as $slot) {
            if (self::toMinutes($slot) <= $minutesNow) {
                $current = $slot;
            }
        }

        return $current;
    }

    /**
     * Whether [startA, endA) and [startB, endB) overlap.
     */
    public static function rangesOverlap(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        $a1 = self::toMinutes($startA);
        $a2 = self::toMinutes($endA);
        $b1 = self::toMinutes($startB);
        $b2 = self::toMinutes($endB);

        if ($a1 === null || $a2 === null || $b1 === null || $b2 === null) {
            return false;
        }

        return $a1 < $b2 && $b1 < $a2;
    }
}
