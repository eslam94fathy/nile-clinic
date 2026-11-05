<?php

namespace Tests\Feature\Reports;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The period and doctor filter shared by every report (FR-002, FR-003, research R10).
 */
class ReportValidationTest extends TestCase
{
    use RefreshDatabase;

    /** Path => whether it is paged. */
    private const ENDPOINTS = [
        '/api/v1/reports/money-collected' => false,
        '/api/v1/reports/billing' => true,
        '/api/v1/reports/activity' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        $cases = [];

        foreach (array_keys(self::ENDPOINTS) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagedEndpoints(): array
    {
        return array_filter(self::endpoints(), fn (array $case) => self::ENDPOINTS[$case[0]]);
    }

    /**
     * Query string => the field it is refused on.
     *
     * @return array<string, string>
     */
    private static function invalidPeriods(): array
    {
        return [
            'to=2026-11-01' => 'from',
            'from=2026-11-01' => 'to',
            'from=2026-02-30&to=2026-03-01' => 'from',
            'from=01-11-2026&to=2026-11-01' => 'from',
            'from=2026-11-02&to=2026-11-01' => 'to',
        ];
    }

    #[DataProvider('endpoints')]
    public function test_an_invalid_period_is_refused_on_the_right_field(string $path): void
    {
        foreach (self::invalidPeriods() as $query => $field) {
            $this->getJson("{$path}?{$query}")->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
    }

    #[DataProvider('endpoints')]
    public function test_367_days_is_refused_with_the_range_message(string $path): void
    {
        $this->getJson("{$path}?from=2026-01-01&to=2027-01-02")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => __('reports::messages.range_too_long', ['days' => 366])]);
    }

    #[DataProvider('endpoints')]
    public function test_exactly_366_days_is_accepted(string $path): void
    {
        $this->getJson("{$path}?from=2026-01-01&to=2027-01-01")->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_one_day_is_accepted(string $path): void
    {
        $this->getJson("{$path}?from=2026-11-01&to=2026-11-01")->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_an_invalid_doctor_filter_is_refused(string $path): void
    {
        foreach (['abc', '0'] as $doctorId) {
            $this->getJson("{$path}?from=2026-11-01&to=2026-11-01&doctor_id={$doctorId}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['doctor_id']);
        }
    }

    #[DataProvider('endpoints')]
    public function test_an_unknown_doctor_is_not_found(string $path): void
    {
        $this->getJson("{$path}?from=2026-11-01&to=2026-11-01&doctor_id=999999")
            ->assertNotFound()
            ->assertExactJson(['message' => __('doctors::messages.doctor_not_found')]);
    }

    #[DataProvider('endpoints')]
    public function test_a_known_doctor_with_no_activity_is_accepted(string $path): void
    {
        $doctor = Doctor::factory()->create();

        $this->getJson("{$path}?from=2026-11-01&to=2026-11-01&doctor_id={$doctor->id}")->assertOk();
    }

    #[DataProvider('pagedEndpoints')]
    public function test_an_invalid_page_size_is_refused(string $path): void
    {
        foreach (['0', '101'] as $perPage) {
            $this->getJson("{$path}?from=2026-11-01&to=2026-11-01&per_page={$perPage}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['per_page']);
        }
    }
}
