<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Exceptions\QueueConflictException;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message from the queue endpoints exists in English and Arabic, with identical JSON
 * structure across locales; statuses are never translated (FR-019, SC-006). The clock is fixed at
 * Sunday 2026-11-01 08:00, clinic time.
 */
class QueuesMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);
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
    public function test_check_in_message(string $locale): void
    {
        $appointment = Appointment::factory()->at('2026-11-02', '09:00')->create(['doctor_id' => $this->doctor->id]);

        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', $this->module($locale, 'messages', 'not_checkable'));
    }

    #[DataProvider('locales')]
    public function test_walk_in_messages(string $locale): void
    {
        $patient = Patient::factory()->create();

        $this->walkIn(999999, $this->doctor->id, $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', $this->module($locale, 'messages', 'patient_not_found'));

        $this->walkIn($patient->id, 999999, $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', $this->module($locale, 'messages', 'doctor_not_found'));

        $this->walkIn($patient->id, $this->doctor->id, $locale)->assertCreated();

        $this->walkIn($patient->id, $this->doctor->id, $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', $this->module($locale, 'messages', 'already_in_queue'));

        $this->doctor->user->update(['is_active' => false]);

        $this->walkIn(Patient::factory()->create()->id, $this->doctor->id, $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', $this->module($locale, 'messages', 'doctor_inactive'));
    }

    #[DataProvider('locales')]
    public function test_correction_messages(string $locale): void
    {
        $this->postJson('/api/v1/queue/999999/leave', [], $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'entry_not_found')]);

        $id = $this->walkIn(Patient::factory()->create()->id, $this->doctor->id, $locale)->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/queue/{$id}", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', $this->module($locale, 'messages', 'not_changeable'));
    }

    #[DataProvider('locales')]
    public function test_doctor_messages(string $locale): void
    {
        $this->walkIn(Patient::factory()->create()->id, $this->doctor->id, $locale)->assertCreated();
        Sanctum::actingAs($this->doctor->user);

        $this->finish($locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', $this->module($locale, 'messages', 'no_current_visit'));

        $this->callNext($locale)->assertOk();

        $this->callNext($locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', $this->module($locale, 'messages', 'visit_in_progress'));

        $this->finish($locale)->assertOk();

        $this->callNext($locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', $this->module($locale, 'messages', 'nobody_waiting'));
    }

    /**
     * The backstops cannot be reached through the API while the locks hold (research R3), so the
     * exception is rendered directly.
     */
    #[DataProvider('locales')]
    public function test_conflict_messages(string $locale): void
    {
        app()->setLocale($locale);

        foreach (['already_checked_in', 'patient_with_another_doctor'] as $reason) {
            $response = (new QueueConflictException($reason))->render();

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame(['message' => $this->module($locale, 'messages', $reason), 'reason' => $reason], $response->getData(true));
        }
    }

    #[DataProvider('locales')]
    public function test_field_names_are_translated(string $locale): void
    {
        $required = (require lang_path("{$locale}/validation.php"))['required'];

        $this->postJson('/api/v1/queue/walk-ins', [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', strtr($required, [':attribute' => $this->module($locale, 'attributes', 'patient_id')]));
    }

    #[DataProvider('locales')]
    public function test_forbidden_for_doctors(string $locale): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson('/api/v1/queue', $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', (require lang_path("{$locale}/auth.php"))['forbidden']);
    }

    public function test_response_structure_is_identical_in_both_languages(): void
    {
        $english = $this->postJson('/api/v1/queue/walk-ins', [], $this->language('en'));
        $arabic = $this->postJson('/api/v1/queue/walk-ins', [], $this->language('ar'));

        $this->assertSame([$english->status(), array_keys($english->json('errors'))], [$arabic->status(), array_keys($arabic->json('errors'))]);
        $this->assertNotSame($english->json('errors.patient_id.0'), $arabic->json('errors.patient_id.0'));
    }

    public function test_entries_and_statuses_are_never_translated(): void
    {
        $this->walkIn(Patient::factory()->create()->id, $this->doctor->id, 'en')->assertCreated();

        $english = $this->getJson('/api/v1/queue', $this->language('en'))->assertOk();
        $arabic = $this->getJson('/api/v1/queue', $this->language('ar'))->assertOk();

        $this->assertSame($english->json('data'), $arabic->json('data'));
        $this->assertSame('waiting', $arabic->json('data.0.status'));
    }

    private function walkIn(int $patientId, int $doctorId, string $locale): TestResponse
    {
        return $this->postJson('/api/v1/queue/walk-ins', ['patient_id' => $patientId, 'doctor_id' => $doctorId], $this->language($locale));
    }

    private function callNext(string $locale): TestResponse
    {
        return $this->postJson('/api/v1/doctors/me/queue/call-next', [], $this->language($locale));
    }

    private function finish(string $locale): TestResponse
    {
        return $this->postJson('/api/v1/doctors/me/queue/finish', [], $this->language($locale));
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
        return (require app_path("Modules/Queues/lang/{$locale}/{$file}.php"))[$key];
    }
}
