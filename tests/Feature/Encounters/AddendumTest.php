<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A finalized record stays locked; its author corrects it only by adding addenda (US5, FR-011).
 */
class AddendumTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Encounter $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $this->doctor->id]);
        $this->record = Encounter::factory()->forEntry($entry)->finalized()->create();
        Sanctum::actingAs($this->doctor->user);
    }

    public function test_the_author_adds_an_addendum_and_the_original_is_unchanged(): void
    {
        $before = (array) DB::table('encounters')->where('id', $this->record->id)->first();

        $this->travel(1)->hours();

        $response = $this->add('Synthetic correction')
            ->assertCreated()
            ->assertJsonPath('data.text', 'Synthetic correction')
            ->assertJsonPath('data.created_at', now()->toISOString());

        $keys = array_keys($response->json('data'));
        sort($keys);
        $this->assertSame(['created_at', 'id', 'text'], $keys);

        $this->assertSame($before, (array) DB::table('encounters')->where('id', $this->record->id)->first());
    }

    public function test_addenda_appear_oldest_first_to_everyone_who_may_read_the_record(): void
    {
        $this->add('First')->assertCreated();
        $this->travel(1)->minutes();
        $this->add('Second')->assertCreated();

        $this->open()
            ->assertOk()
            ->assertJsonPath('data.addenda.0.text', 'First')
            ->assertJsonPath('data.addenda.1.text', 'Second');

        $reader = Doctor::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $reader->id, 'patient_id' => $this->record->patient_id]);
        Sanctum::actingAs($reader->user);

        $this->open()
            ->assertOk()
            ->assertJsonPath('data.addenda.0.text', 'First')
            ->assertJsonPath('data.addenda.1.text', 'Second');
    }

    public function test_a_draft_cannot_have_an_addendum(): void
    {
        $entry = QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $this->doctor->id]);
        $draft = Encounter::factory()->forEntry($entry)->complete()->create();

        $this->postJson("/api/v1/doctors/me/encounters/{$draft->id}/addenda", ['text' => 'Too early'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.encounter.0', __('encounters::messages.not_finalized'));

        $this->assertSame(0, EncounterAddendum::query()->count());
    }

    public function test_the_text_is_required_and_limited(): void
    {
        $this->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors(['text']);
        $this->add('   ')->assertUnprocessable()->assertJsonValidationErrors(['text']);
        $this->add(str_repeat("\u{0645}", 10001))->assertUnprocessable()->assertJsonValidationErrors(['text']);
        $this->assertSame(0, EncounterAddendum::query()->count());

        $this->add(str_repeat("\u{1F600}", 10000))->assertCreated();
    }

    public function test_another_doctor_who_may_read_is_forbidden_and_one_who_may_not_gets_not_found(): void
    {
        $other = Doctor::factory()->create();
        Sanctum::actingAs($other->user);

        $this->add('Not yours')->assertNotFound();

        QueueEntry::factory()->create(['doctor_id' => $other->id, 'patient_id' => $this->record->patient_id]);

        $this->add('Not yours')->assertForbidden();
        $this->assertSame(0, EncounterAddendum::query()->count());
    }

    public function test_reception_and_administrators_are_forbidden(): void
    {
        foreach ([User::factory()->administrator()->create(), User::factory()->receptionist()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->add('Not yours')->assertForbidden();
        }

        $this->assertSame(0, EncounterAddendum::query()->count());
    }

    public function test_nothing_can_change_or_delete_an_addendum(): void
    {
        $addendum = EncounterAddendum::factory()->create(['encounter_id' => $this->record->id, 'text' => 'Kept']);
        $url = "{$this->url()}/{$addendum->id}";

        $this->assertContains($this->putJson($url, ['text' => 'Changed'])->getStatusCode(), [404, 405]);
        $this->assertContains($this->patchJson($url, ['text' => 'Changed'])->getStatusCode(), [404, 405]);
        $this->assertContains($this->deleteJson($url)->getStatusCode(), [404, 405]);
        $this->assertContains($this->deleteJson($this->url())->getStatusCode(), [404, 405]);

        $this->assertSame('Kept', $addendum->fresh()->text);
    }

    private function url(): string
    {
        return "/api/v1/doctors/me/encounters/{$this->record->id}/addenda";
    }

    private function add(string $text): TestResponse
    {
        return $this->postJson($this->url(), ['text' => $text]);
    }

    private function open(): TestResponse
    {
        return $this->getJson("/api/v1/doctors/me/encounters/{$this->record->id}");
    }
}
