<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;
use App\Modules\Encounters\Repositories\EloquentEncounterRepository;
use App\Modules\Queues\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Clinical content never reaches listings, errors, logs, or raw database values: recognizable text is
 * planted, then searched for (FR-018, FR-021, SC-002, research R6).
 */
class EncounterPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'SYNTHETIC-PLANTED-TEXT';

    private const CLINICAL_COLUMNS = ['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions'];

    private Doctor $doctor;

    private Encounter $record;

    private Encounter $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();

        $this->record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->number(1)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->finalized()
            ->create($this->plantedContent());
        EncounterAddendum::factory()->create(['encounter_id' => $this->record->id, 'text' => self::PLANTED]);

        $this->draft = Encounter::factory()
            ->forEntry(QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]))
            ->create();

        Sanctum::actingAs($this->doctor->user);
    }

    /**
     * With arguments in traces, a failing call prints the first 15 characters of a string argument.
     */
    public function test_stack_traces_carry_no_arguments(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        $fail = function (string $text): void {
            throw new RuntimeException('failed');
        };

        try {
            $fail(self::PLANTED);
            $this->fail('The closure should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('SYNTHETIC', $e->getTraceAsString());
        }
    }

    public function test_opening_one_record_does_return_the_content(): void
    {
        $this->assertStringContainsString(self::PLANTED, $this->getJson($this->recordUrl())->assertOk()->getContent());
    }

    public function test_listings_contain_no_clinical_content(): void
    {
        foreach ($this->listingUrls() as $url) {
            $this->assertStringNotContainsString(self::PLANTED, $this->getJson($url)->assertOk()->getContent());
        }
    }

    public function test_listing_queries_never_select_a_clinical_column(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        foreach ($this->listingUrls() as $url) {
            $this->getJson($url)->assertOk();
        }

        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('select * from `encounters`', $sql);
            $this->assertStringNotContainsString('`encounters`.*', $sql);
            $this->assertStringNotContainsString('encounter_addenda', $sql);

            foreach (self::CLINICAL_COLUMNS as $column) {
                $this->assertStringNotContainsString($column, $sql);
            }
        }
    }

    public function test_validation_errors_do_not_repeat_submitted_text(): void
    {
        $tooLong = self::PLANTED.str_repeat('x', 10000);
        $draftUrl = "/api/v1/doctors/me/encounters/{$this->draft->id}";

        $responses = [
            $this->putJson($draftUrl, [
                'chief_complaint' => $tooLong,
                'examination_findings' => $tooLong,
                'diagnosis' => $tooLong,
                'treatment_plan' => $tooLong,
                'prescriptions' => [['medication' => $tooLong, 'dosage' => $tooLong]],
            ])->assertUnprocessable(),
            $this->putJson($draftUrl, ['chief_complaint' => self::PLANTED])->assertUnprocessable(),
            $this->postJson("{$this->recordUrl()}/addenda", ['text' => $tooLong])->assertUnprocessable(),
            $this->postJson("/api/v1/doctors/me/encounters/{$this->draft->id}/addenda", ['text' => self::PLANTED])->assertUnprocessable(),
            $this->putJson($this->recordUrl(), [...$this->plantedContent(), 'prescriptions' => []])->assertUnprocessable(),
        ];

        foreach ($responses as $response) {
            $this->assertStringNotContainsString(self::PLANTED, $response->getContent());
        }
    }

    /**
     * A failure in the middle of a save, with clinical text in the request, written to a real log file.
     */
    public function test_a_failed_save_leaks_nothing_to_the_response_or_the_log(): void
    {
        $this->app->bind(EncounterRepository::class, fn () => new class extends EloquentEncounterRepository
        {
            public function update(Encounter $encounter, array $attributes): Encounter
            {
                throw new RuntimeException('Forced failure');
            }
        });

        $logFile = storage_path('logs/encounter-privacy-test.log');
        File::delete($logFile);
        config(['logging.channels.encounter_privacy_test' => ['driver' => 'single', 'path' => $logFile]]);
        Log::setDefaultDriver('encounter_privacy_test');

        try {
            $response = $this->putJson("/api/v1/doctors/me/encounters/{$this->draft->id}", [
                ...$this->plantedContent(),
                'prescriptions' => [['medication' => self::PLANTED]],
            ])->assertServerError();

            $this->assertStringNotContainsString(self::PLANTED, $response->getContent());

            $log = File::get($logFile);
            $this->assertStringContainsString('Forced failure', $log);
            $this->assertStringNotContainsString('SYNTHETIC', $log);
        } finally {
            File::delete($logFile);
        }
    }

    public function test_the_database_holds_only_ciphertext_after_a_save_through_the_api(): void
    {
        $this->putJson("/api/v1/doctors/me/encounters/{$this->draft->id}", [
            ...$this->plantedContent(),
            'prescriptions' => [['medication' => self::PLANTED]],
        ])->assertOk();

        $row = (array) DB::table('encounters')->where('id', $this->draft->id)->first();

        foreach (self::CLINICAL_COLUMNS as $column) {
            $this->assertNotNull($row[$column]);
            $this->assertStringNotContainsString(self::PLANTED, $row[$column]);
        }

        $this->assertStringNotContainsString(self::PLANTED, DB::table('encounter_addenda')->value('text'));
    }

    /**
     * @return list<string>
     */
    private function listingUrls(): array
    {
        return [
            '/api/v1/doctors/me/visits?from=2026-11-01&to=2026-11-01',
            '/api/v1/doctors/me/visits?from=2026-11-01&to=2026-11-01&record_status=finalized',
            "/api/v1/doctors/me/patients/{$this->record->patient_id}/encounters",
        ];
    }

    private function recordUrl(): string
    {
        return "/api/v1/doctors/me/encounters/{$this->record->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function plantedContent(): array
    {
        return [
            'chief_complaint' => self::PLANTED,
            'examination_findings' => self::PLANTED,
            'diagnosis' => self::PLANTED,
            'treatment_plan' => self::PLANTED,
            'prescriptions' => [[
                'medication' => self::PLANTED,
                'dosage' => self::PLANTED,
                'frequency' => self::PLANTED,
                'duration' => self::PLANTED,
                'instructions' => self::PLANTED,
            ]],
        ];
    }
}
