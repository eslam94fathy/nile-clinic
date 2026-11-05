<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * Searching the trail (FR-009, FR-010, FR-012).
 */
class AuditSearchTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($this->administrator);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function search(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/audit-entries?'.http_build_query($query))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function idsFor(array $query): array
    {
        $ids = array_column($this->search($query)->json('data'), 'id');

        // A search's own entry is never asked for here.
        return array_values(array_diff($ids, AuditEntry::query()->where('action', AuditAction::AuditSearched)->pluck('id')->all()));
    }

    public function test_each_filter_narrows_the_results(): void
    {
        $patient = Patient::factory()->create();
        $target = AuditEntry::factory()->create([
            'action' => AuditAction::DiscountSet,
            'subject_type' => AuditSubject::Charge,
            'subject_id' => 57,
            'patient_id' => $patient->id,
            'outcome' => AuditOutcome::Refused,
        ]);
        $other = AuditEntry::factory()->create();

        foreach ([
            ['action' => 'discount.set'],
            ['outcome' => 'refused'],
            ['patient_id' => $patient->id],
            ['subject_type' => 'charge', 'subject_id' => 57],
            ['staff_id' => $target->actor_id],
        ] as $query) {
            $this->assertSame([$target->id], $this->idsFor($query), json_encode($query));
        }

        $this->assertSame([$other->id, $target->id], $this->idsFor(['per_page' => 100]));
    }

    public function test_filters_combine(): void
    {
        $patient = Patient::factory()->create();
        $target = AuditEntry::factory()->create(['patient_id' => $patient->id, 'action' => AuditAction::PatientOpened]);
        AuditEntry::factory()->create(['patient_id' => $patient->id, 'action' => AuditAction::PatientUpdated]);
        AuditEntry::factory()->create(['action' => AuditAction::PatientOpened]);

        $this->assertSame([$target->id], $this->idsFor(['patient_id' => $patient->id, 'action' => 'patient.opened']));
    }

    public function test_a_record_type_needs_its_own_identifier_to_match(): void
    {
        $target = AuditEntry::factory()->create(['subject_type' => AuditSubject::Charge, 'subject_id' => 7]);
        AuditEntry::factory()->create(['subject_type' => AuditSubject::Visit, 'subject_id' => 7]);
        AuditEntry::factory()->create(['subject_type' => AuditSubject::Charge, 'subject_id' => 8]);

        $this->assertSame([$target->id], $this->idsFor(['subject_type' => 'charge', 'subject_id' => 7]));
    }

    public function test_staff_id_matches_what_they_did_and_what_was_done_to_their_account(): void
    {
        $member = User::factory()->receptionist()->create();
        $did = AuditEntry::factory()->create(['actor_id' => $member->id]);
        $doneTo = AuditEntry::factory()->create([
            'actor_id' => $this->administrator->id,
            'subject_type' => AuditSubject::StaffMember,
            'subject_id' => $member->id,
        ]);
        AuditEntry::factory()->create();
        AuditEntry::factory()->create(['subject_type' => AuditSubject::Patient, 'subject_id' => $member->id]);

        $this->assertSame([$doneTo->id, $did->id], $this->idsFor(['staff_id' => $member->id]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clinicDays(): array
    {
        return [
            'an ordinary day' => ['2026-11-01'],
            'the day the clock goes forward' => ['2026-04-24'],
            'the day after the clock goes back' => ['2026-10-30'],
        ];
    }

    #[DataProvider('clinicDays')]
    public function test_dates_are_clinic_days(string $day): void
    {
        $start = CarbonImmutable::parse($day, 'Africa/Cairo')->startOfDay();
        $last = $start->addDay()->startOfDay();

        $before = AuditEntry::factory()->create(['created_at' => $start->subMinute()->utc()]);
        $first = AuditEntry::factory()->create(['created_at' => $start->addMinute()->utc()]);
        $end = AuditEntry::factory()->create(['created_at' => $last->subMinute()->utc()]);
        $after = AuditEntry::factory()->create(['created_at' => $last->addMinute()->utc()]);

        $this->assertSame([$end->id, $first->id], $this->idsFor(['from' => $day, 'to' => $day]));
        $this->assertSame([$after->id, $end->id, $first->id], $this->idsFor(['from' => $day, 'per_page' => 100]));
        $this->assertSame([$first->id, $before->id], array_values(array_slice($this->idsFor(['to' => $day, 'per_page' => 3]), 1)));
    }

    public function test_a_period_may_be_of_any_length(): void
    {
        $entry = AuditEntry::factory()->create(['created_at' => now()->subYears(3)]);

        $this->assertSame([$entry->id], $this->idsFor(['from' => now()->subYears(4)->toDateString(), 'to' => now()->subYears(2)->toDateString()]));
    }

    public function test_results_are_newest_first_even_within_the_same_second(): void
    {
        $at = now();
        $first = AuditEntry::factory()->create(['created_at' => $at]);
        $second = AuditEntry::factory()->create(['created_at' => $at]);
        $third = AuditEntry::factory()->create(['created_at' => $at]);

        $this->assertSame([$third->id, $second->id, $first->id], $this->idsFor(['action' => 'patient.updated']));
    }

    public function test_cursor_paging_stays_stable_while_entries_are_added(): void
    {
        $created = AuditEntry::factory()->count(7)->create(['action' => AuditAction::PatientOpened])->modelKeys();

        $seen = [];
        $query = ['action' => 'patient.opened', 'per_page' => 3];

        do {
            $response = $this->search($query);
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $query['cursor'] = $response->json('meta.next_cursor');

            $this->assertArrayNotHasKey('total', $response->json('meta'));

            // New entries appear between pages, as searches and staff actions do.
            AuditEntry::factory()->create(['action' => AuditAction::PatientOpened]);
        } while ($query['cursor'] !== null);

        $this->assertSame(array_reverse($created), $seen);
    }

    public function test_an_entry_has_exactly_the_contract_fields(): void
    {
        $patient = Patient::factory()->create();
        $actor = User::factory()->receptionist()->create(['name' => 'Synthetic Old Name']);
        AuditEntry::factory()->create([
            'action' => AuditAction::DiscountSet,
            'actor_id' => $actor->id,
            'actor_role' => 'receptionist',
            'subject_type' => AuditSubject::Charge,
            'subject_id' => 57,
            'patient_id' => $patient->id,
            'details' => ['fields' => ['discount'], 'values' => ['discount' => ['from' => '0.00', 'to' => '10.00']]],
            'created_at' => CarbonImmutable::parse('2026-11-05 14:22:31', 'UTC'),
        ]);

        // A later rename and a later role change: the name is current, and the role is the one held then.
        $actor->update(['name' => 'Synthetic New Name', 'role' => 'doctor']);

        // MySQL's JSON columns reorder object keys, which means nothing to a client, so compare by content.
        $this->assertEquals([
            'id' => AuditEntry::query()->where('action', AuditAction::DiscountSet)->sole()->id,
            'occurred_at' => '2026-11-05T14:22:31.000000Z',
            'action' => 'discount.set',
            'outcome' => 'succeeded',
            'actor' => ['type' => 'staff', 'id' => $actor->id, 'name' => 'Synthetic New Name', 'role' => 'receptionist'],
            'subject' => ['type' => 'charge', 'id' => 57],
            'patient_id' => $patient->id,
            'network_address' => '203.0.113.10',
            'changes' => ['fields' => ['discount'], 'values' => ['discount' => ['from' => '0.00', 'to' => '10.00']]],
            'filters' => null,
        ], $this->search(['action' => 'discount.set'])->json('data.0'));
    }

    public function test_empty_values_are_empty_objects_in_every_change(): void
    {
        AuditEntry::factory()->create(['details' => ['fields' => ['phone'], 'values' => []]]);

        $content = $this->search(['action' => 'patient.updated'])->getContent();

        $this->assertStringContainsString('"values":{}', $content);
    }

    /**
     * @return array<string, array{AuditActorType, string}>
     */
    public static function actorsWithoutStaff(): array
    {
        return [
            'system' => [AuditActorType::System, 'system'],
            'unknown' => [AuditActorType::Unknown, 'unknown'],
        ];
    }

    #[DataProvider('actorsWithoutStaff')]
    public function test_an_entry_without_a_staff_member_has_null_actor_fields(AuditActorType $type, string $value): void
    {
        AuditEntry::factory()->create([
            'action' => AuditAction::AuthSignIn,
            'actor_type' => $type,
            'actor_id' => null,
            'actor_role' => null,
            'network_address' => null,
        ]);

        $this->search(['action' => 'auth.sign_in'])
            ->assertJsonPath('data.0.actor', ['type' => $value, 'id' => null, 'name' => null, 'role' => null])
            ->assertJsonPath('data.0.subject', null)
            ->assertJsonPath('data.0.changes', null);
    }

    public function test_unknown_identifiers_give_an_empty_page_not_an_error(): void
    {
        AuditEntry::factory()->create();

        $this->search(['staff_id' => 999999])->assertJsonPath('data', []);
        $this->search(['patient_id' => 999999])->assertJsonPath('data', []);
        $this->search(['subject_type' => 'visit', 'subject_id' => 999999])->assertJsonPath('data', []);
    }

    public function test_a_deactivated_staff_members_entries_remain_searchable(): void
    {
        $member = User::factory()->inactive()->receptionist()->create();
        $entry = AuditEntry::factory()->create(['actor_id' => $member->id]);

        $this->assertSame([$entry->id], $this->idsFor(['staff_id' => $member->id]));
    }

    /* A search is itself recorded (FR-011, US4 scenario 4) */

    public function test_a_search_is_recorded_with_its_filters(): void
    {
        $patient = Patient::factory()->create();

        $this->search([
            'patient_id' => $patient->id,
            'action' => 'patient.opened',
            'from' => '2026-11-01',
            'per_page' => 5,
        ]);

        $entry = AuditEntry::query()->where('action', AuditAction::AuditSearched)->sole();

        $this->assertSame($this->administrator->id, $entry->actor_id);
        $this->assertSame('administrator', $entry->actor_role);
        $this->assertNull($entry->subject_type);
        $this->assertEquals(['filters' => [
            'patient_id' => $patient->id,
            'action' => 'patient.opened',
            'from' => '2026-11-01',
        ]], $entry->details);
    }

    public function test_a_search_entry_is_shown_with_its_filters(): void
    {
        $this->search(['outcome' => 'refused']);

        $this->search(['action' => 'audit.searched'])
            ->assertJsonPath('data.0.filters', ['outcome' => 'refused'])
            ->assertJsonPath('data.0.changes', null)
            ->assertJsonPath('data.0.subject', null);
    }

    public function test_a_search_does_not_list_itself(): void
    {
        $this->search(['action' => 'audit.searched'])->assertJsonPath('data', []);
    }

    public function test_every_page_is_recorded(): void
    {
        AuditEntry::factory()->count(3)->create();

        $cursor = $this->search(['action' => 'patient.updated', 'per_page' => 1])->json('meta.next_cursor');
        $this->search(['action' => 'patient.updated', 'per_page' => 1, 'cursor' => $cursor]);

        $this->assertSame(2, AuditEntry::query()->where('action', AuditAction::AuditSearched)->count());
    }

    public function test_real_searches_do_not_disturb_paging(): void
    {
        $created = AuditEntry::factory()->count(7)->create()->modelKeys();

        $seen = [];
        $query = ['per_page' => 3];

        // No action filter: each page's own search entry lands in the list that is being walked.
        do {
            $response = $this->search($query);
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $query['cursor'] = $response->json('meta.next_cursor');
        } while ($query['cursor'] !== null);

        $this->assertSame(array_reverse($created), array_values(array_intersect($seen, $created)));
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_a_refused_or_invalid_search_is_not_recorded(): void
    {
        $this->getJson('/api/v1/audit-entries?from=bad')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->getJson('/api/v1/audit-entries')->assertForbidden();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_no_results_are_returned_when_the_entry_fails(): void
    {
        AuditEntry::factory()->create(['network_address' => '203.0.113.99']);
        $this->failAuditWrites();

        $response = $this->getJson('/api/v1/audit-entries')->assertStatus(500);

        $this->assertStringNotContainsString('203.0.113.99', $response->getContent());
    }
}
