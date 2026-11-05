<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The author saves a draft any number of times; a save replaces all content (US1, FR-003, FR-004,
 * FR-006, FR-009, research R8).
 */
class SaveEncounterTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Encounter $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->draft = Encounter::factory()->forEntry($entry)->create();
        Sanctum::actingAs($this->doctor->user);
    }

    public function test_saving_stores_the_content_as_written_and_stays_a_draft(): void
    {
        $this->travel(5)->minutes();

        $content = $this->content([
            'chief_complaint' => 'صداع شديد منذ يومين',
            'prescriptions' => [$this->line(['medication' => 'دواء تجريبي', 'instructions' => 'بعد الأكل'])],
        ]);

        $this->save($content)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.chief_complaint', 'صداع شديد منذ يومين')
            ->assertJsonPath('data.examination_findings', 'Synthetic findings')
            ->assertJsonPath('data.diagnosis', 'Synthetic diagnosis')
            ->assertJsonPath('data.treatment_plan', 'Synthetic plan')
            ->assertJsonPath('data.prescriptions', $content['prescriptions'])
            ->assertJsonPath('data.last_saved_at', now()->toISOString());

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.chief_complaint', 'صداع شديد منذ يومين')
            ->assertJsonPath('data.prescriptions', $content['prescriptions']);
    }

    public function test_a_save_replaces_everything(): void
    {
        $this->save($this->content(['prescriptions' => [$this->line(), $this->line()]]))->assertOk();

        $this->save($this->content(['diagnosis' => null, 'prescriptions' => [$this->line(['medication' => 'Only one'])]]))
            ->assertOk()
            ->assertJsonPath('data.diagnosis', null)
            ->assertJsonCount(1, 'data.prescriptions')
            ->assertJsonPath('data.prescriptions.0.medication', 'Only one');
    }

    public function test_a_partly_filled_draft_is_accepted(): void
    {
        $this->save([
            'chief_complaint' => 'Synthetic complaint',
            'examination_findings' => null,
            'diagnosis' => null,
            'treatment_plan' => null,
            'prescriptions' => [['medication' => 'Synthetic medication']],
        ])
            ->assertOk()
            ->assertJsonPath('data.prescriptions.0', [
                'medication' => 'Synthetic medication',
                'dosage' => null,
                'frequency' => null,
                'duration' => null,
                'instructions' => null,
            ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contentKeys(): array
    {
        return [
            'chief complaint' => ['chief_complaint'],
            'examination findings' => ['examination_findings'],
            'diagnosis' => ['diagnosis'],
            'treatment plan' => ['treatment_plan'],
            'prescriptions' => ['prescriptions'],
        ];
    }

    #[DataProvider('contentKeys')]
    public function test_a_missing_key_is_refused_and_erases_nothing(string $key): void
    {
        $this->save($this->content())->assertOk();
        $content = $this->content(['chief_complaint' => 'Changed']);
        unset($content[$key]);

        $this->save($content)->assertUnprocessable()->assertJsonValidationErrors([$key]);

        $fresh = $this->draft->fresh();
        $this->assertSame('Synthetic complaint', $fresh->chief_complaint);
        $this->assertSame('Synthetic diagnosis', $fresh->diagnosis);
        $this->assertCount(1, $fresh->prescriptions);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function characters(): array
    {
        return ['arabic' => ["\u{0645}"], 'four bytes' => ["\u{1F600}"]];
    }

    #[DataProvider('characters')]
    public function test_clinical_text_may_be_10000_characters(string $character): void
    {
        $text = str_repeat($character, 10000);

        $this->save($this->content(['chief_complaint' => $text]))->assertOk()->assertJsonPath('data.chief_complaint', $text);
        $this->save($this->content(['chief_complaint' => $text.$character]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chief_complaint']);
    }

    public function test_prescription_line_fields_may_be_500_characters(): void
    {
        foreach (['medication', 'dosage', 'frequency', 'duration', 'instructions'] as $field) {
            $this->save($this->content(['prescriptions' => [$this->line([$field => str_repeat('a', 500)])]]))->assertOk();
            $this->save($this->content(['prescriptions' => [$this->line([$field => str_repeat('a', 501)])]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["prescriptions.0.{$field}"]);
        }
    }

    public function test_a_line_needs_a_medication(): void
    {
        $this->save($this->content(['prescriptions' => [$this->line(['medication' => null])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prescriptions.0.medication']);
    }

    public function test_a_record_may_have_20_lines(): void
    {
        $this->save($this->content(['prescriptions' => array_fill(0, 20, $this->line())]))
            ->assertOk()
            ->assertJsonCount(20, 'data.prescriptions');

        $this->save($this->content(['prescriptions' => array_fill(0, 21, $this->line())]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prescriptions']);
    }

    public function test_unknown_line_keys_are_not_stored(): void
    {
        $this->save($this->content(['prescriptions' => [$this->line(['surprise' => 'value'])]]))->assertOk();

        $this->assertArrayNotHasKey('surprise', $this->draft->fresh()->prescriptions[0]);
    }

    public function test_another_doctors_draft_looks_exactly_like_an_unknown_record(): void
    {
        Sanctum::actingAs(Doctor::factory()->create()->user);

        $unknown = $this->getJson('/api/v1/doctors/me/encounters/999999')->assertNotFound()->json();

        $this->assertSame($unknown, $this->getJson($this->url())->assertNotFound()->json());
        $this->assertSame($unknown, $this->save($this->content())->assertNotFound()->json());
        $this->assertSame(['message' => __('encounters::messages.encounter_not_found')], $unknown);

        $this->assertNull($this->draft->fresh()->chief_complaint);
        $this->assertSame(EncounterStatus::Draft, $this->draft->fresh()->status);
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson($this->url())->assertForbidden();
            $this->save($this->content())->assertForbidden();
            $this->putJson('/api/v1/doctors/me/encounters/999999', [])->assertForbidden();
        }

        $this->assertNull($this->draft->fresh()->chief_complaint);
    }

    public function test_validation_comes_before_the_lookup_and_reveals_nothing(): void
    {
        $this->putJson('/api/v1/doctors/me/encounters/999999', [])->assertUnprocessable();
    }

    private function url(): string
    {
        return "/api/v1/doctors/me/encounters/{$this->draft->id}";
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function save(array $content): TestResponse
    {
        return $this->putJson($this->url(), $content);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function content(array $overrides = []): array
    {
        return [
            'chief_complaint' => 'Synthetic complaint',
            'examination_findings' => 'Synthetic findings',
            'diagnosis' => 'Synthetic diagnosis',
            'treatment_plan' => 'Synthetic plan',
            'prescriptions' => [$this->line()],
            ...$overrides,
        ];
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
}
