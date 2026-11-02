<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message from the appointments endpoints exists in English and Arabic, with identical JSON
 * structure across locales; statuses and conflict reasons are never translated (FR-021, SC-007).
 * The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class AppointmentsMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $this->doctor->id]);
        $this->patient = Patient::factory()->create();
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
    public function test_booking_messages(string $locale): void
    {
        $this->book(['start' => '08:00'], $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.start.0', $this->module($locale, 'messages', 'slot_unavailable'));

        $this->book(['patient_id' => 999999], $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', $this->module($locale, 'messages', 'patient_not_found'));

        $this->book(['doctor_id' => 999999], $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', $this->module($locale, 'messages', 'doctor_not_found'));
    }

    #[DataProvider('locales')]
    public function test_conflict_messages(string $locale): void
    {
        $this->book([], $locale)->assertCreated();

        $this->book(['patient_id' => Patient::factory()->create()->id], $locale)
            ->assertConflict()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'slot_booked'), 'reason' => 'slot_booked']);

        $other = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $other->id]);
        $this->book(['doctor_id' => $other->id], $locale)
            ->assertConflict()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'patient_busy'), 'reason' => 'patient_busy']);
    }

    #[DataProvider('locales')]
    public function test_status_change_messages(string $locale): void
    {
        $id = $this->book([], $locale)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/appointments/{$id}/no-show", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', $this->module($locale, 'messages', 'not_started'));

        $this->postJson("/api/v1/appointments/{$id}/cancel", [], $this->language($locale))->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->postJson("/api/v1/appointments/{$id}/cancel", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', $this->module($locale, 'messages', 'not_changeable'));
    }

    #[DataProvider('locales')]
    public function test_not_found_and_range_messages(string $locale): void
    {
        $this->getJson('/api/v1/appointments/999999', $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'not_found')]);

        $this->getJson('/api/v1/appointments?from=2026-11-01&to=2027-02-01', $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', strtr($this->module($locale, 'messages', 'range_too_long'), [':days' => '92']));
    }

    #[DataProvider('locales')]
    public function test_field_names_are_translated(string $locale): void
    {
        $required = (require lang_path("{$locale}/validation.php"))['required'];

        $this->postJson('/api/v1/appointments', [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', strtr($required, [':attribute' => $this->module($locale, 'attributes', 'patient_id')]));
    }

    #[DataProvider('locales')]
    public function test_forbidden_for_doctors(string $locale): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-07', $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', (require lang_path("{$locale}/auth.php"))['forbidden']);
    }

    public function test_response_structure_is_identical_in_both_languages(): void
    {
        $english = $this->book(['start' => '9', 'date' => 'soon'], 'en');
        $arabic = $this->book(['start' => '9', 'date' => 'soon'], 'ar');

        $this->assertSame([$english->status(), array_keys($english->json('errors'))], [$arabic->status(), array_keys($arabic->json('errors'))]);
        $this->assertNotSame($english->json('errors.start.0'), $arabic->json('errors.start.0'));
    }

    public function test_appointments_and_conflict_reasons_are_never_translated(): void
    {
        $id = $this->book([], 'en')->assertCreated()->json('data.id');

        $english = $this->getJson("/api/v1/appointments/{$id}", $this->language('en'))->assertOk();
        $arabic = $this->getJson("/api/v1/appointments/{$id}", $this->language('ar'))->assertOk();
        $this->assertSame($english->json(), $arabic->json());
        $this->assertSame('scheduled', $arabic->json('data.status'));

        $conflict = $this->book(['patient_id' => Patient::factory()->create()->id], 'ar')->assertConflict();
        $this->assertSame('slot_booked', $conflict->json('reason'));
        $this->assertSame(1, Appointment::query()->count());
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function book(array $change, string $locale): TestResponse
    {
        return $this->postJson('/api/v1/appointments', array_merge([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ], $change), $this->language($locale));
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
        return (require app_path("Modules/Appointments/lang/{$locale}/{$file}.php"))[$key];
    }
}
