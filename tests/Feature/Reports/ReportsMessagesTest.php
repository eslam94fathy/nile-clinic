<?php

namespace Tests\Feature\Reports;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message in English and Arabic, with an identical structure; statuses and methods are never
 * translated (SC-008, FR-023).
 */
class ReportsMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const PATHS = [
        '/api/v1/reports/money-collected',
        '/api/v1/reports/billing',
        '/api/v1/reports/activity',
        '/api/v1/reports/times',
    ];

    private const PERIOD = '?from=2026-11-01&to=2026-11-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reportsInEachLocale(): array
    {
        $cases = [];

        foreach (self::PATHS as $path) {
            foreach (['en', 'ar'] as $locale) {
                $cases["{$path} in {$locale}"] = [$path, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('reportsInEachLocale')]
    public function test_the_range_message_is_translated(string $path, string $locale): void
    {
        $this->withHeader('Accept-Language', $locale)
            ->getJson("{$path}?from=2026-01-01&to=2027-01-02")
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', __('reports::messages.range_too_long', ['days' => 366], $locale));
    }

    #[DataProvider('reportsInEachLocale')]
    public function test_validation_uses_the_translated_attributes(string $path, string $locale): void
    {
        $attribute = __('reports::attributes.from', [], $locale);

        $this->withHeader('Accept-Language', $locale)
            ->getJson("{$path}?to=2026-11-01")
            ->assertUnprocessable()
            ->assertJsonPath('errors.from.0', __('validation.required', ['attribute' => $attribute], $locale));
    }

    #[DataProvider('reportsInEachLocale')]
    public function test_the_unknown_doctor_message_is_translated(string $path, string $locale): void
    {
        $this->withHeader('Accept-Language', $locale)
            ->getJson($path.self::PERIOD.'&doctor_id=999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('doctors::messages.doctor_not_found', [], $locale)]);
    }

    #[DataProvider('reportsInEachLocale')]
    public function test_the_forbidden_message_is_translated(string $path, string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->withHeader('Accept-Language', $locale)
            ->getJson($path.self::PERIOD)
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden', [], $locale)]);
    }

    public function test_the_arabic_and_english_messages_are_the_ones_approved(): void
    {
        $this->assertSame('The period cannot be longer than 366 days.', __('reports::messages.range_too_long', ['days' => 366], 'en'));
        $this->assertSame('لا يمكن أن تتجاوز الفترة 366 يومًا.', __('reports::messages.range_too_long', ['days' => 366], 'ar'));
        $this->assertSame('The from date field is required.', __('validation.required', ['attribute' => __('reports::attributes.from', [], 'en')], 'en'));
        $this->assertSame('حقل تاريخ البداية مطلوب.', __('validation.required', ['attribute' => __('reports::attributes.from', [], 'ar')], 'ar'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return array_combine(self::PATHS, array_map(fn (string $path) => [$path], self::PATHS));
    }

    #[DataProvider('paths')]
    public function test_a_report_is_identical_in_both_locales(string $path): void
    {
        $visit = QueueEntry::factory()->seen()->create();
        $charge = Charge::factory()->forEntry($visit)->partiallyPaid('100.00')->create();

        Payment::factory()->create(['charge_id' => $charge->id, 'created_at' => '2026-11-01 08:00:00']);
        Encounter::factory()->forEntry($visit)->create();
        Appointment::factory()->at('2026-11-01', '09:00')->create(['status' => AppointmentStatus::NoShow, 'doctor_id' => $visit->doctor_id]);

        $english = $this->withHeader('Accept-Language', 'en')->getJson($path.self::PERIOD)->assertOk()->json();
        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson($path.self::PERIOD)->assertOk()->json();

        $this->assertSame($this->withoutPageLabels($english), $this->withoutPageLabels($arabic));
    }

    /**
     * Laravel translates its "Previous" and "Next" paging labels, as on every paged list in the API. They are
     * display text, not report fields, so they alone may differ.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function withoutPageLabels(array $response): array
    {
        foreach ($response['meta']['links'] ?? [] as $index => $link) {
            unset($response['meta']['links'][$index]['label']);
        }

        return $response;
    }
}
