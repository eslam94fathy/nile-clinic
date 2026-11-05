<?php

namespace Tests\Feature\Audit;

use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The search's filters (FR-009; data-model.md, "Validation").
 */
class AuditValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'malformed from' => [['from' => '05-11-2026'], 'from'],
            'impossible from' => [['from' => '2026-02-30'], 'from'],
            'malformed to' => [['to' => 'tomorrow'], 'to'],
            'to before from' => [['from' => '2026-11-05', 'to' => '2026-11-04'], 'to'],
            'unknown subject type' => [['subject_type' => 'invoice', 'subject_id' => 1], 'subject_type'],
            'subject id alone' => [['subject_id' => 1], 'subject_type'],
            'subject type alone' => [['subject_type' => 'charge'], 'subject_id'],
            'subject id zero' => [['subject_type' => 'charge', 'subject_id' => 0], 'subject_id'],
            'unknown action' => [['action' => 'patient.deleted'], 'action'],
            'unknown outcome' => [['outcome' => 'maybe'], 'outcome'],
            'staff id zero' => [['staff_id' => 0], 'staff_id'],
            'staff id text' => [['staff_id' => 'abc'], 'staff_id'],
            'patient id zero' => [['patient_id' => 0], 'patient_id'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page above the limit' => [['per_page' => 101], 'per_page'],
            'cursor text' => [['cursor' => 'not-a-cursor'], 'cursor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalid')]
    public function test_an_invalid_filter_is_refused(array $query, string $field): void
    {
        $this->getJson('/api/v1/audit-entries?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_a_cursor_without_a_position_is_refused_rather_than_failing(): void
    {
        $cursor = (new Cursor(['other' => 1]))->encode();

        $this->getJson('/api/v1/audit-entries?cursor='.$cursor)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    public function test_a_cursor_with_a_position_that_is_not_a_number_is_refused(): void
    {
        $cursor = (new Cursor(['id' => 'abc']))->encode();

        $this->getJson('/api/v1/audit-entries?cursor='.$cursor)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    public function test_a_cursor_of_other_valid_json_is_refused_rather_than_failing(): void
    {
        foreach (['{}', '1', '[]', '{"id":1}'] as $json) {
            $this->getJson('/api/v1/audit-entries?cursor='.rtrim(base64_encode($json), '='))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cursor');
        }
    }

    public function test_no_filters_are_needed(): void
    {
        $this->getJson('/api/v1/audit-entries')->assertOk();
    }

    public function test_a_period_longer_than_a_year_is_accepted(): void
    {
        $this->getJson('/api/v1/audit-entries?from=2020-01-01&to=2026-11-05')->assertOk();
    }

    public function test_empty_filters_are_ignored(): void
    {
        $this->getJson('/api/v1/audit-entries?patient_id=&action=&from=')->assertOk();
    }
}
