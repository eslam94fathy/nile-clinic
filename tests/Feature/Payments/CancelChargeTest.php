<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A charge with no money held can be cancelled; it stays on record and the visit can be charged again
 * (US5, FR-013).
 */
class CancelChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private User $admin;

    private Doctor $doctor;

    private QueueEntry $entry;

    private Charge $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        $this->admin = User::factory()->administrator()->create();
        $this->doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $this->doctor->id, 'amount' => '350.00']);
        $this->entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->charge = Charge::factory()->forEntry($this->entry)->fee('300.00')->create();
        Sanctum::actingAs($this->receptionist);
    }

    public function test_an_unpaid_charge_is_cancelled_and_stays_on_record(): void
    {
        $this->cancel('Synthetic mistake')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Synthetic mistake')
            ->assertJsonPath('data.cancelled_by', ['id' => $this->receptionist->id, 'name' => $this->receptionist->name])
            ->assertJsonPath('data.cancelled_at', now()->toISOString());

        $this->getJson("/api/v1/charges/{$this->charge->id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_the_visit_can_be_charged_again_at_the_current_fee(): void
    {
        $this->cancel('Synthetic mistake')->assertOk();

        $this->postJson("/api/v1/visits/{$this->entry->id}/charge")
            ->assertCreated()
            ->assertJsonPath('data.fee', '350.00')
            ->assertJsonPath('data.status', 'unpaid');

        $this->assertSame(2, Charge::query()->where('queue_entry_id', $this->entry->id)->count());
    }

    public function test_a_charge_with_money_held_cannot_be_cancelled(): void
    {
        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '0.01', 'method' => 'cash'])->assertCreated();

        $this->cancel('Synthetic')
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.money_held'));

        $this->assertSame(ChargeStatus::PartiallyPaid, $this->charge->fresh()->status);
    }

    public function test_a_charge_refunded_to_zero_can_be_cancelled(): void
    {
        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/charges/{$this->charge->id}/refunds", ['amount' => '100.00', 'method' => 'cash', 'reason' => 'Synthetic'])->assertCreated();

        Sanctum::actingAs($this->receptionist);
        $this->cancel('Synthetic')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonCount(1, 'data.payments')->assertJsonCount(1, 'data.refunds');
    }

    public function test_a_wholly_discounted_charge_can_be_cancelled(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/charges/{$this->charge->id}/discount", ['amount' => '300.00', 'reason' => 'Synthetic'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->cancel('Synthetic')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelled_is_final(): void
    {
        $this->cancel('Synthetic')->assertOk();

        $this->cancel('Again')->assertUnprocessable()->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
        $this->postJson("/api/v1/charges/{$this->charge->id}/payments", ['amount' => '10.00', 'method' => 'cash'])->assertUnprocessable();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/charges/{$this->charge->id}/refunds", ['amount' => '10.00', 'method' => 'cash', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
        $this->putJson("/api/v1/charges/{$this->charge->id}/discount", ['amount' => '10.00', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled'));
    }

    public function test_a_reason_is_required_and_limited(): void
    {
        $this->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->cancel('   ')->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->cancel(str_repeat('a', 501))->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->assertSame(ChargeStatus::Unpaid, $this->charge->fresh()->status);
    }

    public function test_an_unknown_charge_is_not_found(): void
    {
        $this->postJson('/api/v1/charges/999999/cancel', ['reason' => 'Synthetic'])->assertNotFound();
    }

    public function test_an_administrator_can_cancel(): void
    {
        Sanctum::actingAs($this->admin);

        $this->cancel('Synthetic')->assertOk();
    }

    public function test_doctors_are_forbidden(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->cancel('Synthetic')->assertForbidden();
        $this->postJson($this->url(), [])->assertForbidden();

        $this->assertSame(ChargeStatus::Unpaid, $this->charge->fresh()->status);
    }

    private function url(): string
    {
        return "/api/v1/charges/{$this->charge->id}/cancel";
    }

    private function cancel(string $reason): TestResponse
    {
        return $this->postJson($this->url(), ['reason' => $reason]);
    }
}
