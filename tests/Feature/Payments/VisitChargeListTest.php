<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\QueueEntryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception sees a date's visits, which are not yet charged, and what is owed (US3, FR-016, FR-019,
 * research R8).
 */
class VisitChargeListTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['balance', 'charge_id', 'charge_status', 'date', 'doctor', 'id', 'patient', 'status', 'walk_in'];

    private Doctor $doctorA;

    private Doctor $doctorB;

    private QueueEntry $none;

    private QueueEntry $unpaid;

    private QueueEntry $partial;

    private QueueEntry $paid;

    private QueueEntry $cancelled;

    private QueueEntry $leftBare;

    private QueueEntry $leftCharged;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        [$first, $second] = Doctor::factory()->count(2)->create()->sortBy('id')->values()->all();
        $this->doctorA = $first;
        $this->doctorB = $second;

        // Created out of order, so the numbers alone cannot produce the expected list order.
        $this->unpaid = $this->entry($this->doctorA, 1, 600)->seen()->create();
        $this->none = $this->entry($this->doctorA, 2, 540)->create();
        $this->partial = $this->entry($this->doctorA, 3, 620)->seen()->create();
        $this->paid = $this->entry($this->doctorB, 1, 540)->seen()->create();
        $this->cancelled = $this->entry($this->doctorB, 2, 560)->seen()->create();
        $this->leftBare = $this->entry($this->doctorB, 3, 580)->left()->create();
        $this->leftCharged = $this->entry($this->doctorB, 4, 600)->left()->create();

        Charge::factory()->forEntry($this->unpaid)->fee('300.00')->create();
        Charge::factory()->forEntry($this->partial)->fee('300.00')->partiallyPaid('100.10')->create();
        Charge::factory()->forEntry($this->paid)->fee('300.00')->paid()->create();
        Charge::factory()->forEntry($this->cancelled)->fee('300.00')->cancelled()->create();
        Charge::factory()->forEntry($this->leftCharged)->fee('300.00')->create();

        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_the_list_is_by_doctor_then_queue_order_with_exactly_the_contract_fields(): void
    {
        $data = $this->visits()->assertOk()->json('data');

        $this->assertSame(
            [$this->none->id, $this->unpaid->id, $this->partial->id, $this->paid->id, $this->cancelled->id, $this->leftCharged->id],
            array_column($data, 'id'),
        );

        foreach ($data as $visit) {
            $keys = array_keys($visit);
            sort($keys);
            $this->assertSame(self::KEYS, $keys);
            $this->assertSame('2026-11-01', $visit['date']);
        }

        $this->assertSame(['none', null, null], [$data[0]['charge_status'], $data[0]['charge_id'], $data[0]['balance']]);
        $this->assertSame(['unpaid', '300.00'], [$data[1]['charge_status'], $data[1]['balance']]);
        $this->assertSame(['partially_paid', '199.90'], [$data[2]['charge_status'], $data[2]['balance']]);
        $this->assertSame(['paid', '0.00'], [$data[3]['charge_status'], $data[3]['balance']]);
        $this->assertSame(['id' => $this->doctorA->id, 'name' => $this->doctorA->user->name, 'title' => $this->doctorA->title], $data[0]['doctor']);
        $this->assertSame('waiting', $data[0]['status']);
        $this->assertTrue($data[0]['walk_in']);
    }

    public function test_each_charge_status_filter(): void
    {
        $expected = [
            'none' => [$this->none->id, $this->cancelled->id],
            'unpaid' => [$this->unpaid->id, $this->leftCharged->id],
            'partially_paid' => [$this->partial->id],
            'paid' => [$this->paid->id],
        ];

        foreach ($expected as $status => $ids) {
            $this->assertSame($ids, array_column($this->visits(['charge_status' => $status])->assertOk()->json('data'), 'id'), $status);
        }

        $this->visits(['charge_status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors(['charge_status']);
        $this->visits(['charge_status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors(['charge_status']);
    }

    /**
     * A left visit cannot be newly charged, so it appears only if a charge still needs settling.
     */
    public function test_a_left_visit_appears_only_with_an_active_charge(): void
    {
        $ids = array_column($this->visits()->json('data'), 'id');

        $this->assertNotContains($this->leftBare->id, $ids);
        $this->assertContains($this->leftCharged->id, $ids);
    }

    public function test_the_doctor_filter(): void
    {
        $this->assertSame(
            [$this->none->id, $this->unpaid->id, $this->partial->id],
            array_column($this->visits(['doctor_id' => $this->doctorA->id])->assertOk()->json('data'), 'id'),
        );

        $this->visits(['doctor_id' => 0])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_only_the_date_requested_is_listed(): void
    {
        $other = $this->entry($this->doctorA, 1, 540)->seen()->create(['date' => '2026-10-31']);

        $this->assertNotContains($other->id, array_column($this->visits()->json('data'), 'id'));
        $this->assertSame([$other->id], array_column($this->visits(['date' => '2026-10-31'])->json('data'), 'id'));
    }

    public function test_the_date_is_required_and_valid(): void
    {
        $this->getJson('/api/v1/visits')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->visits(['date' => '01-11-2026'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
    }

    public function test_the_list_is_paged(): void
    {
        $this->visits(['per_page' => 2])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 6);
        $this->visits(['per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_only_the_patient_summary_is_shown(): void
    {
        $patient = Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->create();
        $entry = $this->entry($this->doctorA, 9, 900)->create(['patient_id' => $patient->id]);

        $response = $this->visits(['doctor_id' => $this->doctorA->id])->assertOk();
        $visit = collect($response->json('data'))->firstWhere('id', $entry->id);

        $this->assertSame(['id' => $patient->id, 'full_name' => $patient->full_name, 'phone' => $patient->phone], $visit['patient']);

        foreach (['national_id', 'address', 'emergency_contact'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $response->getContent());
        }
    }

    public function test_doctors_are_forbidden(): void
    {
        Sanctum::actingAs($this->doctorA->user);

        $this->visits()->assertForbidden();
    }

    private function entry(Doctor $doctor, int $number, int $minute): QueueEntryFactory
    {
        return QueueEntry::factory()->number($number)->state(['doctor_id' => $doctor->id, 'order_minute' => $minute]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function visits(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/visits?'.http_build_query(['date' => '2026-11-01', ...$query]));
    }
}
