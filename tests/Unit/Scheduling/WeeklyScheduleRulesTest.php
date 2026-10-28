<?php

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Support\WeeklyScheduleRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rules a weekly schedule's periods must follow (research R5, FR-003, FR-004, SC-004).
 */
class WeeklyScheduleRulesTest extends TestCase
{
    /**
     * @return array<string, array{list<array{day: string, start: int, end: int}>}>
     */
    public static function validSchedules(): array
    {
        return [
            'two periods with a break and another day' => [[
                ['day' => 'sunday', 'start' => 540, 'end' => 780],
                ['day' => 'sunday', 'start' => 1020, 'end' => 1260],
                ['day' => 'tuesday', 'start' => 600, 'end' => 840],
            ]],
            'no periods' => [[]],
            'ending at 24:00' => [[['day' => 'monday', 'start' => 1380, 'end' => 1440]]],
            'same times on different days' => [[
                ['day' => 'sunday', 'start' => 540, 'end' => 780],
                ['day' => 'monday', 'start' => 540, 'end' => 780],
            ]],
            'given out of order' => [[
                ['day' => 'sunday', 'start' => 1020, 'end' => 1260],
                ['day' => 'sunday', 'start' => 540, 'end' => 780],
            ]],
        ];
    }

    /**
     * @param  list<array{day: string, start: int, end: int}>  $periods
     */
    #[DataProvider('validSchedules')]
    public function test_valid_schedules_have_no_problems(array $periods): void
    {
        $this->assertSame([], WeeklyScheduleRules::problems($periods, 20));
    }

    /**
     * @return array<string, array{list<array{day: string, start: int, end: int}>, list<array{index: int, rule: string, day: string}>}>
     */
    public static function invalidSchedules(): array
    {
        return [
            'end before start' => [
                [['day' => 'sunday', 'start' => 600, 'end' => 540]],
                [['index' => 0, 'rule' => 'period_end_before_start', 'day' => 'sunday']],
            ],
            'end equal to start' => [
                [['day' => 'sunday', 'start' => 600, 'end' => 600]],
                [['index' => 0, 'rule' => 'period_end_before_start', 'day' => 'sunday']],
            ],
            'shorter than one appointment' => [
                [['day' => 'monday', 'start' => 540, 'end' => 550]],
                [['index' => 0, 'rule' => 'period_shorter_than_appointment', 'day' => 'monday']],
            ],
            'overlapping, reported on the later one' => [
                [
                    ['day' => 'sunday', 'start' => 700, 'end' => 900],
                    ['day' => 'sunday', 'start' => 540, 'end' => 780],
                ],
                [['index' => 0, 'rule' => 'periods_overlap', 'day' => 'sunday']],
            ],
            'touching' => [
                [
                    ['day' => 'sunday', 'start' => 540, 'end' => 780],
                    ['day' => 'sunday', 'start' => 780, 'end' => 900],
                ],
                [['index' => 1, 'rule' => 'periods_overlap', 'day' => 'sunday']],
            ],
        ];
    }

    /**
     * @param  list<array{day: string, start: int, end: int}>  $periods
     * @param  list<array{index: int, rule: string, day: string}>  $expected
     */
    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_are_reported(array $periods, array $expected): void
    {
        $this->assertSame($expected, WeeklyScheduleRules::problems($periods, 20));
    }

    public function test_a_fifth_period_on_a_day_is_reported_once(): void
    {
        $periods = [
            ['day' => 'monday', 'start' => 1200, 'end' => 1260],
            ['day' => 'monday', 'start' => 540, 'end' => 600],
            ['day' => 'monday', 'start' => 660, 'end' => 720],
            ['day' => 'monday', 'start' => 780, 'end' => 840],
            ['day' => 'monday', 'start' => 900, 'end' => 960],
        ];

        $this->assertSame(
            [['index' => 0, 'rule' => 'too_many_periods', 'day' => 'monday']],
            WeeklyScheduleRules::problems($periods, 20),
        );
    }
}
