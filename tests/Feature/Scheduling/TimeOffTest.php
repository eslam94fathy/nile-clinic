<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators record and remove a doctor's time off, which hides slots; all staff list it
 * (US3, FR-013–FR-017). The clock is fixed at Sunday 2026-11-01 08:00, clinic time; the doctor
 * works Sunday–Thursday 09:00–13:00 and 17:00–21:00 with 20-minute appointments.
 */
class TimeOffTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->weekdays()->create(['doctor_id' => $this->doctor->id]);
    }

    public function test_whole_days_are_recorded_and_hide_every_slot_on_them(): void
    {
        $response = $this->record(['start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'reason' => 'Synthetic conference'])
            ->assertCreated()
            ->assertJsonPath('data.doctor_id', $this->doctor->id)
            ->assertJsonPath('data.start_date', '2026-11-02')
            ->assertJsonPath('data.end_date', '2026-11-03')
            ->assertJsonPath('data.start_time', null)
            ->assertJsonPath('data.end_time', null)
            ->assertJsonPath('data.reason', 'Synthetic conference');

        $this->assertEqualsCanonicalizing(
            ['id', 'doctor_id', 'start_date', 'end_date', 'start_time', 'end_time', 'reason', 'created_at'],
            array_keys($response->json('data')),
        );
        $this->slots('2026-11-02', '2026-11-03')->assertExactJson(['data' => []]);
    }

    public function test_part_of_a_day_hides_only_the_overlapping_slots(): void
    {
        $this->record(['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_time' => '10:00', 'end_time' => '12:00'])
            ->assertCreated()
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.end_time', '12:00');

        $starts = array_column($this->slots('2026-11-04', '2026-11-04')->json('data'), 'start');

        $this->assertContains('09:40', $starts);
        $this->assertContains('12:00', $starts);
        $this->assertSame([], array_values(array_filter($starts, fn (string $s) => $s >= '10:00' && $s < '12:00')));
    }

    public function test_overlapping_time_off_is_accepted(): void
    {
        $this->record(['start_date' => '2026-11-02', 'end_date' => '2026-11-03'])->assertCreated();
        $this->record(['start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '10:00'])->assertCreated();

        $this->assertDatabaseCount('doctor_time_off', 2);
    }

    public function test_part_of_today_already_started_is_accepted(): void
    {
        $this->record(['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'start_time' => '07:00', 'end_time' => '10:00'])->assertCreated();

        $this->assertSame('10:00', $this->slots('2026-11-01', '2026-11-01')->json('data.0.start'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTimeOff(): array
    {
        return [
            'starts yesterday' => [['start_date' => '2026-10-31', 'end_date' => '2026-11-02'], 'start_date'],
            'ends before it starts' => [['start_date' => '2026-11-05', 'end_date' => '2026-11-04'], 'end_date'],
            'start date missing' => [['end_date' => '2026-11-04'], 'start_date'],
            'only a start time' => [['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_time' => '10:00'], 'end_time'],
            'times on two dates' => [['start_date' => '2026-11-04', 'end_date' => '2026-11-05', 'start_time' => '10:00', 'end_time' => '12:00'], 'start_time'],
            'time off the 5-minute grid' => [['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_time' => '10:03', 'end_time' => '12:00'], 'start_time'],
            'end not after start' => [['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_time' => '12:00', 'end_time' => '12:00'], 'end_time'],
            'reason too long' => [['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'reason' => str_repeat('a', 256)], 'reason'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidTimeOff')]
    public function test_invalid_time_off_is_rejected(array $body, string $field): void
    {
        $this->record($body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('doctor_time_off', 0);
    }

    public function test_the_list_shows_entries_overlapping_the_range_in_order(): void
    {
        $later = $this->timeOff(['start_date' => '2026-11-10', 'end_date' => '2026-11-10']);
        $partial = $this->timeOff(['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'start_minute' => 600, 'end_minute' => 660]);
        $whole = $this->timeOff(['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
        $this->timeOff(['start_date' => '2026-12-10', 'end_date' => '2026-12-10']);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $response = $this->getJson($this->url().'?from=2026-11-01&to=2026-11-30')->assertOk();

        $this->assertSame([$whole->id, $partial->id, $later->id], array_column($response->json('data'), 'id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidListRanges(): array
    {
        return [
            'from after to' => ['from=2026-11-30&to=2026-11-01'],
            'more than 366 days' => ['from=2026-11-01&to=2027-11-03'],
            'missing to' => ['from=2026-11-01'],
        ];
    }

    #[DataProvider('invalidListRanges')]
    public function test_invalid_list_ranges_are_rejected(string $query): void
    {
        $this->getJson($this->url().'?'.$query)->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_removing_future_time_off_brings_its_slots_back(): void
    {
        $entry = $this->timeOff(['start_date' => '2026-11-02', 'end_date' => '2026-11-02']);

        $this->deleteJson($this->url()."/{$entry->id}")->assertNoContent();

        $this->assertDatabaseCount('doctor_time_off', 0);
        $this->assertCount(24, $this->slots('2026-11-02', '2026-11-02')->json('data'));
    }

    public function test_ongoing_time_off_can_be_removed(): void
    {
        $entry = $this->timeOff(['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:50', 'Africa/Cairo'));

        $this->deleteJson($this->url()."/{$entry->id}")->assertNoContent();

        $this->assertSame('12:00', $this->slots('2026-11-02', '2026-11-02')->json('data.0.start'));
    }

    public function test_ended_time_off_cannot_be_removed(): void
    {
        $entry = $this->timeOff(['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_minute' => 600, 'end_minute' => 720]);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 13:00', 'Africa/Cairo'));

        $this->deleteJson($this->url()."/{$entry->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_off' => __('scheduling::messages.time_off_ended')]);

        $this->assertDatabaseCount('doctor_time_off', 1);
    }

    public function test_another_doctors_or_an_unknown_entry_is_not_found(): void
    {
        $other = $this->timeOff(['doctor_id' => Doctor::factory()->create()->id]);

        $this->deleteJson($this->url()."/{$other->id}")->assertNotFound();
        $this->deleteJson($this->url().'/999999')->assertNotFound();

        $this->assertDatabaseCount('doctor_time_off', 1);
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->postJson('/api/v1/doctors/999999/time-off', ['start_date' => '2026-11-02', 'end_date' => '2026-11-02'])->assertNotFound();
        $this->getJson('/api/v1/doctors/999999/time-off?from=2026-11-01&to=2026-11-30')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function record(array $body): TestResponse
    {
        return $this->postJson($this->url(), $body);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function timeOff(array $attributes): DoctorTimeOff
    {
        return DoctorTimeOff::factory()->create(['doctor_id' => $this->doctor->id, ...$attributes]);
    }

    private function slots(string $from, string $to): TestResponse
    {
        return $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from={$from}&to={$to}")->assertOk();
    }

    private function url(): string
    {
        return "/api/v1/doctors/{$this->doctor->id}/time-off";
    }
}
