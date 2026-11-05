<?php

namespace Tests\Feature\Encounters;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
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
 * A doctor starts the record of one of their own visits (US1, FR-001, FR-002, FR-005).
 */
class StartEncounterTest extends TestCase
{
    use RefreshDatabase;

    private const ENCOUNTER_KEYS = [
        'addenda', 'chief_complaint', 'date', 'diagnosis', 'doctor', 'examination_findings', 'finalized_at',
        'id', 'last_saved_at', 'patient', 'prescriptions', 'started_at', 'status', 'treatment_plan', 'visit_id',
    ];

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        Sanctum::actingAs($this->doctor->user);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function startableStates(): array
    {
        return ['with doctor' => ['withDoctor'], 'seen' => ['seen']];
    }

    #[DataProvider('startableStates')]
    public function test_starting_creates_an_empty_draft(string $state): void
    {
        $patient = Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->create();
        $entry = QueueEntry::factory()->{$state}()->create(['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id]);

        $response = $this->start($entry->id)->assertCreated();
        $data = $response->json('data');

        $keys = array_keys($data);
        sort($keys);
        $this->assertSame(self::ENCOUNTER_KEYS, $keys);

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.visit_id', $entry->id)
            ->assertJsonPath('data.date', '2026-11-01')
            ->assertJsonPath('data.doctor', [
                'id' => $this->doctor->id,
                'name' => $this->doctor->user->name,
                'title' => $this->doctor->title,
            ])
            ->assertJsonPath('data.patient', [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'sex' => $patient->sex->value,
                'date_of_birth' => $patient->date_of_birth->toDateString(),
            ])
            ->assertJsonPath('data.chief_complaint', null)
            ->assertJsonPath('data.examination_findings', null)
            ->assertJsonPath('data.diagnosis', null)
            ->assertJsonPath('data.treatment_plan', null)
            ->assertJsonPath('data.prescriptions', [])
            ->assertJsonPath('data.addenda', [])
            ->assertJsonPath('data.finalized_at', null);

        $this->assertSame(1, Encounter::query()->count());
    }

    public function test_walk_ins_and_booked_visits_work_the_same_way(): void
    {
        $walkIn = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id]);
        $booked = QueueEntry::factory()->number(2)->forAppointment($appointment)->seen()->create();

        $this->start($walkIn->id)->assertCreated();
        $this->start($booked->id)->assertCreated();

        $this->assertSame(2, Encounter::query()->count());
    }

    public function test_a_record_can_start_on_a_later_day(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id, 'date' => '2026-10-31']);

        $this->start($entry->id)->assertCreated()->assertJsonPath('data.date', '2026-10-31');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unstartedStates(): array
    {
        return ['waiting' => ['waiting'], 'left' => ['left']];
    }

    #[DataProvider('unstartedStates')]
    public function test_a_visit_that_has_not_started_is_refused(string $state): void
    {
        $factory = QueueEntry::factory();
        $factory = $state === 'waiting' ? $factory : $factory->{$state}();
        $entry = $factory->create(['doctor_id' => $this->doctor->id]);

        $this->start($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('encounters::messages.visit_not_started'));

        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_another_doctors_visit_and_an_unknown_visit_are_not_found(): void
    {
        $other = QueueEntry::factory()->seen()->create();

        $this->start($other->id)->assertNotFound()->assertExactJson(['message' => __('encounters::messages.visit_not_found')]);
        $this->start(999999)->assertNotFound()->assertExactJson(['message' => __('encounters::messages.visit_not_found')]);

        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_a_second_start_is_refused(): void
    {
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);

        $this->start($entry->id)->assertCreated();
        $this->start($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('encounters::messages.already_started'));

        $this->assertSame(1, Encounter::query()->count());
    }

    public function test_starting_changes_neither_the_visit_nor_the_appointment(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id, 'status' => AppointmentStatus::CheckedIn]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->withDoctor()->create();

        $this->start($entry->id)->assertCreated();

        $this->assertSame(QueueStatus::WithDoctor, $entry->fresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_finishing_needs_no_record_and_the_record_can_start_afterwards(): void
    {
        $entry = QueueEntry::factory()->withDoctor()->create(['doctor_id' => $this->doctor->id]);

        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk()->assertJsonPath('data.status', 'seen');

        $this->assertSame(0, Encounter::query()->count());
        $this->start($entry->id)->assertCreated();
    }

    public function test_a_doctor_without_a_profile_gets_not_found(): void
    {
        $entry = QueueEntry::factory()->seen()->create();
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->start($entry->id)->assertNotFound();
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        $entry = QueueEntry::factory()->seen()->create();

        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->start($entry->id)->assertForbidden();
        }

        $this->assertSame(0, Encounter::query()->count());
    }

    private function start(int $visitId): TestResponse
    {
        return $this->postJson("/api/v1/doctors/me/visits/{$visitId}/encounter");
    }
}
