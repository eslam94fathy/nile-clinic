<?php

namespace App\Modules\Reports\Support;

/**
 * Averages and rates, worked out in integers until the final division, so rounding is exact (research R8).
 */
final class ReportMath
{
    /**
     * The average in minutes, to the nearest whole minute, half up; null with no visits.
     */
    public static function averageMinutes(int $seconds, int $count): ?int
    {
        if ($count === 0) {
            return null;
        }

        return intdiv(2 * $seconds + 60 * $count, 120 * $count);
    }

    /**
     * No-shows as a percentage of the appointments that came due, to one decimal place, half up; null
     * when none did. Cancelled and still-scheduled appointments do not count (US3).
     */
    public static function noShowRate(int $noShow, int $checkedIn, int $completed): ?float
    {
        $base = $noShow + $checkedIn + $completed;

        if ($base === 0) {
            return null;
        }

        return intdiv(2000 * $noShow + $base, 2 * $base) / 10;
    }
}
