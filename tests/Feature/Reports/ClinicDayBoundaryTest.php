<?php

namespace Tests\Feature\Reports;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Money counts on the clinic day it was recorded, including across daylight-saving changes (SC-004,
 * FR-008, FR-022, research R4). Cairo is UTC+2 in winter and UTC+3 in summer: in 2026 the clocks go forward at
 * the start of April 24 and back at the end of October 29.
 */
class ClinicDayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Charge $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Africa/Cairo'));
        $this->charge = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create())->create();
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function instants(): array
    {
        return [
            'ordinary day, a minute before midnight' => ['2026-11-04 21:59:00', '2026-11-04'],
            'ordinary day, at midnight' => ['2026-11-04 22:00:00', '2026-11-05'],
            'clocks forward, a minute before midnight' => ['2026-04-23 21:59:00', '2026-04-23'],
            'clocks forward, the first instant of the day' => ['2026-04-23 22:00:00', '2026-04-24'],
            'summer, a minute before midnight' => ['2026-07-01 20:59:00', '2026-07-01'],
            'summer, at midnight' => ['2026-07-01 21:00:00', '2026-07-02'],
            'clocks back, the first 23:30' => ['2026-10-29 20:30:00', '2026-10-29'],
            'clocks back, the second 23:30' => ['2026-10-29 21:30:00', '2026-10-29'],
            'clocks back, a minute before midnight' => ['2026-10-29 21:59:00', '2026-10-29'],
            'clocks back, at midnight' => ['2026-10-29 22:00:00', '2026-10-30'],
        ];
    }

    #[DataProvider('instants')]
    public function test_a_payment_counts_on_its_clinic_day(string $utc, string $clinicDate): void
    {
        Payment::factory()->create(['charge_id' => $this->charge->id, 'amount' => '10.00', 'created_at' => $utc]);

        $this->assertDay($clinicDate, ['received' => '10.00', 'refunded' => '0.00', 'net' => '10.00']);
    }

    #[DataProvider('instants')]
    public function test_a_refund_counts_on_its_clinic_day(string $utc, string $clinicDate): void
    {
        Refund::factory()->create(['charge_id' => $this->charge->id, 'amount' => '10.00', 'created_at' => $utc]);

        $this->assertDay($clinicDate, ['received' => '0.00', 'refunded' => '10.00', 'net' => '-10.00']);
    }

    public function test_a_period_ending_the_day_before_clocks_go_forward_stops_at_the_right_instant(): void
    {
        Payment::factory()->create(['charge_id' => $this->charge->id, 'amount' => '1.00', 'created_at' => '2026-04-23 21:59:59']);
        Payment::factory()->create(['charge_id' => $this->charge->id, 'amount' => '2.00', 'created_at' => '2026-04-23 22:00:00']);

        $this->getJson('/api/v1/reports/money-collected?from=2026-04-23&to=2026-04-23')
            ->assertJsonPath('data.totals.received', '1.00');
        $this->getJson('/api/v1/reports/money-collected?from=2026-04-24&to=2026-04-24')
            ->assertJsonPath('data.totals.received', '2.00');
    }

    public function test_a_wider_period_groups_both_clock_changes_correctly(): void
    {
        foreach (['2026-10-29 20:30:00', '2026-10-29 21:30:00', '2026-10-29 22:00:00'] as $utc) {
            Payment::factory()->create(['charge_id' => $this->charge->id, 'amount' => '5.00', 'created_at' => $utc]);
        }

        $this->getJson('/api/v1/reports/money-collected?from=2026-10-28&to=2026-10-31')
            ->assertJsonPath('data.by_day', [
                ['date' => '2026-10-29', 'received' => '10.00', 'refunded' => '0.00', 'net' => '10.00'],
                ['date' => '2026-10-30', 'received' => '5.00', 'refunded' => '0.00', 'net' => '5.00'],
            ]);
    }

    /**
     * The day holds the money, and neither neighbouring day does.
     *
     * @param  array{received: string, refunded: string, net: string}  $figures
     */
    private function assertDay(string $date, array $figures): void
    {
        $this->getJson("/api/v1/reports/money-collected?from={$date}&to={$date}")
            ->assertOk()
            ->assertJsonPath('data.totals', $figures)
            ->assertJsonPath('data.by_day', [['date' => $date, ...$figures]]);

        foreach ([CarbonImmutable::parse($date)->subDay(), CarbonImmutable::parse($date)->addDay()] as $neighbour) {
            $day = $neighbour->toDateString();

            $this->getJson("/api/v1/reports/money-collected?from={$day}&to={$day}")
                ->assertOk()
                ->assertJsonCount(0, 'data.by_day');
        }
    }
}
