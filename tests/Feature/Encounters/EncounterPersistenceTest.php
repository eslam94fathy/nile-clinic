<?php

namespace Tests\Feature\Encounters;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database backs the one-record-per-visit rule, keeps records from losing their references, and
 * holds only ciphertext (FR-002, FR-004, research R6).
 */
class EncounterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'SYNTHETIC-PLANTED-TEXT';

    private const TEXT_COLUMNS = ['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan'];

    public function test_a_visit_cannot_have_two_records(): void
    {
        $entry = QueueEntry::factory()->seen()->create();
        Encounter::factory()->forEntry($entry)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Encounter::factory()->forEntry($entry)->create();
    }

    public function test_a_visit_with_a_record_cannot_be_deleted(): void
    {
        $encounter = Encounter::factory()->create();

        $this->expectException(QueryException::class);

        $encounter->queueEntry->delete();
    }

    public function test_a_doctor_with_a_record_cannot_be_deleted(): void
    {
        $encounter = Encounter::factory()->create();

        $this->expectException(QueryException::class);

        $encounter->doctor->delete();
    }

    public function test_a_patient_with_a_record_cannot_be_deleted(): void
    {
        $encounter = Encounter::factory()->create();

        $this->expectException(QueryException::class);

        $encounter->patient->delete();
    }

    public function test_a_record_with_an_addendum_cannot_be_deleted(): void
    {
        $addendum = EncounterAddendum::factory()->create();

        $this->expectException(QueryException::class);

        $addendum->encounter->delete();
    }

    public function test_clinical_content_is_stored_encrypted_and_read_back_decrypted(): void
    {
        $lines = [['medication' => self::PLANTED]];
        $encounter = Encounter::factory()->create([
            ...array_fill_keys(self::TEXT_COLUMNS, self::PLANTED),
            'prescriptions' => $lines,
        ]);
        $addendum = EncounterAddendum::factory()->create(['encounter_id' => $encounter->id, 'text' => self::PLANTED]);

        $row = (array) DB::table('encounters')->where('id', $encounter->id)->first();

        foreach ([...self::TEXT_COLUMNS, 'prescriptions'] as $column) {
            $this->assertNotNull($row[$column]);
            $this->assertStringNotContainsString(self::PLANTED, $row[$column]);
        }

        $this->assertStringNotContainsString(self::PLANTED, DB::table('encounter_addenda')->where('id', $addendum->id)->value('text'));

        $fresh = $encounter->fresh();

        foreach (self::TEXT_COLUMNS as $column) {
            $this->assertSame(self::PLANTED, $fresh->{$column});
        }

        $this->assertSame($lines, $fresh->prescriptions);
        $this->assertSame(self::PLANTED, $addendum->fresh()->text);
    }

    /**
     * Encrypted four-byte text is larger than a TEXT column can hold (research R6).
     */
    public function test_the_largest_allowed_content_round_trips(): void
    {
        $text = str_repeat("\u{1F600}", 10000);
        $field = str_repeat("\u{1F600}", 500);
        $lines = array_fill(0, 20, array_fill_keys(['medication', 'dosage', 'frequency', 'duration', 'instructions'], $field));

        $encounter = Encounter::factory()->create([
            ...array_fill_keys(self::TEXT_COLUMNS, $text),
            'prescriptions' => $lines,
        ]);
        $addendum = EncounterAddendum::factory()->create(['encounter_id' => $encounter->id, 'text' => $text]);

        $fresh = $encounter->fresh();

        foreach (self::TEXT_COLUMNS as $column) {
            $this->assertSame($text, $fresh->{$column});
        }

        $this->assertSame($lines, $fresh->prescriptions);
        $this->assertSame($text, $addendum->fresh()->text);
    }

    public function test_serialization_leaves_out_clinical_attributes(): void
    {
        $encounter = Encounter::factory()->finalized()->create();
        $addendum = EncounterAddendum::factory()->create(['encounter_id' => $encounter->id]);

        foreach ([...self::TEXT_COLUMNS, 'prescriptions'] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $encounter->toArray());
        }

        $this->assertArrayNotHasKey('text', $addendum->toArray());
    }
}
