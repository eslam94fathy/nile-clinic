<?php

namespace Tests\Unit\Reports;

use App\Modules\Reports\Support\ReportMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Averages and rates are worked out in integers, so rounding is exact and predictable (research R8).
 */
class ReportMathTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int|null}>
     */
    public static function averages(): array
    {
        return [
            'half a minute rounds up' => [90, 1, 2],
            'just under rounds down' => [89, 1, 1],
            'across visits' => [300, 2, 3],
            'no time' => [0, 3, 0],
            'no visits' => [500, 0, null],
        ];
    }

    #[DataProvider('averages')]
    public function test_average_minutes(int $seconds, int $count, ?int $expected): void
    {
        $this->assertSame($expected, ReportMath::averageMinutes($seconds, $count));
    }

    /**
     * @return array<string, array{int, int, int, float|null}>
     */
    public static function rates(): array
    {
        return [
            'one in eight' => [1, 7, 0, 12.5],
            'a third' => [1, 2, 0, 33.3],
            'two thirds' => [2, 1, 0, 66.7],
            'none' => [0, 2, 3, 0.0],
            'all' => [5, 0, 0, 100.0],
            'nothing to divide by' => [0, 0, 0, null],
        ];
    }

    #[DataProvider('rates')]
    public function test_no_show_rate(int $noShow, int $checkedIn, int $completed, ?float $expected): void
    {
        $this->assertSame($expected, ReportMath::noShowRate($noShow, $checkedIn, $completed));
    }
}
