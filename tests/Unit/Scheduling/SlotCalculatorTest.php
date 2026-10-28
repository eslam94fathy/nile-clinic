<?php

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Support\SlotCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Cutting working periods into bookable slots (research R6, FR-008, FR-009, SC-003). 2026-11-01 is
 * a Sunday; weekday 0 = Sunday, 2 = Tuesday, 4 = Thursday, 5 = Friday.
 */
class SlotCalculatorTest extends TestCase
{
    private const TZ = 'Africa/Cairo';

    public function test_a_period_is_cut_into_appointment_length_slots(): void
    {
        $this->assertSame([
            ['date' => '2026-11-01', 'start' => '09:00', 'end' => '09:20'],
            ['date' => '2026-11-01', 'start' => '09:20', 'end' => '09:40'],
            ['date' => '2026-11-01', 'start' => '09:40', 'end' => '10:00'],
        ], $this->slots([0 => [[540, 600]]], 20, [], '2026-11-01', '2026-11-01'));
    }

    public function test_a_leftover_shorter_than_one_appointment_is_dropped(): void
    {
        $this->assertSame(
            ['09:00', '09:20', '09:40'],
            $this->starts($this->slots([0 => [[540, 610]]], 20, [], '2026-11-01', '2026-11-01')),
        );
    }

    public function test_several_periods_and_days_are_returned_in_order(): void
    {
        $slots = $this->slots([0 => [[1020, 1060], [540, 580]], 2 => [[600, 640]]], 20, [], '2026-11-01', '2026-11-03');

        $this->assertSame(
            ['2026-11-01 09:00', '2026-11-01 09:20', '2026-11-01 17:00', '2026-11-01 17:20', '2026-11-03 10:00', '2026-11-03 10:20'],
            array_map(fn (array $s) => "{$s['date']} {$s['start']}", $slots),
        );
    }

    public function test_a_period_can_end_at_midnight(): void
    {
        $this->assertSame(
            [['date' => '2026-11-01', 'start' => '23:00', 'end' => '23:30'], ['date' => '2026-11-01', 'start' => '23:30', 'end' => '24:00']],
            $this->slots([0 => [[1380, 1440]]], 30, [], '2026-11-01', '2026-11-01'),
        );
    }

    public function test_whole_day_time_off_removes_every_slot_on_its_dates(): void
    {
        $periods = [0 => [[540, 600]], 1 => [[540, 600]], 2 => [[540, 600]], 3 => [[540, 600]]];

        $slots = $this->slots($periods, 20, [$this->off('2026-11-01', '2026-11-03')], '2026-11-01', '2026-11-04');

        $this->assertSame(['2026-11-04'], array_values(array_unique(array_column($slots, 'date'))));
    }

    public function test_partial_time_off_removes_only_overlapping_slots(): void
    {
        $slots = $this->slots([0 => [[540, 600]]], 20, [$this->off('2026-11-01', '2026-11-01', 560, 580)], '2026-11-01', '2026-11-01');

        $this->assertSame(['09:00', '09:40'], $this->starts($slots));
    }

    public function test_partial_time_off_on_another_date_changes_nothing(): void
    {
        $slots = $this->slots([0 => [[540, 600]]], 20, [$this->off('2026-11-02', '2026-11-02', 540, 600)], '2026-11-01', '2026-11-01');

        $this->assertCount(3, $slots);
    }

    public function test_slots_that_have_started_are_dropped(): void
    {
        $now = CarbonImmutable::parse('2026-11-01 09:20', self::TZ);

        $this->assertSame(['09:40'], $this->starts($this->slots([0 => [[540, 600]]], 20, [], '2026-11-01', '2026-11-01', $now)));
    }

    public function test_no_periods_means_no_slots(): void
    {
        $this->assertSame([], $this->slots([], 20, [], '2026-11-01', '2026-11-07'));
    }

    public function test_starts_in_the_spring_forward_gap_are_dropped(): void
    {
        $now = CarbonImmutable::parse('2026-04-23 08:00', self::TZ);

        $this->assertSame(['01:00', '01:30'], $this->starts($this->slots([5 => [[0, 120]]], 30, [], '2026-04-24', '2026-04-24', $now)));
    }

    public function test_the_repeated_fall_back_hour_is_offered_once(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 08:00', self::TZ);

        $this->assertSame(
            ['22:00', '22:30', '23:00', '23:30'],
            $this->starts($this->slots([4 => [[1320, 1440]]], 30, [], '2026-10-29', '2026-10-29', $now)),
        );
    }

    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $periods
     * @param  list<array{start_date: string, end_date: string, start_minute: ?int, end_minute: ?int}>  $timeOff
     * @return list<array{date: string, start: string, end: string}>
     */
    private function slots(array $periods, int $length, array $timeOff, string $from, string $to, ?CarbonImmutable $now = null): array
    {
        return SlotCalculator::slots($periods, $length, $timeOff, $from, $to, $now ?? CarbonImmutable::parse('2026-10-31 12:00', self::TZ));
    }

    /**
     * @return array{start_date: string, end_date: string, start_minute: ?int, end_minute: ?int}
     */
    private function off(string $start, string $end, ?int $startMinute = null, ?int $endMinute = null): array
    {
        return ['start_date' => $start, 'end_date' => $end, 'start_minute' => $startMinute, 'end_minute' => $endMinute];
    }

    /**
     * @param  list<array{date: string, start: string, end: string}>  $slots
     * @return list<string>
     */
    private function starts(array $slots): array
    {
        return array_column($slots, 'start');
    }
}
