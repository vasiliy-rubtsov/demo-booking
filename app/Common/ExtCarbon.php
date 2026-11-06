<?php

namespace App\Common;

use Carbon\Carbon;
use Carbon\Month;
use Carbon\WeekDay;

/**
 * Extended Carbon class with timezone-aware parsing helpers.
 *
 * Provides methods for interpreting date/time strings in a specific timezone,
 * essential for multi-office applications where bookings must be validated
 * against the office's local working hours.
 */
class ExtCarbon extends Carbon
{
    /**
     * Parse a time string in a specific timezone.
     *
     * Parses the given time string and interprets the resulting date/time
     * components (year, month, day, hour, minute, second) in the specified
     * timezone. Returns a new Carbon instance bound to that timezone.
     *
     * Example: "2026-11-05 10:00" parsed with timezone "Asia/Novosibirsk"
     * produces a Carbon instance representing 10:00 in Novosibirsk time,
     * not the app's default timezone.
     *
     * @param  DateTimeInterface|WeekDay|Month|string|int|float  $time  The time string to parse.
     * @param  DateTimeZone|string|int  $timezone  The target timezone.
     * @return static A Carbon instance bound to the given timezone.
     */
    public static function parseWithOtherTimezone(
        DateTimeInterface|WeekDay|Month|string|int|float $time,
        DateTimeZone|string|int $timezone
    ): static {
        $dt = parent::parse($time);

        $year  = $dt->year;
        $month = $dt->month;
        $day   = $dt->day;
        $hour  = $dt->hour;
        $min   = $dt->minute;
        $sec   = $dt->second;

        return static::create($year, $month, $day, $hour, $min, $sec, $timezone);
    }

}
