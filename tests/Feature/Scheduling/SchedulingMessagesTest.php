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
 * Every message from the scheduling endpoints exists in English and Arabic, with identical JSON
 * structure across locales; day identifiers in responses are never translated (FR-021, SC-006).
 */
class SchedulingMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->doctor = Doctor::factory()->create();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return [
            'en' => ['en'],
            'ar' => ['ar'],
        ];
    }

    #[DataProvider('locales')]
    public function test_period_rules_name_the_day(string $locale): void
    {
        $response = $this->putJson($this->url('schedule'), [
            'appointment_minutes' => 20,
            'periods' => [['day' => 'sunday', 'start' => '09:00', 'end' => '13:00'], ['day' => 'sunday', 'start' => '12:00', 'end' => '14:00']],
        ], $this->language($locale))->assertUnprocessable();

        $this->assertSame(
            strtr($this->module($locale, 'messages', 'periods_overlap'), [':day' => $this->module($locale, 'days', 'sunday')]),
            $response->json('errors')['periods.1'][0],
        );
    }

    #[DataProvider('locales')]
    public function test_slot_range_messages(string $locale): void
    {
        $this->getJson($this->url('slots').'?from=2026-11-01&to=2026-12-15', $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', strtr($this->module($locale, 'messages', 'range_too_long'), [':days' => '31']));

        $this->getJson($this->url('slots').'?from=2027-01-10&to=2027-02-05', $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', strtr($this->module($locale, 'messages', 'beyond_horizon'), [':days' => '90']));
    }

    #[DataProvider('locales')]
    public function test_time_off_messages(string $locale): void
    {
        $this->postJson($this->url('time-off'), [
            'start_date' => '2026-11-04', 'end_date' => '2026-11-05', 'start_time' => '10:00', 'end_time' => '12:00',
        ], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', $this->module($locale, 'messages', 'time_off_times_single_day'));

        $ended = DoctorTimeOff::factory()->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Africa/Cairo'));

        $this->deleteJson($this->url("time-off/{$ended->id}"), [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.time_off.0', $this->module($locale, 'messages', 'time_off_ended'));

        $this->deleteJson($this->url('time-off/999999'), [], $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'time_off_not_found')]);
    }

    #[DataProvider('locales')]
    public function test_forbidden_for_receptionists(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->deleteJson($this->url('schedule'), [], $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', (require lang_path("{$locale}/auth.php"))['forbidden']);
    }

    public function test_response_structure_is_identical_in_both_languages(): void
    {
        $body = ['appointment_minutes' => 7, 'periods' => [['day' => 'funday', 'start' => '9', 'end' => '25:00']]];

        $english = $this->putJson($this->url('schedule'), $body, $this->language('en'));
        $arabic = $this->putJson($this->url('schedule'), $body, $this->language('ar'));

        $this->assertSame($this->shape($english), $this->shape($arabic));
        $this->assertNotSame($english->json('errors.appointment_minutes.0'), $arabic->json('errors.appointment_minutes.0'));
    }

    public function test_day_identifiers_are_never_translated(): void
    {
        DoctorSchedule::factory()->weekdays()->create(['doctor_id' => $this->doctor->id]);

        $english = $this->getJson($this->url('schedule'), $this->language('en'))->assertOk();
        $arabic = $this->getJson($this->url('schedule'), $this->language('ar'))->assertOk();

        $this->assertSame($english->json(), $arabic->json());
        $this->assertSame('sunday', $arabic->json('data.days.0.day'));
    }

    /**
     * @return array<string, string>
     */
    private function language(string $locale): array
    {
        return ['Accept-Language' => $locale];
    }

    private function module(string $locale, string $file, string $key): string
    {
        return (require app_path("Modules/Scheduling/lang/{$locale}/{$file}.php"))[$key];
    }

    private function url(string $path): string
    {
        return "/api/v1/doctors/{$this->doctor->id}/{$path}";
    }

    /**
     * The status and the error keys, without the translated texts.
     *
     * @return array{int, list<string>}
     */
    private function shape(TestResponse $response): array
    {
        return [$response->status(), array_keys($response->json('errors'))];
    }
}
