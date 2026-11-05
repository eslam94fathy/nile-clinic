<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ChargeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception finds charges by date range and opens one with all its money (US3, FR-017–FR-019).
 */
class ChargeListTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['amount_due', 'amount_paid', 'balance', 'charged_at', 'date', 'discount', 'doctor', 'fee', 'id', 'patient', 'status', 'visit_id'];

    private Doctor $doctor;

    private Patient $patient;

    private Charge $older;

    private Charge $newer;

    private Charge $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        $this->patient = Patient::factory()->create();

        $this->older = $this->chargeOn('2026-10-20', 1)->create();
        $this->newer = $this->chargeOn('2026-10-25', 2)->paid()->create();
        $this->other = Charge::factory()->fee('150.00')->create(['date' => '2026-10-22']);

        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_the_list_is_newest_first_with_exactly_the_summary_fields(): void
    {
        $data = $this->charges()->assertOk()->json('data');

        $this->assertSame([$this->newer->id, $this->other->id, $this->older->id], array_column($data, 'id'));

        foreach ($data as $charge) {
            $keys = array_keys($charge);
            sort($keys);
            $this->assertSame(self::SUMMARY_KEYS, $keys);
            $this->assertSame(['id', 'full_name', 'phone'], array_keys($charge['patient']));
        }

        $this->assertSame('300.00', $data[0]['amount_paid']);
        $this->assertSame('0.00', $data[0]['balance']);
    }

    public function test_the_filters_combine(): void
    {
        $this->assertSame([$this->newer->id, $this->older->id], $this->ids(['patient_id' => $this->patient->id]));
        $this->assertSame([$this->newer->id, $this->older->id], $this->ids(['doctor_id' => $this->doctor->id]));
        $this->assertSame([$this->newer->id], $this->ids(['status' => 'paid']));
        $this->assertSame([$this->older->id], $this->ids(['patient_id' => $this->patient->id, 'status' => 'unpaid']));
        $this->assertSame([$this->older->id], $this->ids(['from' => '2026-10-19', 'to' => '2026-10-21']));
        $this->assertSame([], $this->ids(['patient_id' => 999999]));
    }

    public function test_the_range_is_required_and_limited(): void
    {
        $this->getJson('/api/v1/charges')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->charges(['from' => '2026-11-02', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->charges(['from' => '2026-08-02', 'to' => '2026-11-01'])->assertOk();
        $this->charges(['from' => '2026-08-01', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->charges(['status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->charges(['patient_id' => 0])->assertUnprocessable()->assertJsonValidationErrors(['patient_id']);
        $this->charges(['per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_the_list_is_paged(): void
    {
        $this->charges(['per_page' => 2])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_lists_carry_no_reasons_or_money_rows(): void
    {
        $discounted = $this->chargeOn('2026-10-26', 3)->create(['discount' => '10.00', 'discount_reason' => 'SYNTHETIC-REASON']);
        Payment::factory()->create(['charge_id' => $discounted->id]);
        Refund::factory()->create(['charge_id' => $discounted->id, 'reason' => 'SYNTHETIC-REASON']);

        $content = $this->charges()->assertOk()->getContent();

        $this->assertStringNotContainsString('SYNTHETIC-REASON', $content);
        $this->assertStringNotContainsString('"payments"', $content);
        $this->assertStringNotContainsString('"refunds"', $content);
    }

    public function test_opening_a_charge_shows_every_payment_and_refund(): void
    {
        $payment = Payment::factory()->create(['charge_id' => $this->newer->id, 'amount' => '300.00', 'method' => 'card']);
        $refund = Refund::factory()->create(['charge_id' => $this->newer->id, 'amount' => '20.00', 'method' => 'cash', 'reason' => 'Synthetic refund']);

        $this->getJson("/api/v1/charges/{$this->newer->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.id', $payment->id)
            ->assertJsonPath('data.payments.0.amount', '300.00')
            ->assertJsonPath('data.payments.0.method', 'card')
            ->assertJsonPath('data.payments.0.received_by.id', $payment->received_by)
            ->assertJsonPath('data.refunds.0.id', $refund->id)
            ->assertJsonPath('data.refunds.0.amount', '20.00')
            ->assertJsonPath('data.refunds.0.reason', 'Synthetic refund')
            ->assertJsonPath('data.refunds.0.refunded_by.id', $refund->refunded_by);

        $this->getJson('/api/v1/charges/999999')->assertNotFound();
    }

    /**
     * FR-019: no medical content, even on a visit that has a finalized record.
     */
    public function test_no_medical_content_is_ever_returned(): void
    {
        Encounter::factory()->forEntry($this->older->queueEntry)->finalized()->create(['diagnosis' => 'SYNTHETIC-PLANTED-TEXT']);

        $responses = [
            $this->charges()->assertOk(),
            $this->getJson("/api/v1/charges/{$this->older->id}")->assertOk(),
            $this->getJson('/api/v1/visits?date=2026-10-20')->assertOk(),
        ];

        foreach ($responses as $response) {
            $this->assertStringNotContainsString('SYNTHETIC-PLANTED-TEXT', $response->getContent());
            $this->assertStringNotContainsString('diagnosis', $response->getContent());
        }
    }

    public function test_only_the_patient_summary_is_shown_when_opened(): void
    {
        $patient = Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->create();
        $charge = Charge::factory()->forEntry(QueueEntry::factory()->seen()->create(['patient_id' => $patient->id]))->create();

        $content = $this->getJson("/api/v1/charges/{$charge->id}")->assertOk()->getContent();

        foreach (['national_id', 'address', 'emergency_contact'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $content);
        }
    }

    public function test_doctors_are_forbidden(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->charges()->assertForbidden();
        $this->getJson("/api/v1/charges/{$this->older->id}")->assertForbidden();
    }

    private function chargeOn(string $date, int $number): ChargeFactory
    {
        return Charge::factory()->forEntry(QueueEntry::factory()->number($number)->seen()->create([
            'doctor_id' => $this->doctor->id,
            'patient_id' => $this->patient->id,
            'date' => $date,
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function charges(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/charges?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-11-01', ...$query]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function ids(array $query): array
    {
        return array_column($this->charges($query)->assertOk()->json('data'), 'id');
    }
}
