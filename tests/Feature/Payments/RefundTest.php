<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only administrators return money, with a reason; what was received stays on record (US5, FR-012,
 * FR-015).
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Charge $charge;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->admin = User::factory()->administrator()->create();
        $this->charge = Charge::factory()->fee('300.00')->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '300.00', 'method' => 'card'])->assertCreated();
        $this->payment = Payment::query()->sole();
    }

    public function test_a_part_refund_updates_the_figures_and_leaves_the_payment_alone(): void
    {
        $before = (array) DB::table('payments')->where('id', $this->payment->id)->first();

        $response = $this->refund('50.00', 'cash', 'Synthetic overpayment')
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', '250.00')
            ->assertJsonPath('data.balance', '50.00')
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonCount(1, 'data.refunds');

        $refund = $response->json('data.refunds.0');
        $keys = array_keys($refund);
        sort($keys);
        $this->assertSame(['amount', 'id', 'method', 'reason', 'refunded_at', 'refunded_by'], $keys);
        $this->assertSame('50.00', $refund['amount']);
        $this->assertSame('cash', $refund['method']);
        $this->assertSame('Synthetic overpayment', $refund['reason']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->name], $refund['refunded_by']);
        $this->assertSame(now()->toISOString(), $refund['refunded_at']);

        $this->assertSame($before, (array) DB::table('payments')->where('id', $this->payment->id)->first());
    }

    public function test_a_full_refund_makes_it_unpaid_again(): void
    {
        $this->refund('300.00', 'card', 'Synthetic mistake')
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', '0.00')
            ->assertJsonPath('data.balance', '300.00')
            ->assertJsonPath('data.status', 'unpaid');
    }

    public function test_money_can_be_paid_again_after_a_refund(): void
    {
        $this->refund('100.00', 'cash', 'Synthetic')->assertCreated();

        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '100.00', 'method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '300.00');
    }

    public function test_the_stored_total_matches_payments_less_refunds(): void
    {
        $this->refund('33.33', 'cash', 'Synthetic')->assertCreated();
        $this->refund('0.07', 'cash', 'Synthetic')->assertCreated();

        $this->assertSame(
            DB::scalar('SELECT CAST((SELECT SUM(amount) FROM payments WHERE charge_id = ?) - (SELECT SUM(amount) FROM refunds WHERE charge_id = ?) AS DECIMAL(10,2))', [$this->charge->id, $this->charge->id]),
            DB::table('charges')->where('id', $this->charge->id)->value('amount_paid'),
        );
        $this->assertSame('266.60', $this->charge->fresh()->amount_paid);
    }

    public function test_a_refund_above_what_was_paid_is_refused(): void
    {
        $this->refund('300.01', 'cash', 'Too much')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.above_paid'));

        $this->refund('200.00', 'cash', 'Synthetic')->assertCreated();

        $this->refund('100.01', 'cash', 'Too much')->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('100.00', $this->charge->fresh()->amount_paid);
    }

    /**
     * @return array<string, array{mixed, mixed, mixed, string}>
     */
    public static function badInput(): array
    {
        return [
            'zero' => [0, 'cash', 'Synthetic', 'amount'],
            'negative' => [-1, 'cash', 'Synthetic', 'amount'],
            'three decimals' => [1.999, 'cash', 'Synthetic', 'amount'],
            'no amount' => [null, 'cash', 'Synthetic', 'amount'],
            'unknown method' => ['10.00', 'bitcoin', 'Synthetic', 'method'],
            'no method' => ['10.00', null, 'Synthetic', 'method'],
            'no reason' => ['10.00', 'cash', null, 'reason'],
            'blank reason' => ['10.00', 'cash', '   ', 'reason'],
            'long reason' => ['10.00', 'cash', 'too long', 'reason'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_bad_input_is_refused(mixed $amount, mixed $method, mixed $reason, string $field): void
    {
        $reason = $reason === 'too long' ? str_repeat('a', 501) : $reason;

        $this->refund($amount, $method, $reason)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame(0, Refund::query()->count());
    }

    public function test_a_cancelled_charge_is_refused(): void
    {
        $cancelled = Charge::factory()->cancelled()->create();

        $this->postJson("/api/v1/charges/{$cancelled->id}/refunds", ['amount' => '10.00', 'method' => 'cash', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
    }

    public function test_an_unknown_charge_is_not_found(): void
    {
        $this->postJson('/api/v1/charges/999999/refunds', ['amount' => '10.00', 'method' => 'cash', 'reason' => 'Synthetic'])->assertNotFound();
    }

    public function test_only_administrators_refund(): void
    {
        foreach ([User::factory()->receptionist()->create(), User::factory()->doctor()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->refund('10.00', 'cash', 'Synthetic')->assertForbidden();
            $this->refund('not a number', 'cash', null)->assertForbidden();
        }

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('300.00', $this->charge->fresh()->amount_paid);
    }

    /**
     * FR-012: nothing can change or delete money already recorded.
     */
    public function test_no_route_changes_or_deletes_a_payment_or_refund(): void
    {
        $this->refund('10.00', 'cash', 'Synthetic')->assertCreated();
        $refund = Refund::query()->sole();
        $base = "/api/v1/charges/{$this->charge->id}";

        foreach (["{$base}/payments/{$this->payment->id}", "{$base}/refunds/{$refund->id}", "/api/v1/payments/{$this->payment->id}", "/api/v1/refunds/{$refund->id}"] as $url) {
            $this->assertContains($this->putJson($url, ['amount' => '1.00'])->getStatusCode(), [404, 405], $url);
            $this->assertContains($this->patchJson($url, ['amount' => '1.00'])->getStatusCode(), [404, 405], $url);
            $this->assertContains($this->deleteJson($url)->getStatusCode(), [404, 405], $url);
        }

        $this->assertContains($this->deleteJson($base)->getStatusCode(), [404, 405]);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Refund::query()->count());
    }

    private function refund(mixed $amount, mixed $method, mixed $reason): TestResponse
    {
        return $this->postJson("/api/v1/charges/{$this->charge->id}/refunds", ['amount' => $amount, 'method' => $method, 'reason' => $reason]);
    }
}
