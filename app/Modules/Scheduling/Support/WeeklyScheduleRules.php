<?php

namespace App\Modules\Scheduling\Support;

/**
 * Rules a weekly schedule's periods must follow beyond their format (research R5, FR-003, FR-004).
 * Pure: no database and no translation; SetScheduleRequest turns each rule key into a message.
 */
final class WeeklyScheduleRules
{
    public const MAX_PERIODS_PER_DAY = 4;

    /**
     * Problems in the periods, in the order found. Overlaps and the fifth period on a day are
     * reported on the period that starts later.
     *
     * @param  list<array{day: string, start: int, end: int}>  $periods  times in minutes since midnight
     * @return list<array{index: int, rule: string, day: string}>
     */
    public static function problems(array $periods, int $appointmentMinutes): array
    {
        $problems = [];

        foreach ($periods as $index => $period) {
            if ($period['end'] <= $period['start']) {
                $problems[] = ['index' => $index, 'rule' => 'period_end_before_start', 'day' => $period['day']];
            } elseif ($period['end'] - $period['start'] < $appointmentMinutes) {
                $problems[] = ['index' => $index, 'rule' => 'period_shorter_than_appointment', 'day' => $period['day']];
            }
        }

        foreach (self::byDay($periods) as $day => $dayPeriods) {
            $previousEnd = null;

            foreach ($dayPeriods as $position => [$index, $period]) {
                if ($position === self::MAX_PERIODS_PER_DAY) {
                    $problems[] = ['index' => $index, 'rule' => 'too_many_periods', 'day' => $day];
                } elseif ($previousEnd !== null && $period['start'] <= $previousEnd) {
                    $problems[] = ['index' => $index, 'rule' => 'periods_overlap', 'day' => $day];
                }

                $previousEnd = max($previousEnd ?? $period['end'], $period['end']);
            }
        }

        return $problems;
    }

    /**
     * The periods grouped by day, each day sorted by start, keeping each period's input index.
     *
     * @param  list<array{day: string, start: int, end: int}>  $periods
     * @return array<string, list<array{0: int, 1: array{day: string, start: int, end: int}}>>
     */
    private static function byDay(array $periods): array
    {
        $days = [];

        foreach ($periods as $index => $period) {
            $days[$period['day']][] = [$index, $period];
        }

        foreach ($days as &$dayPeriods) {
            usort($dayPeriods, fn (array $a, array $b) => [$a[1]['start'], $a[0]] <=> [$b[1]['start'], $b[0]]);
        }

        return $days;
    }
}
