<?php

namespace Tests\Feature\Reports;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Average wait and consultation, and counts of medical records with no clinical content (US4,
 * FR-015–FR-017).
 */
class TimesReportTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-01';

    private const ARRIVED = '2026-11-01 08:00:00';

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::factory()->create(['user_id' => User::factory()->doctor()->create(['name' => $name])->id]);
    }

    /**
     * A seen visit that waited and took these numbers of seconds.
     */
    private function seenVisit(Doctor $doctor, int $waitSeconds, int $consultationSeconds): QueueEntry
    {
        $arrived = CarbonImmutable::parse(self::ARRIVED, 'UTC');
        $called = $arrived->addSeconds($waitSeconds);

        return QueueEntry::factory()->number($this->nextNumber++)->seen()->create([
            'doctor_id' => $doctor->id,
            'arrived_at' => $arrived,
            'called_at' => $called,
            'finished_at' => $called->addSeconds($consultationSeconds),
        ]);
    }

    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/times?'.http_build_query(['from' => self::TODAY, 'to' => self::TODAY, ...$query]));
    }

    public function test_the_full_report_has_the_exact_shape_and_figures(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        $this->seenVisit($amira, 600, 1200);
        $this->seenVisit($amira, 1200, 600);
        $this->seenVisit($badr, 60, 120);

        $response = $this->report()->assertOk()->assertJsonStructure(['data', 'totals', 'links', 'meta']);

        $this->assertSame([
            [
                'doctor' => ['id' => $amira->id, 'name' => 'Amira Synthetic', 'title' => $amira->title],
                'wait' => ['average_minutes' => 15, 'visits' => 2],
                'consultation' => ['average_minutes' => 15, 'visits' => 2],
                'records' => ['finalized' => 0, 'draft' => 0, 'none' => 2],
            ],
            [
                'doctor' => ['id' => $badr->id, 'name' => 'Badr Synthetic', 'title' => $badr->title],
                'wait' => ['average_minutes' => 1, 'visits' => 1],
                'consultation' => ['average_minutes' => 2, 'visits' => 1],
                'records' => ['finalized' => 0, 'draft' => 0, 'none' => 1],
            ],
        ], $response->json('data'));

        // 1860 s / 3 = 10.3 minutes, and 1920 s / 3 = 10.7 minutes.
        $this->assertSame([
            'wait' => ['average_minutes' => 10, 'visits' => 3],
            'consultation' => ['average_minutes' => 11, 'visits' => 3],
            'records' => ['finalized' => 0, 'draft' => 0, 'none' => 3],
        ], $response->json('totals'));
    }

    public function test_an_average_rounds_to_the_nearest_minute(): void
    {
        $this->seenVisit($this->doctor('Amira Synthetic'), 90, 89);

        $this->report()
            ->assertJsonPath('totals.wait.average_minutes', 2)
            ->assertJsonPath('totals.consultation.average_minutes', 1);
    }

    public function test_overall_is_not_an_average_of_the_doctors_averages(): void
    {
        $this->seenVisit($this->doctor('Amira Synthetic'), 600, 600);

        $badr = $this->doctor('Badr Synthetic');
        $this->seenVisit($badr, 120, 120);
        $this->seenVisit($badr, 120, 120);
        $this->seenVisit($badr, 120, 120);

        // (10 + 2 + 2 + 2) / 4 = 4, where an average of the averages would give 6.
        $this->report()
            ->assertJsonPath('totals.wait.average_minutes', 4)
            ->assertJsonPath('totals.consultation.average_minutes', 4);
    }

    public function test_visits_missing_a_time_are_left_out_of_that_average(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $arrived = CarbonImmutable::parse(self::ARRIVED, 'UTC');

        QueueEntry::factory()->number($this->nextNumber++)->create(['doctor_id' => $doctor->id, 'arrived_at' => $arrived]);
        QueueEntry::factory()->number($this->nextNumber++)->withDoctor()->create([
            'doctor_id' => $doctor->id,
            'arrived_at' => $arrived,
            'called_at' => $arrived->addMinutes(4),
        ]);
        QueueEntry::factory()->number($this->nextNumber++)->left()->create(['doctor_id' => $doctor->id, 'arrived_at' => $arrived]);

        $this->report()
            ->assertJsonPath('data.0.wait', ['average_minutes' => 4, 'visits' => 1])
            ->assertJsonPath('data.0.consultation', ['average_minutes' => null, 'visits' => 0]);
    }

    public function test_records_count_seen_visits_and_add_up(): void
    {
        $doctor = $this->doctor('Amira Synthetic');

        Encounter::factory()->forEntry($this->seenVisit($doctor, 60, 60))->finalized()->create();
        Encounter::factory()->forEntry($this->seenVisit($doctor, 60, 60))->create();
        $this->seenVisit($doctor, 60, 60);

        $this->report()
            ->assertJsonPath('data.0.records', ['finalized' => 1, 'draft' => 1, 'none' => 1])
            ->assertJsonPath('totals.records', ['finalized' => 1, 'draft' => 1, 'none' => 1]);
    }

    public function test_a_draft_on_a_visit_still_with_the_doctor_is_not_counted(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $visit = QueueEntry::factory()->number($this->nextNumber++)->withDoctor()->create(['doctor_id' => $doctor->id]);

        Encounter::factory()->forEntry($visit)->create();

        $this->report()->assertJsonPath('totals.records', ['finalized' => 0, 'draft' => 0, 'none' => 0]);
    }

    public function test_a_doctor_filter_applies_to_every_figure(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        $this->seenVisit($amira, 600, 600);
        $this->seenVisit($badr, 120, 120);

        $this->report(['doctor_id' => $badr->id])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.wait', ['average_minutes' => 2, 'visits' => 1])
            ->assertJsonPath('totals.records.none', 1);
    }

    public function test_doctors_are_paged_by_name_and_totals_cover_every_page(): void
    {
        $this->seenVisit($this->doctor('Badr Synthetic'), 60, 60);
        $amira = $this->doctor('Amira Synthetic');
        $this->seenVisit($amira, 60, 60);

        $this->report(['per_page' => 1])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $amira->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.wait.visits', 2);
    }

    public function test_a_period_with_no_visits_has_empty_averages(): void
    {
        $this->report(['from' => '2026-10-01', 'to' => '2026-10-01'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('totals', [
                'wait' => ['average_minutes' => null, 'visits' => 0],
                'consultation' => ['average_minutes' => null, 'visits' => 0],
                'records' => ['finalized' => 0, 'draft' => 0, 'none' => 0],
            ]);
    }
}
