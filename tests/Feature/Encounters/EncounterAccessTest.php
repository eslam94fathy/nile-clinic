<?php

namespace Tests\Feature\Encounters;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The approved access rule, end to end through the API: own records at any time; another doctor's
 * only when finalized and while the patient is on this doctor's list today (US4, FR-012, FR-013,
 * SC-003).
 */
class EncounterAccessTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $reader;

    private Patient $patient;

    private Encounter $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->reader = Doctor::factory()->create();
        $this->patient = Patient::factory()->create();

        $otherEntry = QueueEntry::factory()->seen()->create(['patient_id' => $this->patient->id, 'date' => '2026-10-15']);
        $this->record = Encounter::factory()->forEntry($otherEntry)->finalized()->create();
        EncounterAddendum::factory()->create(['encounter_id' => $this->record->id, 'text' => 'Synthetic correction']);

        Sanctum::actingAs($this->reader->user);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedStates(): array
    {
        return ['waiting' => ['waiting'], 'with doctor' => ['withDoctor'], 'seen' => ['seen']];
    }

    #[DataProvider('allowedStates')]
    public function test_a_patient_on_todays_list_makes_finalized_records_readable(string $state): void
    {
        $factory = QueueEntry::factory();
        $factory = $state === 'waiting' ? $factory : $factory->{$state}();
        $factory->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);

        $this->open($this->record)
            ->assertOk()
            ->assertJsonPath('data.diagnosis', 'Synthetic diagnosis')
            ->assertJsonPath('data.addenda.0.text', 'Synthetic correction');
    }

    public function test_no_list_entry_means_not_found(): void
    {
        $this->open($this->record)->assertNotFound();
    }

    public function test_a_patient_who_left_gives_no_access(): void
    {
        QueueEntry::factory()->left()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);

        $this->open($this->record)->assertNotFound();
    }

    public function test_yesterdays_list_gives_no_access_even_if_the_visit_never_finished(): void
    {
        QueueEntry::factory()->seen()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id, 'date' => '2026-10-31']);
        QueueEntry::factory()->number(2)->withDoctor()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id, 'date' => '2026-10-31']);

        $this->open($this->record)->assertNotFound();
    }

    public function test_access_ends_when_the_patient_leaves(): void
    {
        $entry = QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);

        $this->open($this->record)->assertOk();

        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->postJson("/api/v1/queue/{$entry->id}/leave")->assertOk();

        Sanctum::actingAs($this->reader->user);
        $this->open($this->record)->assertNotFound();
    }

    public function test_access_ends_when_the_check_in_is_undone(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create([
            'doctor_id' => $this->reader->id,
            'patient_id' => $this->patient->id,
            'status' => AppointmentStatus::CheckedIn,
        ]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->create();

        $this->open($this->record)->assertOk();

        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->deleteJson("/api/v1/queue/{$entry->id}")->assertNoContent();

        Sanctum::actingAs($this->reader->user);
        $this->open($this->record)->assertNotFound();
    }

    public function test_access_ends_with_the_clinic_day(): void
    {
        QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);

        $this->open($this->record)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 00:01', 'Africa/Cairo'));

        $this->open($this->record)->assertNotFound();
    }

    public function test_another_doctors_draft_stays_private_with_the_patient_on_the_list(): void
    {
        QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);
        $draft = Encounter::factory()->complete()->create(['patient_id' => $this->patient->id]);

        $this->open($draft)->assertNotFound();
    }

    public function test_own_records_are_readable_without_a_list_entry_today(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->reader->id, 'date' => '2026-10-01']);
        $draft = Encounter::factory()->forEntry($entry)->create();
        $secondEntry = QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->reader->id, 'date' => '2026-10-01']);
        $finalized = Encounter::factory()->forEntry($secondEntry)->finalized()->create();

        $this->open($draft)->assertOk();
        $this->open($finalized)->assertOk();
    }

    /**
     * FR-013: a refused record must be indistinguishable from one that does not exist.
     */
    public function test_a_refused_record_looks_exactly_like_an_unknown_one(): void
    {
        $refused = $this->open($this->record)->assertNotFound()->json();
        $unknown = $this->getJson('/api/v1/doctors/me/encounters/999999')->assertNotFound()->json();

        $this->assertSame($unknown, $refused);
        $this->assertSame(['message' => __('encounters::messages.encounter_not_found')], $refused);
    }

    public function test_a_reader_cannot_change_a_record(): void
    {
        $body = [
            'chief_complaint' => 'Changed',
            'examination_findings' => null,
            'diagnosis' => null,
            'treatment_plan' => null,
            'prescriptions' => [],
        ];
        $url = "/api/v1/doctors/me/encounters/{$this->record->id}";

        $this->putJson($url, $body)->assertNotFound();

        QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $this->patient->id]);

        $this->putJson($url, $body)->assertForbidden();
        $this->assertSame('Synthetic complaint', $this->record->fresh()->chief_complaint);
    }

    private function open(Encounter $encounter): TestResponse
    {
        return $this->getJson("/api/v1/doctors/me/encounters/{$encounter->id}");
    }
}
