<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A patient's history: every finalized record while the patient is on the doctor's list today;
 * otherwise only the doctor's own (US4, FR-017, FR-018, research R5).
 */
class PatientHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'SYNTHETIC-PLANTED-TEXT';

    private Doctor $reader;

    private Doctor $other;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->reader = Doctor::factory()->create();
        $this->other = Doctor::factory()->withSpecialties(2)->create();
        $this->patient = Patient::factory()->create();

        Sanctum::actingAs($this->reader->user);
    }

    public function test_a_patient_on_the_list_shows_all_finalized_records_newest_first(): void
    {
        $this->onList();
        $older = $this->record($this->other, '2026-09-01');
        $newest = $this->record($this->other, '2026-10-20');
        $own = $this->record($this->reader, '2026-10-01');

        $response = $this->history($this->patient->id)->assertOk();

        $this->assertSame([$newest->id, $own->id, $older->id], array_column($response->json('data'), 'id'));

        $item = $response->json('data.0');
        $keys = array_keys($item);
        sort($keys);
        $this->assertSame(['date', 'doctor', 'id'], $keys);
        $this->assertSame('2026-10-20', $item['date']);

        $doctorKeys = array_keys($item['doctor']);
        sort($doctorKeys);
        $this->assertSame(['id', 'name', 'specialties'], $doctorKeys);
        $this->assertSame($this->other->user->name, $item['doctor']['name']);
        $this->assertCount(2, $item['doctor']['specialties']);
    }

    public function test_the_history_has_no_clinical_content(): void
    {
        $this->onList();
        $this->record($this->other, '2026-10-20', ['chief_complaint' => self::PLANTED]);

        $content = $this->history($this->patient->id)->assertOk()->getContent();

        $this->assertStringNotContainsString(self::PLANTED, $content);

        foreach (['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions', 'addenda'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $content);
        }
    }

    public function test_drafts_are_left_out_including_the_doctors_own(): void
    {
        $this->onList();
        $this->draft($this->other);
        $this->draft($this->reader);

        $this->history($this->patient->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_patient_on_the_list_with_no_records_gives_an_empty_page(): void
    {
        $this->onList();

        $this->history($this->patient->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_patient_off_the_list_shows_only_the_doctors_own_records(): void
    {
        $own = $this->record($this->reader, '2026-10-01');
        $this->record($this->other, '2026-10-20');

        $response = $this->history($this->patient->id)->assertOk();

        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
    }

    public function test_a_patient_who_left_counts_as_off_the_list(): void
    {
        QueueEntry::factory()->left()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);
        $this->record($this->other, '2026-10-20');

        $this->history($this->patient->id)->assertNotFound();
    }

    /**
     * FR-013: nothing readable and an unknown patient must be indistinguishable.
     */
    public function test_nothing_readable_looks_exactly_like_an_unknown_patient(): void
    {
        $this->record($this->other, '2026-10-20');
        $this->draft($this->reader);

        $refused = $this->history($this->patient->id)->assertNotFound()->json();
        $unknown = $this->history(999999)->assertNotFound()->json();

        $this->assertSame($unknown, $refused);
        $this->assertSame(['message' => __('encounters::messages.history_not_found')], $refused);
    }

    public function test_a_later_page_of_a_readable_history_is_empty_not_refused(): void
    {
        $this->record($this->reader, '2026-10-01');

        $this->history($this->patient->id, ['page' => 2])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_history_is_paged(): void
    {
        $this->onList();
        $this->record($this->other, '2026-09-01');
        $this->record($this->other, '2026-09-02');
        $this->record($this->other, '2026-09-03');

        $this->history($this->patient->id, ['per_page' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->history($this->patient->id, ['per_page' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_a_doctor_without_a_profile_gets_not_found(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->history($this->patient->id)->assertNotFound();
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->history($this->patient->id)->assertForbidden();
            $this->history(999999)->assertForbidden();
        }
    }

    private function onList(): void
    {
        QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Doctor $doctor, string $date, array $attributes = []): Encounter
    {
        return Encounter::factory()->forEntry($this->visit($doctor, $date))->finalized()->create($attributes);
    }

    private function draft(Doctor $doctor): Encounter
    {
        return Encounter::factory()->forEntry($this->visit($doctor, '2026-10-05'))->complete()->create();
    }

    private function visit(Doctor $doctor, string $date): QueueEntry
    {
        return QueueEntry::factory()->seen()->create([
            'doctor_id' => $doctor->id,
            'patient_id' => $this->patient->id,
            'date' => $date,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function history(int $patientId, array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/doctors/me/patients/{$patientId}/encounters?".http_build_query($query));
    }
}
