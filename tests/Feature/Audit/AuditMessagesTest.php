<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message exists in both languages, with identical structure; actions and outcomes are never
 * translated (SC-008, FR-019).
 */
class AuditMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    #[DataProvider('locales')]
    public function test_an_invalid_cursor_is_translated(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale)
            ->getJson('/api/v1/audit-entries?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonPath('errors.cursor.0', __('audit::messages.invalid_cursor', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_validation_messages_use_the_translated_field_names(string $locale): void
    {
        $response = $this->withHeader('Accept-Language', $locale)
            ->getJson('/api/v1/audit-entries?staff_id=abc&subject_id=1&from=bad')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['staff_id', 'subject_type', 'from']);

        $this->assertStringContainsString(__('audit::attributes.staff_id', [], $locale), $response->json('errors.staff_id.0'));
        $this->assertStringContainsString(__('audit::attributes.subject_type', [], $locale), $response->json('errors.subject_type.0'));
        $this->assertStringContainsString(__('audit::attributes.from', [], $locale), $response->json('errors.from.0'));
    }

    #[DataProvider('locales')]
    public function test_a_refusal_is_translated(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->withHeader('Accept-Language', $locale)
            ->getJson('/api/v1/audit-entries')
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden', [], $locale)]);
    }

    public function test_every_field_name_has_its_own_translation(): void
    {
        foreach (['from', 'to', 'staff_id', 'patient_id', 'subject_type', 'subject_id', 'action', 'outcome', 'per_page', 'cursor'] as $field) {
            $this->assertNotSame("audit::attributes.{$field}", __("audit::attributes.{$field}", [], 'en'));
            $this->assertNotSame(__("audit::attributes.{$field}", [], 'en'), __("audit::attributes.{$field}", [], 'ar'));
        }
    }

    public function test_a_search_has_the_same_structure_and_identifiers_in_both_languages(): void
    {
        AuditEntry::factory()->create([
            'action' => AuditAction::DiscountSet,
            'subject_type' => AuditSubject::Charge,
            'subject_id' => 1,
            'details' => ['fields' => ['status'], 'values' => ['status' => ['from' => 'unpaid', 'to' => 'paid']]],
        ]);

        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/audit-entries?action=discount.set')->assertOk()->json('data');
        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/audit-entries?action=discount.set')->assertOk()->json('data');

        $this->assertSame($english, $arabic);
        $this->assertSame('discount.set', $arabic[0]['action']);
        $this->assertSame('succeeded', $arabic[0]['outcome']);
        $this->assertSame('charge', $arabic[0]['subject']['type']);
    }
}
