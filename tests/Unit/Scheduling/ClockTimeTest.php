<?php

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Support\ClockTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "HH:MM" API times and stored minutes since midnight (research R3).
 */
class ClockTimeTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function times(): array
    {
        return [
            'midnight' => ['00:00', 0],
            'morning' => ['09:05', 545],
            'last start' => ['23:55', 1435],
            'end of day' => ['24:00', 1440],
        ];
    }

    #[DataProvider('times')]
    public function test_converts_both_ways(string $time, int $minutes): void
    {
        $this->assertSame($minutes, ClockTime::toMinutes($time));
        $this->assertSame($time, ClockTime::fromMinutes($minutes));
    }

    public function test_start_pattern_accepts_only_five_minute_times_before_midnight(): void
    {
        foreach (['09:00', '23:55', '00:00'] as $valid) {
            $this->assertMatchesRegularExpression(ClockTime::START_PATTERN, $valid);
        }

        foreach (['09:03', '24:00', '9:00', '09:60', 'ab:cd'] as $invalid) {
            $this->assertDoesNotMatchRegularExpression(ClockTime::START_PATTERN, $invalid);
        }
    }

    public function test_end_pattern_also_accepts_end_of_day(): void
    {
        foreach (['24:00', '09:00'] as $valid) {
            $this->assertMatchesRegularExpression(ClockTime::END_PATTERN, $valid);
        }

        foreach (['24:05', '09:03'] as $invalid) {
            $this->assertDoesNotMatchRegularExpression(ClockTime::END_PATTERN, $invalid);
        }
    }
}
