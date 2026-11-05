<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\QueueEntryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor finds their visits and which still lack a finalized record, with no clinical content (US3,
 * FR-016, FR-018, research R9).
 */
class OwnVisitListTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'SYNTHETIC-PLANTED-TEXT';

    private const VISIT_KEYS = ['arrived_at', 'called_at', 'date', 'encounter_id', 'finished_at', 'id', 'patient', 'record_status', 'walk_in'];

    private Doctor $doctor;

    private QueueEntry $noRecord;

    private QueueEntry $withDraft;

    private QueueEntry $withFinalized;

    private Encounter $draft;

    private Encounter $finalized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();

        // Created out of order, so the numbers alone cannot produce the expected list order.
        $this->withFinalized = $this->entry(1, 600)->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->noRecord = $this->entry(2, 540)->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->withDraft = $this->entry(3, 560)->withDoctor()->create(['doctor_id' => $this->doctor->id]);

        $this->draft = Encounter::factory()->forEntry($this->withDraft)->create(['chief_complaint' => self::PLANTED]);
        $this->finalized = Encounter::factory()->forEntry($this->withFinalized)->finalized()->create(['diagnosis' => self::PLANTED]);

        // Never listed: waiting, left, and another doctor's visit.
        $this->entry(4, 700)->create(['doctor_id' => $this->doctor->id]);
        $this->entry(5, 710)->left()->create(['doctor_id' => $this->doctor->id]);
        Encounter::factory()->finalized()->create();

        Sanctum::actingAs($this->doctor->user);
    }

    public function test_the_list_has_the_doctors_visits_in_queue_order_with_their_record_status(): void
    {
        $patient = $this->noRecord->patient;

        $response = $this->visits()->assertOk();

        $this->assertSame(
            [$this->noRecord->id, $this->withDraft->id, $this->withFinalized->id],
            array_column($response->json('data'), 'id'),
        );

        foreach ($response->json('data') as $visit) {
            $keys = array_keys($visit);
            sort($keys);
            $this->assertSame(self::VISIT_KEYS, $keys);
        }

        $response->assertJsonPath('data.0.record_status', 'none')
            ->assertJsonPath('data.0.encounter_id', null)
            ->assertJsonPath('data.0.date', '2026-11-01')
            ->assertJsonPath('data.0.walk_in', true)
            ->assertJsonPath('data.0.patient', [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'sex' => $patient->sex->value,
                'date_of_birth' => $patient->date_of_birth->toDateString(),
            ])
            ->assertJsonPath('data.1.record_status', 'draft')
            ->assertJsonPath('data.1.encounter_id', $this->draft->id)
            ->assertJsonPath('data.2.record_status', 'finalized')
            ->assertJsonPath('data.2.encounter_id', $this->finalized->id);
    }

    public function test_the_list_filters_by_record_status(): void
    {
        $expected = [
            'none' => $this->noRecord->id,
            'draft' => $this->withDraft->id,
            'finalized' => $this->withFinalized->id,
        ];

        foreach ($expected as $status => $id) {
            $this->assertSame([$id], array_column($this->visits(['record_status' => $status])->assertOk()->json('data'), 'id'));
        }

        $this->visits(['record_status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors(['record_status']);
    }

    public function test_the_list_filters_by_patient(): void
    {
        $this->assertSame(
            [$this->withDraft->id],
            array_column($this->visits(['patient_id' => $this->withDraft->patient_id])->assertOk()->json('data'), 'id'),
        );

        $this->visits(['patient_id' => 999999])->assertOk()->assertJsonCount(0, 'data');
        $this->visits(['patient_id' => Patient::factory()->create()->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_range_is_required_and_limited(): void
    {
        $this->getJson('/api/v1/doctors/me/visits')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->visits(['from' => '2026-11-02', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->visits(['from' => '2026-08-02', 'to' => '2026-11-01'])->assertOk();
        $this->visits(['from' => '2026-08-01', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_the_range_selects_the_days(): void
    {
        $this->entry(1, 540)->seen()->create(['doctor_id' => $this->doctor->id, 'date' => '2026-10-31']);

        $this->visits()->assertJsonCount(3, 'data');
        $this->visits(['from' => '2026-10-31', 'to' => '2026-10-31'])->assertJsonCount(1, 'data');
        $this->visits(['from' => '2026-10-31', 'to' => '2026-11-01'])
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.date', '2026-10-31');
    }

    public function test_the_list_is_paged(): void
    {
        $this->visits(['per_page' => 2])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->visits(['per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_the_list_has_no_clinical_content(): void
    {
        $response = $this->visits()->assertOk();

        $this->assertStringNotContainsString(self::PLANTED, $response->getContent());

        foreach (['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions', 'addenda'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $response->getContent());
        }
    }

    public function test_a_doctor_without_a_profile_gets_not_found(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->visits()->assertNotFound();
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->visits()->assertForbidden();
        }
    }

    private function entry(int $number, int $minute): QueueEntryFactory
    {
        return QueueEntry::factory()->number($number)->state(['order_minute' => $minute]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function visits(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/doctors/me/visits?'.http_build_query([
            'from' => '2026-11-01',
            'to' => '2026-11-01',
            ...$query,
        ]));
    }
}
