<?php

namespace Tests\Feature\Reports;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Support\Money;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every breakdown adds up exactly to its total, to the piastre, over many small amounts (SC-003, FR-019,
 * research R6).
 */
class MoneyExactnessReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_breakdown_equals_the_total_over_many_small_amounts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Africa/Cairo'));

        $charge = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create())->create();
        $staff = [User::factory()->receptionist()->create()->id, User::factory()->administrator()->create()->id];
        $methods = ['cash', 'card', 'wallet'];

        $start = CarbonImmutable::parse('2026-11-01 08:00', 'UTC');

        $payments = [];
        for ($i = 0; $i < 1000; $i++) {
            $payments[] = [
                'charge_id' => $charge->id,
                'amount' => '0.07',
                'method' => $methods[$i % 3],
                'received_by' => $staff[$i % 2],
                'created_at' => $start->addHours($i % 96)->format('Y-m-d H:i:s'),
            ];
        }
        DB::table('payments')->insert($payments);

        $refunds = [];
        for ($i = 0; $i < 100; $i++) {
            $refunds[] = [
                'charge_id' => $charge->id,
                'amount' => '0.03',
                'method' => $methods[$i % 3],
                'reason' => 'Synthetic refund',
                'refunded_by' => $staff[$i % 2],
                'created_at' => $start->addHours($i % 96)->format('Y-m-d H:i:s'),
            ];
        }
        DB::table('refunds')->insert($refunds);

        Sanctum::actingAs(User::factory()->administrator()->create());

        $report = $this->getJson('/api/v1/reports/money-collected?from=2026-11-01&to=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.totals', ['received' => '70.00', 'refunded' => '3.00', 'net' => '67.00'])
            ->json('data');

        $this->assertCount(3, $report['by_method']);
        $this->assertGreaterThan(1, count($report['by_day']));

        $this->assertAddsUp($report['totals'], $report['by_method']);
        $this->assertAddsUp($report['totals'], $report['by_day']);

        foreach ($report['by_method'] as $method) {
            $this->assertCount(2, $method['staff']);
            $this->assertAddsUp($method, $method['staff']);
        }
    }

    /**
     * @param  array<string, mixed>  $total
     * @param  list<array<string, mixed>>  $parts
     */
    private function assertAddsUp(array $total, array $parts): void
    {
        foreach (['received', 'refunded', 'net'] as $figure) {
            $sum = array_sum(array_map(fn (array $part) => Money::toPiastres($part[$figure]), $parts));

            $this->assertSame($total[$figure], Money::fromPiastres($sum));
        }
    }
}
