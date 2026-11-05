<?php

namespace Tests\Feature\Encounters;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
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
 * Every role against every endpoint: only active doctors use medical records (SC-003, FR-014, FR-020).
 */
class EncountersAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private const ENDPOINTS = ['visits', 'start', 'show', 'update', 'finalize', 'addendum', 'history'];

    private const CONTENT = [
        'chief_complaint' => 'Synthetic complaint',
        'examination_findings' => null,
        'diagnosis' => 'Synthetic diagnosis',
        'treatment_plan' => null,
        'prescriptions' => [],
    ];

    private Doctor $doctor;

    private QueueEntry $openVisit;

    private Encounter $draft;

    private Encounter $finalized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();

        $this->openVisit = QueueEntry::factory()->number(1)->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->draft = Encounter::factory()
            ->forEntry(QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->complete()
            ->create();
        $this->finalized = Encounter::factory()
            ->forEntry(QueueEntry::factory()->number(3)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->finalized()
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
    public function test_administrators_and_receptionists_are_forbidden_and_nothing_changes(string $endpoint): void
    {
        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->hit($endpoint)->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);
        }

        $this->assertNothingChanged();
    }

    #[DataProvider('endpoints')]
    public function test_a_doctor_succeeds(string $endpoint): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->assertContains($this->hit($endpoint)->getStatusCode(), [200, 201]);
    }

    #[DataProvider('endpoints')]
    public function test_no_token_is_unauthenticated(string $endpoint): void
    {
        $this->hit($endpoint)->assertUnauthorized();
        $this->assertNothingChanged();
    }

    #[DataProvider('endpoints')]
    public function test_a_deactivated_doctor_with_a_real_token_is_unauthenticated(string $endpoint): void
    {
        $token = $this->signIn($this->doctor->user);
        $this->doctor->user->update(['is_active' => false]);

        $this->usingToken($token);

        $this->hit($endpoint)->assertUnauthorized();
        $this->assertNothingChanged();
    }

    public function test_reception_gets_forbidden_before_validation_and_lookup(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->putJson('/api/v1/doctors/me/encounters/999999', [])->assertForbidden();
        $this->postJson('/api/v1/doctors/me/encounters/999999/addenda', [])->assertForbidden();
        $this->postJson('/api/v1/doctors/me/encounters/999999/finalize')->assertForbidden();
        $this->getJson('/api/v1/doctors/me/encounters/999999')->assertForbidden();
        $this->postJson('/api/v1/doctors/me/visits/999999/encounter')->assertForbidden();
        $this->getJson('/api/v1/doctors/me/visits')->assertForbidden();
        $this->getJson('/api/v1/doctors/me/patients/999999/encounters?per_page=999')->assertForbidden();
    }

    /**
     * FR-014: reception and administrators must not even learn whether a visit has a record.
     */
    public function test_other_features_responses_carry_no_record_data(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create([
            'doctor_id' => $this->doctor->id,
            'status' => AppointmentStatus::Completed,
        ]);
        $entry = QueueEntry::factory()->number(4)->forAppointment($appointment)->seen()->create();
        $record = Encounter::factory()->forEntry($entry)->finalized()->create(['diagnosis' => 'SYNTHETIC-PLANTED-TEXT']);
        EncounterAddendum::factory()->create(['encounter_id' => $record->id, 'text' => 'SYNTHETIC-PLANTED-TEXT']);

        Sanctum::actingAs(User::factory()->receptionist()->create());

        $responses = [
            $this->getJson('/api/v1/queue')->assertOk(),
            $this->getJson("/api/v1/doctors/{$this->doctor->id}/queue")->assertOk(),
            $this->getJson("/api/v1/appointments/{$appointment->id}")->assertOk(),
            $this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-01')->assertOk(),
            $this->getJson("/api/v1/patients/{$record->patient_id}")->assertOk(),
        ];

        Sanctum::actingAs($this->doctor->user);

        $responses[] = $this->getJson('/api/v1/doctors/me/queue')->assertOk();
        $responses[] = $this->getJson("/api/v1/doctors/me/appointments/{$appointment->id}")->assertOk();

        foreach ($responses as $response) {
            $this->assertStringNotContainsString('SYNTHETIC-PLANTED-TEXT', $response->getContent());
            $this->assertStringNotContainsString('encounter', $response->getContent());
            $this->assertStringNotContainsString('diagnosis', $response->getContent());
        }
    }

    private function hit(string $endpoint): TestResponse
    {
        $me = '/api/v1/doctors/me';

        return match ($endpoint) {
            'visits' => $this->getJson("{$me}/visits?from=2026-11-01&to=2026-11-01"),
            'start' => $this->postJson("{$me}/visits/{$this->openVisit->id}/encounter"),
            'show' => $this->getJson("{$me}/encounters/{$this->draft->id}"),
            'update' => $this->putJson("{$me}/encounters/{$this->draft->id}", self::CONTENT),
            'finalize' => $this->postJson("{$me}/encounters/{$this->draft->id}/finalize"),
            'addendum' => $this->postJson("{$me}/encounters/{$this->finalized->id}/addenda", ['text' => 'Synthetic correction']),
            'history' => $this->getJson("{$me}/patients/{$this->finalized->patient_id}/encounters"),
        };
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame(2, Encounter::query()->count());
        $this->assertSame(0, EncounterAddendum::query()->count());
        $this->assertSame('Synthetic complaint', $this->draft->fresh()->chief_complaint);
        $this->assertSame('draft', $this->draft->fresh()->status->value);
    }
}
