<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\Charge;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators reduce or waive a fee, with a reason; the original fee stays visible (US4, FR-011).
 */
class DiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Charge $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->admin = User::factory()->administrator()->create();
        $this->charge = Charge::factory()->fee('400.00')->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_a_discount_lowers_what_is_owed_and_keeps_the_fee_visible(): void
    {
        $this->discount('150.00', 'Synthetic hardship')
            ->assertOk()
            ->assertJsonPath('data.fee', '400.00')
            ->assertJsonPath('data.discount', '150.00')
            ->assertJsonPath('data.amount_due', '250.00')
            ->assertJsonPath('data.balance', '250.00')
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.discount_reason', 'Synthetic hardship')
            ->assertJsonPath('data.discounted_by', ['id' => $this->admin->id, 'name' => $this->admin->name])
            ->assertJsonPath('data.discounted_at', now()->toISOString());
    }

    public function test_a_later_discount_replaces_the_earlier_one(): void
    {
        $this->discount('150.00', 'First')->assertOk();

        $this->discount('50.00', 'Second')
            ->assertOk()
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.balance', '350.00')
            ->assertJsonPath('data.discount_reason', 'Second');
    }

    public function test_zero_removes_a_discount_and_records_the_reason(): void
    {
        $this->discount('150.00', 'First')->assertOk();

        $this->discount('0', 'Synthetic removal')
            ->assertOk()
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.balance', '400.00')
            ->assertJsonPath('data.discount_reason', 'Synthetic removal');
    }

    public function test_the_whole_fee_can_be_waived(): void
    {
        $this->discount('400.00', 'Synthetic waiver')
            ->assertOk()
            ->assertJsonPath('data.amount_due', '0.00')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_a_discount_cannot_exceed_the_fee_less_what_is_paid(): void
    {
        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertCreated();

        $this->discount('300.01', 'Too much')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.discount_too_large'));

        $this->assertSame('0.00', $this->charge->fresh()->discount);

        $this->discount('300.00', 'Exactly the rest')
            ->assertOk()
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_a_smaller_discount_after_payment_shows_part_paid(): void
    {
        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertCreated();

        $this->discount('50.00', 'Synthetic')
            ->assertOk()
            ->assertJsonPath('data.balance', '250.00')
            ->assertJsonPath('data.status', 'partially_paid');
    }

    public function test_above_the_fee_is_refused(): void
    {
        $this->discount('400.01', 'Too much')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    /**
     * @return array<string, array{mixed, mixed, string}>
     */
    public static function badInput(): array
    {
        return [
            'negative' => [-1, 'Synthetic', 'amount'],
            'three decimals' => [1.999, 'Synthetic', 'amount'],
            'no amount' => [null, 'Synthetic', 'amount'],
            'no reason' => ['10.00', null, 'reason'],
            'blank reason' => ['10.00', '   ', 'reason'],
            'long reason' => ['10.00', 'too long', 'reason'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_bad_input_is_refused(mixed $amount, mixed $reason, string $field): void
    {
        $reason = $reason === 'too long' ? str_repeat('a', 501) : $reason;

        $this->discount($amount, $reason)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame('0.00', $this->charge->fresh()->discount);
    }

    public function test_a_reason_may_be_500_characters(): void
    {
        $this->discount('10.00', str_repeat('م', 500))->assertOk();
    }

    public function test_a_cancelled_charge_is_refused(): void
    {
        $cancelled = Charge::factory()->cancelled()->create();

        $this->putJson("/api/v1/charges/{$cancelled->id}/discount", ['amount' => '10.00', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
    }

    public function test_an_unknown_charge_is_not_found(): void
    {
        $this->putJson('/api/v1/charges/999999/discount', ['amount' => '10.00', 'reason' => 'Synthetic'])->assertNotFound();
    }

    public function test_only_administrators_discount(): void
    {
        foreach ([User::factory()->receptionist()->create(), User::factory()->doctor()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->discount('10.00', 'Synthetic')->assertForbidden();
            $this->discount('not a number', null)->assertForbidden();
        }

        $this->assertSame('0.00', $this->charge->fresh()->discount);
    }

    private function discount(mixed $amount, mixed $reason): TestResponse
    {
        return $this->putJson("/api/v1/charges/{$this->charge->id}/discount", ['amount' => $amount, 'reason' => $reason]);
    }
}
