<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\Charge;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every balance is exact to the piastre, and the stored total never drifts from the payment rows
 * (SC-004, research R3).
 */
class MoneyExactnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_100_small_payments_end_exactly_at_zero(): void
    {
        $charge = Charge::factory()->fee('7.00')->create();

        foreach (range(1, 100) as $i) {
            $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => '0.07', 'method' => 'cash'])->assertCreated();
            $this->assertStoredTotalMatchesTheRows($charge);
        }

        $this->getJson("/api/v1/charges/{$charge->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '7.00')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonCount(100, 'data.payments');
    }

    /**
     * 0.1 + 0.2 is not 0.3 in floats.
     */
    public function test_amounts_that_drift_in_floats_are_exact(): void
    {
        $charge = Charge::factory()->fee('0.30')->create();

        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => 0.1, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => 0.2, 'method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '0.30')
            ->assertJsonPath('data.balance', '0.00');

        $this->assertStoredTotalMatchesTheRows($charge);
    }

    public function test_amounts_that_drift_when_multiplied_are_exact(): void
    {
        $charge = Charge::factory()->fee('19.99')->create();

        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => 19.99, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance', '0.00');
    }

    private function assertStoredTotalMatchesTheRows(Charge $charge): void
    {
        $this->assertSame(
            DB::scalar('SELECT CAST(COALESCE((SELECT SUM(amount) FROM payments WHERE charge_id = ?), 0) - COALESCE((SELECT SUM(amount) FROM refunds WHERE charge_id = ?), 0) AS DECIMAL(10,2))', [$charge->id, $charge->id]),
            DB::table('charges')->where('id', $charge->id)->value('amount_paid'),
        );
    }
}
