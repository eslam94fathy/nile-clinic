<?php

namespace Tests\Feature\Encounters;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The author locks a complete draft; nothing overwrites it afterwards (US2, FR-007, FR-008, research
 * R8).
 */
class FinalizeEncounterTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        Sanctum::actingAs($this->doctor->user);
    }

    public function test_a_complete_draft_is_finalized(): void
    {
        $draft = $this->draft();

        $this->finalize($draft)
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.finalized_at', now()->toISOString())
            ->assertJsonPath('data.diagnosis', 'Synthetic diagnosis');

        $this->assertSame(EncounterStatus::Finalized, $draft->fresh()->status);
    }

    public function test_a_record_with_no_lines_can_be_finalized(): void
    {
        $this->finalize($this->draft(['prescriptions' => []]))->assertOk();
        $this->finalize($this->draft(['prescriptions' => null], 2))->assertOk();
    }

    public function test_instructions_are_optional(): void
    {
        $this->finalize($this->draft(['prescriptions' => [$this->line(['instructions' => null])]]))->assertOk();
    }

    public function test_a_missing_chief_complaint_and_diagnosis_are_named(): void
    {
        $draft = $this->draft(['chief_complaint' => null, 'diagnosis' => null]);

        $this->finalize($draft)
            ->assertUnprocessable()
            ->assertJsonPath('errors.chief_complaint.0', __('encounters::messages.required_to_finalize', ['attribute' => __('encounters::attributes.chief_complaint')]))
            ->assertJsonPath('errors.diagnosis.0', __('encounters::messages.required_to_finalize', ['attribute' => __('encounters::attributes.diagnosis')]));

        $this->assertSame(EncounterStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->finalized_at);
    }

    public function test_incomplete_lines_are_named_by_position(): void
    {
        $draft = $this->draft(['prescriptions' => [
            $this->line(),
            $this->line(['dosage' => null, 'frequency' => null, 'duration' => null]),
        ]]);

        $errors = $this->finalize($draft)->assertUnprocessable()->json('errors');

        foreach (['dosage', 'frequency', 'duration'] as $field) {
            $this->assertSame(
                __('encounters::messages.required_to_finalize', ['attribute' => __("encounters::attributes.{$field}")]),
                $errors["prescriptions.1.{$field}"][0],
            );
        }

        $this->assertArrayNotHasKey('prescriptions.0.dosage', $errors);
        $this->assertSame(EncounterStatus::Draft, $draft->fresh()->status);
    }

    public function test_a_finalized_record_cannot_be_saved_over_or_finalized_again(): void
    {
        $draft = $this->draft();
        $this->finalize($draft)->assertOk();

        $before = (array) DB::table('encounters')->where('id', $draft->id)->first();

        $this->finalize($draft)
            ->assertUnprocessable()
            ->assertJsonPath('errors.encounter.0', __('encounters::messages.not_editable'));

        $this->putJson("/api/v1/doctors/me/encounters/{$draft->id}", [
            'chief_complaint' => 'Changed',
            'examination_findings' => null,
            'diagnosis' => 'Changed',
            'treatment_plan' => null,
            'prescriptions' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.encounter.0', __('encounters::messages.not_editable'));

        $this->assertSame($before, (array) DB::table('encounters')->where('id', $draft->id)->first());
    }

    public function test_finalizing_is_separate_from_finishing_the_visit(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create([
            'doctor_id' => $this->doctor->id,
            'status' => AppointmentStatus::CheckedIn,
        ]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->withDoctor()->create();
        $draft = Encounter::factory()->forEntry($entry)->complete()->create();

        $this->finalize($draft)->assertOk();

        $this->assertSame(QueueStatus::WithDoctor, $entry->fresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_another_doctor_is_refused(): void
    {
        $otherDraft = Encounter::factory()->complete()->create();
        $readable = Encounter::factory()->finalized()->create();
        $unreadable = Encounter::factory()->finalized()->create();
        QueueEntry::factory()->create(['doctor_id' => $this->doctor->id, 'patient_id' => $readable->patient_id]);

        $this->finalize($otherDraft)->assertNotFound();
        $this->finalize($unreadable)->assertNotFound();
        $this->finalize($readable)->assertForbidden();

        $this->assertSame(EncounterStatus::Draft, $otherDraft->fresh()->status);
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        $draft = $this->draft();

        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->finalize($draft)->assertForbidden();
        }

        $this->assertSame(EncounterStatus::Draft, $draft->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = [], int $number = 1): Encounter
    {
        $entry = QueueEntry::factory()->number($number)->seen()->create(['doctor_id' => $this->doctor->id]);

        return Encounter::factory()->forEntry($entry)->complete()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return [
            'medication' => 'Synthetic medication',
            'dosage' => 'Synthetic dosage',
            'frequency' => 'Synthetic frequency',
            'duration' => 'Synthetic duration',
            'instructions' => 'Synthetic instructions',
            ...$overrides,
        ];
    }

    private function finalize(Encounter $encounter): TestResponse
    {
        return $this->postJson("/api/v1/doctors/me/encounters/{$encounter->id}/finalize");
    }
}
