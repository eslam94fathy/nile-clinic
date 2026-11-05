<?php

namespace Tests\Feature\Reports;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Money received and refunded, by method, staff member, and day (US1, FR-007, FR-008).
 */
class MoneyCollectedReportTest extends TestCase
{
    use RefreshDatabase;

    private const NOON_NOV_1 = '2026-11-01 10:00:00';

    private const NOON_NOV_2 = '2026-11-02 10:00:00';

    private Charge $chargeA;

    private Charge $chargeB;

    private User $amal;

    private User $bassem;

    private User $zaki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $this->amal = User::factory()->receptionist()->create(['name' => 'Amal Synthetic']);
        $this->bassem = User::factory()->receptionist()->create(['name' => 'Bassem Synthetic']);
        $this->zaki = User::factory()->administrator()->create(['name' => 'Zaki Synthetic']);

        $this->chargeA = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create())->create();
        $this->chargeB = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create())->create();

        Sanctum::actingAs($this->zaki);
    }

    private function pay(Charge $charge, string $amount, PaymentMethod $method, User $by, string $at): void
    {
        Payment::factory()->create(['charge_id' => $charge->id, 'amount' => $amount, 'method' => $method, 'received_by' => $by->id, 'created_at' => $at]);
    }

    private function refund(Charge $charge, string $amount, PaymentMethod $method, User $by, string $at): void
    {
        Refund::factory()->create(['charge_id' => $charge->id, 'amount' => $amount, 'method' => $method, 'refunded_by' => $by->id, 'created_at' => $at]);
    }

    private function report(string $from, string $to, ?int $doctorId = null): TestResponse
    {
        $query = http_build_query(array_filter(['from' => $from, 'to' => $to, 'doctor_id' => $doctorId]));

        return $this->getJson("/api/v1/reports/money-collected?{$query}");
    }

    private function seedTwoDays(): void
    {
        $this->pay($this->chargeA, '100.10', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);
        $this->pay($this->chargeA, '199.90', PaymentMethod::Card, $this->bassem, self::NOON_NOV_1);
        $this->pay($this->chargeB, '50.00', PaymentMethod::Cash, $this->bassem, self::NOON_NOV_1);
        $this->refund($this->chargeA, '150.00', PaymentMethod::Cash, $this->zaki, self::NOON_NOV_2);
    }

    public function test_the_full_report_has_the_exact_shape_and_figures(): void
    {
        $this->seedTwoDays();

        $this->report('2026-11-01', '2026-11-02')->assertOk()->assertExactJson(['data' => [
            'from' => '2026-11-01',
            'to' => '2026-11-02',
            'totals' => ['received' => '350.00', 'refunded' => '150.00', 'net' => '200.00'],
            'by_method' => [
                [
                    'method' => 'cash',
                    'received' => '150.10',
                    'refunded' => '150.00',
                    'net' => '0.10',
                    'staff' => [
                        ['id' => $this->amal->id, 'name' => 'Amal Synthetic', 'received' => '100.10', 'refunded' => '0.00', 'net' => '100.10'],
                        ['id' => $this->bassem->id, 'name' => 'Bassem Synthetic', 'received' => '50.00', 'refunded' => '0.00', 'net' => '50.00'],
                        ['id' => $this->zaki->id, 'name' => 'Zaki Synthetic', 'received' => '0.00', 'refunded' => '150.00', 'net' => '-150.00'],
                    ],
                ],
                [
                    'method' => 'card',
                    'received' => '199.90',
                    'refunded' => '0.00',
                    'net' => '199.90',
                    'staff' => [
                        ['id' => $this->bassem->id, 'name' => 'Bassem Synthetic', 'received' => '199.90', 'refunded' => '0.00', 'net' => '199.90'],
                    ],
                ],
            ],
            'by_day' => [
                ['date' => '2026-11-01', 'received' => '350.00', 'refunded' => '0.00', 'net' => '350.00'],
                ['date' => '2026-11-02', 'received' => '0.00', 'refunded' => '150.00', 'net' => '-150.00'],
            ],
        ]]);
    }

    public function test_methods_come_in_a_fixed_order(): void
    {
        $this->pay($this->chargeA, '1.00', PaymentMethod::Wallet, $this->amal, self::NOON_NOV_1);
        $this->pay($this->chargeA, '1.00', PaymentMethod::Card, $this->amal, self::NOON_NOV_1);
        $this->pay($this->chargeA, '1.00', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);

        $methods = array_column($this->report('2026-11-01', '2026-11-01')->json('data.by_method'), 'method');

        $this->assertSame(['cash', 'card', 'wallet'], $methods);
    }

    public function test_each_day_counts_only_its_own_money_and_a_later_refund_leaves_the_earlier_day_alone(): void
    {
        $this->pay($this->chargeA, '300.00', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);
        $before = $this->report('2026-11-01', '2026-11-01')->json('data.totals');

        $this->refund($this->chargeA, '300.00', PaymentMethod::Cash, $this->zaki, self::NOON_NOV_2);

        $this->assertSame($before, $this->report('2026-11-01', '2026-11-01')->json('data.totals'));
        $this->assertSame(
            ['received' => '0.00', 'refunded' => '300.00', 'net' => '-300.00'],
            $this->report('2026-11-02', '2026-11-02')->json('data.totals'),
        );
    }

    public function test_a_small_negative_net_is_printed_exactly(): void
    {
        $this->pay($this->chargeA, '100.10', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);
        $this->refund($this->chargeA, '150.00', PaymentMethod::Cash, $this->zaki, self::NOON_NOV_1);
        $this->pay($this->chargeB, '10.00', PaymentMethod::Wallet, $this->amal, self::NOON_NOV_2);
        $this->refund($this->chargeB, '10.50', PaymentMethod::Wallet, $this->zaki, self::NOON_NOV_2);

        $methods = $this->report('2026-11-01', '2026-11-02')->json('data.by_method');

        $this->assertSame('-49.90', $methods[0]['net']);
        $this->assertSame('-0.50', $methods[1]['net']);
    }

    public function test_a_doctor_filter_counts_only_that_doctors_charges(): void
    {
        $this->seedTwoDays();

        $this->report('2026-11-01', '2026-11-02', $this->chargeB->doctor_id)
            ->assertOk()
            ->assertJsonPath('data.totals', ['received' => '50.00', 'refunded' => '0.00', 'net' => '50.00'])
            ->assertJsonCount(1, 'data.by_method')
            ->assertJsonCount(1, 'data.by_day');
    }

    public function test_a_cancelled_charges_payment_and_refund_still_count_and_net_to_zero(): void
    {
        $cancelled = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create())->cancelled()->create();
        $this->pay($cancelled, '75.00', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);
        $this->refund($cancelled, '75.00', PaymentMethod::Cash, $this->zaki, self::NOON_NOV_2);

        $this->report('2026-11-01', '2026-11-02')
            ->assertJsonPath('data.totals', ['received' => '75.00', 'refunded' => '75.00', 'net' => '0.00']);
    }

    public function test_a_period_with_no_money_has_zero_totals_and_empty_lists(): void
    {
        $this->seedTwoDays();

        $this->report('2026-10-01', '2026-10-31')->assertOk()->assertExactJson(['data' => [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'totals' => ['received' => '0.00', 'refunded' => '0.00', 'net' => '0.00'],
            'by_method' => [],
            'by_day' => [],
        ]]);
    }

    public function test_a_method_or_day_with_no_money_is_not_listed(): void
    {
        $this->pay($this->chargeA, '20.00', PaymentMethod::Card, $this->amal, self::NOON_NOV_1);
        $this->pay($this->chargeA, '20.00', PaymentMethod::Card, $this->amal, '2026-11-03 10:00:00');

        $this->report('2026-11-01', '2026-11-03')
            ->assertJsonCount(1, 'data.by_method')
            ->assertJsonPath('data.by_method.0.method', 'card')
            ->assertJsonCount(2, 'data.by_day')
            ->assertJsonPath('data.by_day.0.date', '2026-11-01')
            ->assertJsonPath('data.by_day.1.date', '2026-11-03');
    }

    public function test_a_deactivated_staff_member_still_appears(): void
    {
        $this->pay($this->chargeA, '20.00', PaymentMethod::Cash, $this->amal, self::NOON_NOV_1);
        $this->amal->update(['is_active' => false]);

        $this->report('2026-11-01', '2026-11-01')
            ->assertJsonPath('data.by_method.0.staff.0.id', $this->amal->id);
    }

    public function test_a_known_doctor_with_no_money_gets_zero_totals(): void
    {
        $this->seedTwoDays();
        $doctor = Doctor::factory()->create();

        $this->report('2026-11-01', '2026-11-02', $doctor->id)
            ->assertOk()
            ->assertJsonPath('data.totals.net', '0.00')
            ->assertJsonCount(0, 'data.by_day');
    }
}
