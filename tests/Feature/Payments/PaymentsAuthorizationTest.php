<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every endpoint: doctors do nothing here, and only administrators set fees,
 * discount, and refund (SC-005, FR-020, FR-022).
 */
class PaymentsAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private const ENDPOINTS = [
        'list fees', 'get fee', 'set fee', 'list visits', 'charge visit', 'list charges', 'get charge',
        'record payment', 'record refund', 'set discount', 'cancel charge',
    ];

    private const ADMIN_ONLY = ['set fee', 'record refund', 'set discount'];

    private Doctor $doctor;

    private QueueEntry $visit;

    private Charge $paidCharge;

    private Charge $unpaidCharge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $this->doctor->id, 'amount' => '350.00']);
        $this->visit = QueueEntry::factory()->number(1)->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->paidCharge = Charge::factory()
            ->forEntry(QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->fee('300.00')
            ->partiallyPaid('100.00')
            ->create();
        $this->unpaidCharge = Charge::factory()
            ->forEntry(QueueEntry::factory()->number(3)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->fee('300.00')
            ->create();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return array_combine(self::ENDPOINTS, array_map(fn (string $endpoint) => [$endpoint], self::ENDPOINTS));
    }

    #[DataProvider('endpoints')]
    public function test_a_doctor_is_forbidden_and_nothing_changes(string $endpoint): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->hit($endpoint)->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);

        $this->assertNothingChanged();
    }

    #[DataProvider('endpoints')]
    public function test_an_administrator_succeeds(string $endpoint): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->assertContains($this->hit($endpoint)->getStatusCode(), [200, 201]);
    }

    #[DataProvider('endpoints')]
    public function test_a_receptionist_gets_exactly_their_endpoints(string $endpoint): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $response = $this->hit($endpoint);

        if (in_array($endpoint, self::ADMIN_ONLY, true)) {
            $response->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);
            $this->assertNothingChanged();
        } else {
            $this->assertContains($response->getStatusCode(), [200, 201]);
        }
    }

    #[DataProvider('endpoints')]
    public function test_no_token_is_unauthenticated(string $endpoint): void
    {
        $this->hit($endpoint)->assertUnauthorized();

        $this->assertNothingChanged();
    }

    #[DataProvider('endpoints')]
    public function test_a_deactivated_account_with_a_real_token_is_unauthenticated(string $endpoint): void
    {
        $administrator = User::factory()->administrator()->create();
        $token = $this->signIn($administrator);
        $administrator->update(['is_active' => false]);

        $this->usingToken($token);

        $this->hit($endpoint)->assertUnauthorized();
        $this->assertNothingChanged();
    }

    public function test_a_doctor_gets_forbidden_before_validation_and_lookup(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->putJson('/api/v1/doctors/999999/fee', [])->assertForbidden();
        $this->getJson('/api/v1/doctors/999999/fee')->assertForbidden();
        $this->getJson('/api/v1/visits')->assertForbidden();
        $this->postJson('/api/v1/visits/999999/charge')->assertForbidden();
        $this->getJson('/api/v1/charges')->assertForbidden();
        $this->getJson('/api/v1/charges/999999')->assertForbidden();
        $this->postJson('/api/v1/charges/999999/payments', [])->assertForbidden();
        $this->postJson('/api/v1/charges/999999/refunds', [])->assertForbidden();
        $this->putJson('/api/v1/charges/999999/discount', [])->assertForbidden();
        $this->postJson('/api/v1/charges/999999/cancel', [])->assertForbidden();
    }

    public function test_a_receptionist_gets_forbidden_on_administrator_actions_before_validation_and_lookup(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->putJson('/api/v1/doctors/999999/fee', [])->assertForbidden();
        $this->postJson('/api/v1/charges/999999/refunds', [])->assertForbidden();
        $this->putJson('/api/v1/charges/999999/discount', [])->assertForbidden();
    }

    private function hit(string $endpoint): TestResponse
    {
        $doctor = "/api/v1/doctors/{$this->doctor->id}/fee";
        $paid = "/api/v1/charges/{$this->paidCharge->id}";
        $unpaid = "/api/v1/charges/{$this->unpaidCharge->id}";

        return match ($endpoint) {
            'list fees' => $this->getJson('/api/v1/fees'),
            'get fee' => $this->getJson($doctor),
            'set fee' => $this->putJson($doctor, ['amount' => '500.00']),
            'list visits' => $this->getJson('/api/v1/visits?date=2026-11-01'),
            'charge visit' => $this->postJson("/api/v1/visits/{$this->visit->id}/charge"),
            'list charges' => $this->getJson('/api/v1/charges?from=2026-11-01&to=2026-11-01'),
            'get charge' => $this->getJson($paid),
            'record payment' => $this->postJson("{$paid}/payments", ['amount' => '10.00', 'method' => 'cash']),
            'record refund' => $this->postJson("{$paid}/refunds", ['amount' => '10.00', 'method' => 'cash', 'reason' => 'Synthetic']),
            'set discount' => $this->putJson("{$paid}/discount", ['amount' => '10.00', 'reason' => 'Synthetic']),
            'cancel charge' => $this->postJson("{$unpaid}/cancel", ['reason' => 'Synthetic']),
        };
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame('350.00', DoctorFee::query()->sole()->amount);
        $this->assertSame(2, Charge::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('100.00', $this->paidCharge->fresh()->amount_paid);
        $this->assertSame('0.00', $this->paidCharge->fresh()->discount);
        $this->assertSame('unpaid', $this->unpaidCharge->fresh()->status->value);
    }
}
