<?php

namespace Tests\Feature\Payments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reception charges a visit at the doctor's fee at that moment; a charge never affects the queue (US2,
 * FR-003–FR-006, SC-003).
 */
class ChargeVisitTest extends TestCase
{
    use RefreshDatabase;

    private const CHARGE_KEYS = [
        'amount_due', 'amount_paid', 'balance', 'cancel_reason', 'cancelled_at', 'cancelled_by', 'charged_at', 'charged_by',
        'date', 'discount', 'discount_reason', 'discounted_at', 'discounted_by', 'doctor', 'fee', 'id', 'patient', 'payments',
        'refunds', 'status', 'visit_id',
    ];

    private Doctor $doctor;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $this->doctor->id, 'amount' => '350.00']);
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);
    }

    public function test_charging_creates_an_unpaid_charge_at_the_doctors_fee(): void
    {
        $patient = Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->create();
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id]);

        $response = $this->charge($entry->id)->assertCreated();

        $keys = array_keys($response->json('data'));
        sort($keys);
        $this->assertSame(self::CHARGE_KEYS, $keys);

        $response->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.visit_id', $entry->id)
            ->assertJsonPath('data.date', '2026-11-01')
            ->assertJsonPath('data.fee', '350.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.amount_due', '350.00')
            ->assertJsonPath('data.amount_paid', '0.00')
            ->assertJsonPath('data.balance', '350.00')
            ->assertJsonPath('data.doctor', ['id' => $this->doctor->id, 'name' => $this->doctor->user->name, 'title' => $this->doctor->title])
            ->assertJsonPath('data.patient', ['id' => $patient->id, 'full_name' => $patient->full_name, 'phone' => $patient->phone])
            ->assertJsonPath('data.charged_by', ['id' => $this->receptionist->id, 'name' => $this->receptionist->name])
            ->assertJsonPath('data.charged_at', now()->toISOString())
            ->assertJsonPath('data.payments', [])
            ->assertJsonPath('data.refunds', [])
            ->assertJsonPath('data.discount_reason', null)
            ->assertJsonPath('data.cancelled_at', null);

        $this->assertSame(1, Charge::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function chargeableStates(): array
    {
        return ['waiting' => ['waiting'], 'with doctor' => ['withDoctor'], 'seen' => ['seen']];
    }

    #[DataProvider('chargeableStates')]
    public function test_a_visit_can_be_charged_in_each_state(string $state): void
    {
        $factory = QueueEntry::factory();
        $factory = $state === 'waiting' ? $factory : $factory->{$state}();

        $this->charge($factory->create(['doctor_id' => $this->doctor->id])->id)->assertCreated();
    }

    public function test_a_visit_can_be_charged_on_a_later_day(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id, 'date' => '2026-10-20']);

        $this->charge($entry->id)->assertCreated()->assertJsonPath('data.date', '2026-10-20');
    }

    public function test_booked_visits_and_walk_ins_work_the_same_way(): void
    {
        $walkIn = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id]);
        $booked = QueueEntry::factory()->number(2)->forAppointment($appointment)->seen()->create();

        $this->charge($walkIn->id)->assertCreated();
        $this->charge($booked->id)->assertCreated();

        $this->assertSame(2, Charge::query()->count());
    }

    public function test_an_administrator_can_charge(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->charge(QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id])->id)->assertCreated();
    }

    public function test_a_left_visit_is_refused(): void
    {
        $entry = QueueEntry::factory()->left()->create(['doctor_id' => $this->doctor->id]);

        $this->charge($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.visit_left'));

        $this->assertSame(0, Charge::query()->count());
    }

    public function test_a_doctor_with_no_fee_is_refused(): void
    {
        $entry = QueueEntry::factory()->seen()->create();

        $this->charge($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.no_fee'));

        $this->assertSame(0, Charge::query()->count());
    }

    public function test_a_second_charge_is_refused(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);

        $this->charge($entry->id)->assertCreated();
        $this->charge($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.already_charged'));

        $this->assertSame(1, Charge::query()->count());
    }

    public function test_an_unknown_visit_is_not_found(): void
    {
        $this->charge(999999)->assertNotFound()->assertExactJson(['message' => __('payments::messages.visit_not_found')]);
    }

    /**
     * SC-003: a later fee change never alters an existing charge.
     */
    public function test_a_charge_keeps_its_fee_when_the_doctors_fee_changes(): void
    {
        $first = QueueEntry::factory()->number(1)->seen()->create(['doctor_id' => $this->doctor->id]);
        $second = QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]);
        $charge = $this->charge($first->id)->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->putJson("/api/v1/doctors/{$this->doctor->id}/fee", ['amount' => '500.00'])->assertOk();

        $this->getJson("/api/v1/charges/{$charge}")->assertOk()->assertJsonPath('data.fee', '350.00')->assertJsonPath('data.balance', '350.00');
        $this->charge($second->id)->assertCreated()->assertJsonPath('data.fee', '500.00');
    }

    public function test_charging_changes_neither_the_visit_nor_the_appointment(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create([
            'doctor_id' => $this->doctor->id,
            'status' => AppointmentStatus::CheckedIn,
        ]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->create();

        $this->charge($entry->id)->assertCreated();

        $this->assertSame(QueueStatus::Waiting, $entry->fresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_an_unpaid_visit_moves_through_the_queue_as_usual(): void
    {
        $entry = QueueEntry::factory()->create(['doctor_id' => $this->doctor->id]);
        $this->charge($entry->id)->assertCreated();

        Sanctum::actingAs($this->doctor->user);

        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertOk()->assertJsonPath('data.id', $entry->id);
        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk()->assertJsonPath('data.status', 'seen');
    }

    public function test_doctors_are_forbidden(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        Sanctum::actingAs($this->doctor->user);

        $this->charge($entry->id)->assertForbidden();
        $this->charge(999999)->assertForbidden();

        $this->assertSame(0, Charge::query()->count());
    }

    private function charge(int $visitId): TestResponse
    {
        return $this->postJson("/api/v1/visits/{$visitId}/charge");
    }
}
