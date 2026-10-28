<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff list a doctor's bookable slots for a date range (US2, FR-008–FR-012). The clock is fixed
 * at Sunday 2026-11-01 08:00, clinic time.
 */
class SlotsTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()
            ->withPeriod('sunday', '09:00', '10:00')
            ->withPeriod('sunday', '17:00', '21:00')
            ->create(['doctor_id' => $this->doctor->id]);
    }

    public function test_a_days_slots_are_cut_from_its_periods(): void
    {
        $response = $this->slots('2026-11-01', '2026-11-01')->assertOk()->assertJsonCount(15, 'data');

        $this->assertSame(['date' => '2026-11-01', 'start' => '09:00', 'end' => '09:20'], $response->json('data.0'));
        $this->assertSame(['date' => '2026-11-01', 'start' => '20:40', 'end' => '21:00'], $response->json('data.14'));
        foreach ($response->json('data') as $slot) {
            $this->assertSame(['date', 'start', 'end'], array_keys($slot));
        }
    }

    public function test_slots_that_have_started_are_not_offered(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:30', 'Africa/Cairo'));

        $this->slots('2026-11-01', '2026-11-01')->assertOk()->assertJsonPath('data.0.start', '09:40');
    }

    public function test_a_range_covers_every_matching_weekday_in_order(): void
    {
        $response = $this->slots('2026-11-01', '2026-11-08')->assertOk()->assertJsonCount(30, 'data');

        $this->assertSame(['2026-11-01', '2026-11-08'], array_values(array_unique(array_column($response->json('data'), 'date'))));
    }

    public function test_whole_day_time_off_removes_that_dates_slots(): void
    {
        DoctorTimeOff::factory()->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-08', 'end_date' => '2026-11-08']);

        $response = $this->slots('2026-11-01', '2026-11-08')->assertOk();

        $this->assertSame(['2026-11-01'], array_values(array_unique(array_column($response->json('data'), 'date'))));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'starts yesterday' => ['2026-10-31', '2026-11-01', 'from'],
            'ends before it starts' => ['2026-11-05', '2026-11-04', 'to'],
            'longer than 31 days' => ['2026-11-01', '2026-12-02', 'to'],
            'beyond the 90-day horizon' => ['2027-01-01', '2027-01-31', 'to'],
            'not a date' => ['2026-11-01', 'soon', 'to'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_ranges_are_rejected(string $from, string $to, string $field): void
    {
        $this->slots($from, $to)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_a_31_day_range_ending_on_the_horizon_is_accepted(): void
    {
        $this->slots('2027-01-01', '2027-01-30')->assertOk();
        $this->slots('2026-11-01', '2026-12-01')->assertOk();
    }

    public function test_both_dates_are_required(): void
    {
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_a_doctor_without_a_schedule_has_no_slots(): void
    {
        $other = Doctor::factory()->create();

        $this->getJson("/api/v1/doctors/{$other->id}/slots?from=2026-11-01&to=2026-11-07")->assertOk()->assertExactJson(['data' => []]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function inactiveAccounts(): array
    {
        return [
            'deactivated' => [['is_active' => false]],
            'no longer a doctor' => [['role' => Role::Receptionist]],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('inactiveAccounts')]
    public function test_an_inactive_doctor_has_no_slots(array $change): void
    {
        $this->doctor->user->update($change);

        $this->slots('2026-11-01', '2026-11-07')->assertOk()->assertExactJson(['data' => []]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherReaders(): array
    {
        return ['administrator' => ['administrator'], 'doctor' => ['doctor']];
    }

    #[DataProvider('otherReaders')]
    public function test_every_role_may_list_slots(string $role): void
    {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->slots('2026-11-01', '2026-11-01')->assertOk()->assertJsonCount(15, 'data');
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->getJson('/api/v1/doctors/999999/slots?from=2026-11-01&to=2026-11-01')->assertNotFound();
    }

    private function slots(string $from, string $to): TestResponse
    {
        return $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from={$from}&to={$to}");
    }
}
