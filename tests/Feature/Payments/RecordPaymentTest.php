<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reception records money received, in full or in part, up to the balance (US2, FR-008–FR-010).
 */
class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Charge $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        $this->charge = Charge::factory()->fee('300.00')->create();
        Sanctum::actingAs($this->receptionist);
    }

    public function test_a_part_payment_updates_the_figures(): void
    {
        $response = $this->pay('100.10', 'cash')
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.amount_paid', '100.10')
            ->assertJsonPath('data.balance', '199.90')
            ->assertJsonCount(1, 'data.payments');

        $payment = $response->json('data.payments.0');
        $keys = array_keys($payment);
        sort($keys);
        $this->assertSame(['amount', 'id', 'method', 'received_at', 'received_by'], $keys);
        $this->assertSame('100.10', $payment['amount']);
        $this->assertSame('cash', $payment['method']);
        $this->assertSame(['id' => $this->receptionist->id, 'name' => $this->receptionist->name], $payment['received_by']);
        $this->assertSame(now()->toISOString(), $payment['received_at']);
    }

    public function test_the_last_payment_makes_it_paid_with_several_methods(): void
    {
        $this->pay('100.10', 'cash')->assertCreated();

        $this->pay('199.90', 'card')
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', '300.00')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.payments.0.method', 'cash')
            ->assertJsonPath('data.payments.1.method', 'card');
    }

    public function test_a_full_payment_at_once_makes_it_paid(): void
    {
        $this->pay('300.00', 'wallet')->assertCreated()->assertJsonPath('data.status', 'paid');
    }

    public function test_a_payment_above_the_balance_is_refused_and_nothing_changes(): void
    {
        $this->pay('300.01', 'cash')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.above_balance'));

        $this->pay('100.00', 'cash')->assertCreated();

        $this->pay('200.01', 'cash')->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('100.00', $this->charge->fresh()->amount_paid);
    }

    public function test_a_paid_charge_takes_no_more(): void
    {
        $this->pay('300.00', 'cash')->assertCreated();

        $this->pay('0.01', 'cash')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.above_balance'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'three decimals' => [1.999],
            'three decimals as text' => ['1.999'],
            'not a number' => ['abc'],
            'empty' => [null],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_amount_is_refused(mixed $amount): void
    {
        $this->pay($amount, 'cash')->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_method_must_be_known(): void
    {
        $this->pay('100.00', 'bitcoin')->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->postJson($this->url(), ['amount' => '100.00'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
    }

    public function test_a_cancelled_charge_is_refused(): void
    {
        $cancelled = Charge::factory()->cancelled()->create();

        $this->postJson("/api/v1/charges/{$cancelled->id}/payments", ['amount' => '10.00', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
    }

    public function test_an_unknown_charge_is_not_found(): void
    {
        $this->postJson('/api/v1/charges/999999/payments', ['amount' => '10.00', 'method' => 'cash'])
            ->assertNotFound()
            ->assertExactJson(['message' => __('payments::messages.charge_not_found')]);
        $this->getJson('/api/v1/charges/999999')->assertNotFound();
    }

    public function test_opening_the_charge_shows_the_same_figures(): void
    {
        $this->pay('100.10', 'cash')->assertCreated();

        $this->getJson("/api/v1/charges/{$this->charge->id}")
            ->assertOk()
            ->assertJsonPath('data.amount_paid', '100.10')
            ->assertJsonPath('data.balance', '199.90')
            ->assertJsonCount(1, 'data.payments');
    }

    /**
     * FR-010: a payment holds only its method, so a card number has nowhere to go.
     */
    public function test_a_payment_has_nowhere_to_hold_card_details(): void
    {
        $this->assertSame(['id', 'charge_id', 'amount', 'method', 'received_by', 'created_at'], Schema::getColumnListing('payments'));

        $response = $this->postJson($this->url(), ['amount' => '10.00', 'method' => 'card', 'card_number' => '4111111111111111'])->assertCreated();

        $this->assertStringNotContainsString('4111', $response->getContent());
    }

    public function test_an_administrator_can_record_a_payment(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->pay('10.00', 'cash')->assertCreated();
    }

    public function test_doctors_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->pay('10.00', 'cash')->assertForbidden();
        $this->pay('not a number', 'cash')->assertForbidden();
        $this->getJson("/api/v1/charges/{$this->charge->id}")->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
    }

    private function url(): string
    {
        return "/api/v1/charges/{$this->charge->id}/payments";
    }

    private function pay(mixed $amount, string $method): TestResponse
    {
        return $this->postJson($this->url(), ['amount' => $amount, 'method' => $method]);
    }
}
