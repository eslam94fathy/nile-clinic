<?php

namespace App\Modules\Scheduling\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Cuts a doctor's working periods into bookable slots (research R6, FR-008, FR-009). Pure: the
 * caller passes the schedule, time off, and the clinic's current time, whose zone is used for
 * every date. Nothing is stored.
 */
final class SlotCalculator
{
    /**
     * Longest slots request, in days including both ends (FR-010).
     */
    public const MAX_RANGE_DAYS = 31;

    /**
     * How far ahead slots can be requested, in days after today (FR-010).
     */
    public const HORIZON_DAYS = 90;

    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $periodsByWeekday  weekday (0 = Sunday) => [start, end] in minutes
     * @param  list<array{start_date: string, end_date: string, start_minute: ?int, end_minute: ?int}>  $timeOff
     * @param  string  $from  first date, Y-m-d
     * @param  string  $to  last date, Y-m-d (inclusive)
     * @param  CarbonImmutable  $now  the clinic's current time, in the clinic's zone
     * @return list<array{date: string, start: string, end: string}>
     */
    public static function slots(array $periodsByWeekday, int $appointmentMinutes, array $timeOff, string $from, string $to, CarbonImmutable $now): array
    {
        $timezone = $now->getTimezone();
        $nowWallClock = $now->format('Y-m-d H:i');
        $slots = [];

        for ($date = CarbonImmutable::parse($from, $timezone); $date->toDateString() <= $to; $date = $date->addDay()) {
            $day = $date->toDateString();
            $periods = $periodsByWeekday[$date->dayOfWeek] ?? [];
            usort($periods, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $offWindows = self::offWindowsOn($day, $timeOff);

            if ($offWindows === null) {
                continue;
            }

            foreach ($periods as [$periodStart, $periodEnd]) {
                for ($start = $periodStart; $start + $appointmentMinutes <= $periodEnd; $start += $appointmentMinutes) {
                    $end = $start + $appointmentMinutes;
                    $startTime = ClockTime::fromMinutes($start);

                    if (self::overlapsAny($start, $end, $offWindows)
                        || "{$day} {$startTime}" <= $nowWallClock
                        || ! self::existsOn($day, $startTime, $timezone)) {
                        continue;
                    }

                    $slots[] = ['date' => $day, 'start' => $startTime, 'end' => ClockTime::fromMinutes($end)];
                }
            }
        }

        return $slots;
    }

    /**
     * The partial time off windows on this date, or null when a whole-day entry covers it.
     *
     * @param  list<array{start_date: string, end_date: string, start_minute: ?int, end_minute: ?int}>  $timeOff
     * @return list<array{0: int, 1: int}>|null
     */
    private static function offWindowsOn(string $day, array $timeOff): ?array
    {
        $windows = [];

        foreach ($timeOff as $entry) {
            if ($entry['start_date'] > $day || $entry['end_date'] < $day) {
                continue;
            }

            if ($entry['start_minute'] === null) {
                return null;
            }

            $windows[] = [$entry['start_minute'], $entry['end_minute']];
        }

        return $windows;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     */
    private static function overlapsAny(int $start, int $end, array $windows): bool
    {
        foreach ($windows as [$offStart, $offEnd]) {
            if ($start < $offEnd && $end > $offStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * False for a wall-clock time skipped by a daylight-saving change on that date (research R2).
     */
    private static function existsOn(string $day, string $time, DateTimeZone $timezone): bool
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$day} {$time}", $timezone)->format('H:i') === $time;
    }
}
