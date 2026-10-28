<?php

namespace App\Modules\Scheduling\Support;

/**
 * The one conversion between API times ("HH:MM") and stored minutes since midnight (research R3,
 * approved D3). 24:00 (1440) is allowed only as an end.
 */
final class ClockTime
{
    /**
     * A start time: 00:00–23:55 on a 5-minute boundary.
     */
    public const START_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][05]$/';

    /**
     * An end time: like a start time, or 24:00 for the end of the day.
     */
    public const END_PATTERN = '/^(([01][0-9]|2[0-3]):[0-5][05]|24:00)$/';

    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    public static function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
