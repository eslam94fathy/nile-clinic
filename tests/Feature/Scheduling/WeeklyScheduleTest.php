<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators set, replace, and clear a doctor's weekly schedule; all staff read it (US1,
 * FR-001–FR-007, SC-004).
 */
class WeeklyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->doctor = Doctor::factory()->create();
    }

    public function test_administrator_sets_a_schedule_returned_grouped_by_day(): void
    {
        $response = $this->save($this->sample())->assertOk()
            ->assertJsonPath('data.doctor_id', $this->doctor->id)
            ->assertJsonPath('data.appointment_minutes', 20)
            ->assertJsonPath('data.days.0.periods', [['start' => '09:00', 'end' => '10:00'], ['start' => '17:00', 'end' => '21:00']])
            ->assertJsonPath('data.days.2.periods', [['start' => '10:00', 'end' => '14:00']])
            ->assertJsonPath('data.days.1.periods', []);

        $this->assertEqualsCanonicalizing(['doctor_id', 'appointment_minutes', 'days', 'updated_at'], array_keys($response->json('data')));
        $this->assertSame(self::DAYS, array_column($response->json('data.days'), 'day'));
        $this->assertNotNull($response->json('data.updated_at'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return ['receptionist' => ['receptionist'], 'doctor' => ['doctor']];
    }

    #[DataProvider('readers')]
    public function test_every_role_reads_the_same_schedule(string $role): void
    {
        $saved = $this->save($this->sample())->json('data');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson($this->url())->assertOk()->assertExactJson(['data' => $saved]);
    }

    public function test_a_new_schedule_replaces_the_old_one(): void
    {
        $this->save($this->sample());

        $this->save(['appointment_minutes' => 30, 'periods' => [['day' => 'monday', 'start' => '08:00', 'end' => '12:00']]])
            ->assertOk()
            ->assertJsonPath('data.appointment_minutes', 30)
            ->assertJsonPath('data.days.0.periods', [])
            ->assertJsonPath('data.days.1.periods', [['start' => '08:00', 'end' => '12:00']])
            ->assertJsonPath('data.days.2.periods', []);

        $this->assertDatabaseCount('doctor_schedules', 1);
        $this->assertDatabaseCount('schedule_periods', 1);
    }

    public function test_a_doctor_without_a_schedule_has_the_empty_shape(): void
    {
        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.doctor_id', $this->doctor->id)
            ->assertJsonPath('data.appointment_minutes', null)
            ->assertJsonPath('data.updated_at', null);

        $this->assertSame(self::DAYS, array_column($response->json('data.days'), 'day'));
        $this->assertSame([[], [], [], [], [], [], []], array_column($response->json('data.days'), 'periods'));
    }

    public function test_clearing_removes_the_schedule_and_is_idempotent(): void
    {
        $this->save($this->sample());

        $this->deleteJson($this->url())->assertNoContent();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.appointment_minutes', null);
        $this->deleteJson($this->url())->assertNoContent();

        $this->assertDatabaseCount('doctor_schedules', 0);
        $this->assertDatabaseCount('schedule_periods', 0);
    }

    public function test_a_schedule_with_no_periods_is_allowed(): void
    {
        $this->save(['appointment_minutes' => 15, 'periods' => []])->assertOk()->assertJsonPath('data.appointment_minutes', 15);
    }

    public function test_a_period_can_end_at_midnight(): void
    {
        $this->save(['appointment_minutes' => 30, 'periods' => [['day' => 'friday', 'start' => '22:00', 'end' => '24:00']]])
            ->assertOk()
            ->assertJsonPath('data.days.5.periods', [['start' => '22:00', 'end' => '24:00']]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSchedules(): array
    {
        $period = fn (string $day, string $start, string $end) => ['day' => $day, 'start' => $start, 'end' => $end];

        return [
            'appointment too short' => [['appointment_minutes' => 4, 'periods' => []], 'appointment_minutes'],
            'appointment too long' => [['appointment_minutes' => 245, 'periods' => []], 'appointment_minutes'],
            'appointment not a multiple of 5' => [['appointment_minutes' => 7, 'periods' => []], 'appointment_minutes'],
            'appointment missing' => [['periods' => []], 'appointment_minutes'],
            'periods missing' => [['appointment_minutes' => 20], 'periods'],
            'unknown day' => [['appointment_minutes' => 20, 'periods' => [$period('funday', '09:00', '10:00')]], 'periods.0.day'],
            'start off the 5-minute grid' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '09:03', '10:00')]], 'periods.0.start'],
            'start at 24:00' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '24:00', '24:00')]], 'periods.0.start'],
            'end after 24:00' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '23:00', '24:05')]], 'periods.0.end'],
            'end before start' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '10:00', '09:00')]], 'periods.0'],
            'shorter than one appointment' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '09:00', '09:10')]], 'periods.0'],
            'touching periods' => [['appointment_minutes' => 20, 'periods' => [$period('sunday', '09:00', '13:00'), $period('sunday', '13:00', '15:00')]], 'periods.1'],
            'five periods on one day' => [['appointment_minutes' => 20, 'periods' => [
                $period('monday', '08:00', '09:00'), $period('monday', '10:00', '11:00'), $period('monday', '12:00', '13:00'),
                $period('monday', '14:00', '15:00'), $period('monday', '16:00', '17:00'),
            ]], 'periods.4'],
            'more than 28 periods' => [['appointment_minutes' => 5, 'periods' => array_fill(0, 29, $period('sunday', '09:00', '10:00'))], 'periods'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_are_rejected_and_the_old_one_stays(array $body, string $field): void
    {
        $saved = $this->save($this->sample())->json('data');

        $this->save($body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->getJson($this->url())->assertExactJson(['data' => $saved]);
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->getJson('/api/v1/doctors/999999/schedule')->assertNotFound();
        $this->putJson('/api/v1/doctors/999999/schedule', $this->sample())->assertNotFound();
        $this->deleteJson('/api/v1/doctors/999999/schedule')->assertNotFound();

        $this->assertSame(0, DoctorSchedule::query()->count());
    }

    /**
     * Sunday 17:00–21:00 and 09:00–10:00 (given out of order), Tuesday 10:00–14:00, 20 minutes.
     *
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        return [
            'appointment_minutes' => 20,
            'periods' => [
                ['day' => 'sunday', 'start' => '17:00', 'end' => '21:00'],
                ['day' => 'sunday', 'start' => '09:00', 'end' => '10:00'],
                ['day' => 'tuesday', 'start' => '10:00', 'end' => '14:00'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function save(array $body): TestResponse
    {
        return $this->putJson($this->url(), $body);
    }

    private function url(): string
    {
        return "/api/v1/doctors/{$this->doctor->id}/schedule";
    }
}
