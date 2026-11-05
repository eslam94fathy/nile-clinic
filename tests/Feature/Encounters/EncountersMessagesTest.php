<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\EncounterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message in English and Arabic with an identical structure; identifiers and clinical text are
 * never translated (FR-024, SC-006).
 */
class EncountersMessagesTest extends TestCase
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

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    #[DataProvider('locales')]
    public function test_not_found_messages(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $this->getJson('/api/v1/doctors/me/encounters/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('encounters::messages.encounter_not_found', [], $locale)]);

        $this->getJson('/api/v1/doctors/me/patients/999999/encounters')
            ->assertNotFound()
            ->assertExactJson(['message' => __('encounters::messages.history_not_found', [], $locale)]);

        $this->postJson('/api/v1/doctors/me/visits/999999/encounter')
            ->assertNotFound()
            ->assertExactJson(['message' => __('encounters::messages.visit_not_found', [], $locale)]);
    }

    #[DataProvider('locales')]
    public function test_start_refusals(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $waiting = QueueEntry::factory()->number(1)->create(['doctor_id' => $this->doctor->id]);
        $started = Encounter::factory()->forEntry(QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]))->create();

        $this->postJson("/api/v1/doctors/me/visits/{$waiting->id}/encounter")
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('encounters::messages.visit_not_started', [], $locale));

        $this->postJson("/api/v1/doctors/me/visits/{$started->queue_entry_id}/encounter")
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('encounters::messages.already_started', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_record_state_refusals(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $finalized = $this->record(1)->finalized()->create();
        $draft = $this->record(2)->create();

        $this->postJson("/api/v1/doctors/me/encounters/{$finalized->id}/finalize")
            ->assertUnprocessable()
            ->assertJsonPath('errors.encounter.0', __('encounters::messages.not_editable', [], $locale));

        $this->postJson("/api/v1/doctors/me/encounters/{$draft->id}/addenda", ['text' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.encounter.0', __('encounters::messages.not_finalized', [], $locale));

        $response = $this->postJson("/api/v1/doctors/me/encounters/{$draft->id}/finalize")->assertUnprocessable();

        $response->assertJsonPath('errors.diagnosis.0', __(
            'encounters::messages.required_to_finalize',
            ['attribute' => __('encounters::attributes.diagnosis', [], $locale)],
            $locale,
        ));
    }

    #[DataProvider('locales')]
    public function test_finalize_names_a_prescription_field_in_the_language(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $draft = $this->record(1)->complete()->create(['prescriptions' => [['medication' => 'Synthetic medication']]]);

        $errors = $this->postJson("/api/v1/doctors/me/encounters/{$draft->id}/finalize")->assertUnprocessable()->json('errors');

        $this->assertStringContainsString(__('encounters::attributes.dosage', [], $locale), $errors['prescriptions.0.dosage'][0]);
    }

    #[DataProvider('locales')]
    public function test_validation_messages_use_translated_attributes(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $draft = $this->record(1)->create();
        $finalized = $this->record(2)->finalized()->create();

        $required = $this->postJson("/api/v1/doctors/me/encounters/{$finalized->id}/addenda", [])->assertUnprocessable()->json('errors.text.0');
        $this->assertStringContainsString(__('encounters::attributes.text', [], $locale), $required);

        $errors = $this->putJson("/api/v1/doctors/me/encounters/{$draft->id}", [
            'chief_complaint' => str_repeat('a', 10001),
            'examination_findings' => null,
            'diagnosis' => null,
            'treatment_plan' => null,
            'prescriptions' => [['medication' => 'Synthetic medication', 'dosage' => str_repeat('a', 501)]],
        ])->assertUnprocessable()->json('errors');

        $this->assertStringContainsString(__('encounters::attributes.chief_complaint', [], $locale), $errors['chief_complaint'][0]);
        $this->assertStringContainsString(__('encounters::attributes.dosage', [], $locale), $errors['prescriptions.0.dosage'][0]);

        $status = $this->getJson('/api/v1/doctors/me/visits?from=2026-11-01&to=2026-11-01&record_status=done')
            ->assertUnprocessable()
            ->json('errors.record_status.0');
        $this->assertStringContainsString(__('encounters::attributes.record_status', [], $locale), $status);
    }

    #[DataProvider('locales')]
    public function test_forbidden_message(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->withHeader('Accept-Language', $locale)
            ->getJson('/api/v1/doctors/me/encounters/999999')
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden', [], $locale)]);
    }

    public function test_arabic_translations_differ_from_english(): void
    {
        foreach (array_keys(require app_path('Modules/Encounters/lang/en/messages.php')) as $key) {
            $this->assertNotSame(__("encounters::messages.{$key}", [], 'en'), __("encounters::messages.{$key}", [], 'ar'), $key);
        }
    }

    public function test_structure_and_identifiers_are_identical_across_locales(): void
    {
        $record = $this->record(1)->finalized()->create();
        $entry = $record->queueEntry;
        $urls = [
            "/api/v1/doctors/me/encounters/{$record->id}",
            '/api/v1/doctors/me/visits?from=2026-11-01&to=2026-11-01',
            "/api/v1/doctors/me/patients/{$entry->patient_id}/encounters",
        ];

        foreach ($urls as $url) {
            $english = $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk()->json();
            $arabic = $this->withHeader('Accept-Language', 'ar')->getJson($url)->assertOk()->json();

            unset($english['links'], $english['meta'], $arabic['links'], $arabic['meta']);

            $this->assertSame($english, $arabic);
        }
    }

    public function test_clinical_text_is_returned_as_written_in_any_locale(): void
    {
        $record = $this->record(1)->complete()->create(['chief_complaint' => 'صداع شديد', 'diagnosis' => 'Synthetic diagnosis']);

        foreach (['en', 'ar'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->getJson("/api/v1/doctors/me/encounters/{$record->id}")
                ->assertOk()
                ->assertJsonPath('data.chief_complaint', 'صداع شديد')
                ->assertJsonPath('data.diagnosis', 'Synthetic diagnosis')
                ->assertJsonPath('data.status', 'draft');
        }
    }

    private function record(int $number): EncounterFactory
    {
        return Encounter::factory()->forEntry(QueueEntry::factory()->number($number)->seen()->create(['doctor_id' => $this->doctor->id]));
    }
}
