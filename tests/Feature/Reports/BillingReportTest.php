<?php

namespace Tests\Feature\Reports;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What each doctor's visits were charged and what is owed, as charges stand now (US2, FR-009–FR-011).
 */
class BillingReportTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-01';

    private const ZEROS = [
        'active_charges' => 0,
        'fees' => '0.00',
        'discounts' => '0.00',
        'amount_due' => '0.00',
        'amount_paid' => '0.00',
        'balance' => '0.00',
        'cancelled_charges' => 0,
        'uncharged_seen_visits' => 0,
    ];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::factory()->create([
            'user_id' => User::factory()->doctor()->create(['name' => $name])->id,
            'title' => 'Consultant',
        ]);
    }

    private function seen(Doctor $doctor): QueueEntry
    {
        return QueueEntry::factory()->number($this->nextNumber++)->seen()->create(['doctor_id' => $doctor->id]);
    }

    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/billing?'.http_build_query(['from' => self::TODAY, 'to' => self::TODAY, ...$query]));
    }

    public function test_the_full_report_has_the_exact_shape_and_figures(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        Charge::factory()->forEntry($this->seen($amira))->fee('400.00')->partiallyPaid('100.00')->create(['discount' => '150.00']);
        Charge::factory()->forEntry($this->seen($amira))->fee('300.00')->paid()->create();
        Charge::factory()->forEntry($this->seen($amira))->fee('300.00')->cancelled()->create();
        $this->seen($amira);
        Charge::factory()->forEntry($this->seen($badr))->fee('200.00')->create();

        $response = $this->report()->assertOk();

        $this->assertSame([
            [
                'doctor' => ['id' => $amira->id, 'name' => 'Amira Synthetic', 'title' => 'Consultant'],
                'active_charges' => 2,
                'fees' => '700.00',
                'discounts' => '150.00',
                'amount_due' => '550.00',
                'amount_paid' => '400.00',
                'balance' => '150.00',
                'cancelled_charges' => 1,
                'uncharged_seen_visits' => 2,
            ],
            [
                'doctor' => ['id' => $badr->id, 'name' => 'Badr Synthetic', 'title' => 'Consultant'],
                'active_charges' => 1,
                'fees' => '200.00',
                'discounts' => '0.00',
                'amount_due' => '200.00',
                'amount_paid' => '0.00',
                'balance' => '200.00',
                'cancelled_charges' => 0,
                'uncharged_seen_visits' => 0,
            ],
        ], $response->json('data'));

        $this->assertSame([
            'active_charges' => 3,
            'fees' => '900.00',
            'discounts' => '150.00',
            'amount_due' => '750.00',
            'amount_paid' => '400.00',
            'balance' => '350.00',
            'cancelled_charges' => 1,
            'uncharged_seen_visits' => 2,
        ], $response->json('totals'));

        $response->assertJsonStructure(['data', 'totals', 'links', 'meta']);
    }

    public function test_a_cancelled_charge_is_only_counted(): void
    {
        Charge::factory()->forEntry($this->seen($this->doctor('Amira Synthetic')))->fee('300.00')->cancelled()->create();

        $this->report()
            ->assertOk()
            ->assertJsonPath('totals.fees', '0.00')
            ->assertJsonPath('totals.active_charges', 0)
            ->assertJsonPath('totals.cancelled_charges', 1);
    }

    public function test_a_fully_discounted_charge_counts_with_nothing_due(): void
    {
        Charge::factory()->forEntry($this->seen($this->doctor('Amira Synthetic')))->fee('300.00')->create(['discount' => '300.00', 'status' => 'paid']);

        $this->report()
            ->assertJsonPath('totals.fees', '300.00')
            ->assertJsonPath('totals.discounts', '300.00')
            ->assertJsonPath('totals.amount_due', '0.00')
            ->assertJsonPath('totals.balance', '0.00');
    }

    public function test_a_charge_counts_on_its_own_date_whenever_it_was_created(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id, 'date' => '2026-10-20']);

        Charge::factory()->forEntry($entry)->create();

        $this->report()->assertJsonPath('totals.active_charges', 0);
        $this->report(['from' => '2026-10-20', 'to' => '2026-10-20'])->assertJsonPath('totals.active_charges', 1);
    }

    public function test_figures_reflect_a_charge_as_it_stands_now(): void
    {
        $charge = Charge::factory()->forEntry($this->seen($this->doctor('Amira Synthetic')))->fee('300.00')->create();

        $this->report()->assertJsonPath('totals.balance', '300.00');

        $charge->update(['discount' => '50.00', 'amount_paid' => '100.00', 'status' => 'partially_paid']);

        $this->report()
            ->assertJsonPath('totals.discounts', '50.00')
            ->assertJsonPath('totals.amount_paid', '100.00')
            ->assertJsonPath('totals.balance', '150.00');
    }

    public function test_only_seen_visits_with_no_active_charge_count_as_uncharged(): void
    {
        $doctor = $this->doctor('Amira Synthetic');

        $this->seen($doctor);
        Charge::factory()->forEntry($this->seen($doctor))->cancelled()->create();

        Charge::factory()->forEntry($this->seen($doctor))->create();
        QueueEntry::factory()->number($this->nextNumber++)->create(['doctor_id' => $doctor->id]);
        QueueEntry::factory()->number($this->nextNumber++)->withDoctor()->create(['doctor_id' => $doctor->id]);
        QueueEntry::factory()->number($this->nextNumber++)->left()->create(['doctor_id' => $doctor->id]);

        $this->report()->assertJsonPath('totals.uncharged_seen_visits', 2);
    }

    public function test_an_active_charge_on_a_left_visit_still_counts(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $left = QueueEntry::factory()->left()->create(['doctor_id' => $doctor->id]);

        Charge::factory()->forEntry($left)->fee('120.00')->create();

        $this->report()
            ->assertJsonPath('totals.fees', '120.00')
            ->assertJsonPath('totals.uncharged_seen_visits', 0);
    }

    public function test_a_doctor_with_only_uncharged_visits_appears_and_one_with_no_activity_does_not(): void
    {
        $withVisit = $this->doctor('Amira Synthetic');
        $this->doctor('Idle Synthetic');
        $this->seen($withVisit);

        $this->report()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $withVisit->id)
            ->assertJsonPath('data.0.uncharged_seen_visits', 1)
            ->assertJsonPath('data.0.fees', '0.00');
    }

    public function test_a_doctor_filter_applies_to_every_figure(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        Charge::factory()->forEntry($this->seen($amira))->fee('100.00')->create();
        $this->seen($amira);
        Charge::factory()->forEntry($this->seen($badr))->fee('200.00')->create();

        $this->report(['doctor_id' => $badr->id])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $badr->id)
            ->assertJsonPath('totals.fees', '200.00')
            ->assertJsonPath('totals.uncharged_seen_visits', 0);
    }

    public function test_a_known_doctor_with_no_activity_gets_no_rows_and_zero_totals(): void
    {
        $this->report(['doctor_id' => $this->doctor('Idle Synthetic')->id])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('totals', self::ZEROS);
    }

    public function test_doctors_are_paged_by_name_and_totals_cover_every_page(): void
    {
        $badr = $this->doctor('Badr Synthetic');
        $amira = $this->doctor('Amira Synthetic');

        Charge::factory()->forEntry($this->seen($badr))->fee('200.00')->create();
        Charge::factory()->forEntry($this->seen($amira))->fee('100.00')->create();

        $first = $this->report(['per_page' => 1, 'page' => 1])->assertOk();
        $second = $this->report(['per_page' => 1, 'page' => 2])->assertOk();

        $first->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $amira->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.fees', '300.00');
        $second->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $badr->id)
            ->assertJsonPath('totals.fees', '300.00');

        $this->assertStringContainsString('from='.self::TODAY, $first->json('links.next'));
    }

    public function test_a_deactivated_doctor_with_activity_appears(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        Charge::factory()->forEntry($this->seen($doctor))->create();
        $doctor->user->update(['is_active' => false]);

        $this->report()->assertJsonPath('data.0.doctor.id', $doctor->id);
    }
}
