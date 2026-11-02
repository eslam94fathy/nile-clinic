<?php

namespace Tests\Feature\Appointments;

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
 * Reception records that a patient did not come, once the appointment has started (US4, FR-013,
 * FR-014). Booked at Sunday 2026-11-01 08:00 for 09:20, clinic time.
 */
class NoShowTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private User $receptionist;

    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $this->doctor->id]);
        $this->appointment = $this->postJson('/api/v1/appointments', [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $this->doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ])->assertCreated()->json('data.id');
    }

    public function test_an_appointment_is_marked_as_no_show_from_its_start(): void
    {
        $this->at('09:20');

        $this->noShow()
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show')
            ->assertJsonPath('data.status_changed_by', ['id' => $this->receptionist->id, 'name' => $this->receptionist->name])
            ->assertJsonPath('data.status_changed_at', now()->toISOString())
            ->assertJsonPath('data.cancellation_reason', null);
    }

    public function test_an_appointment_that_has_not_started_cannot_be_marked(): void
    {
        $this->at('09:19');

        $this->noShow()
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', __('appointments::messages.not_started'));
    }

    public function test_a_no_show_cannot_be_marked_again(): void
    {
        $this->at('09:30');
        $this->noShow()->assertOk();

        $this->assertNotChangeable($this->noShow());
    }

    public function test_a_cancelled_appointment_cannot_be_marked(): void
    {
        $this->postJson("/api/v1/appointments/{$this->appointment}/cancel")->assertOk();
        $this->at('09:30');

        $this->assertNotChangeable($this->noShow());
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function laterChanges(): array
    {
        return [
            'reschedule' => ['reschedule', ['date' => '2026-11-08', 'start' => '09:00']],
            'cancel' => ['cancel', []],
        ];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('laterChanges')]
    public function test_a_no_show_is_final(string $action, array $body): void
    {
        $this->at('09:30');
        $this->noShow()->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $this->assertNotChangeable($this->postJson("/api/v1/appointments/{$this->appointment}/{$action}", $body));
    }

    public function test_a_no_show_does_not_hold_its_slot(): void
    {
        $this->at('09:30');
        $this->noShow()->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $starts = array_column($this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from=2026-11-01&to=2026-11-01")->json('data'), 'start');
        $this->assertSame(['09:00', '09:20', '09:40'], $starts);
    }

    public function test_an_unknown_appointment_is_not_found(): void
    {
        $this->postJson('/api/v1/appointments/999999/no-show')->assertNotFound();
    }

    public function test_a_doctor_cannot_mark_a_no_show(): void
    {
        $this->at('09:30');
        Sanctum::actingAs($this->doctor->user);

        $this->noShow()->assertForbidden();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-11-01 {$time}", 'Africa/Cairo'));
    }

    private function noShow(): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$this->appointment}/no-show");
    }

    private function assertNotChangeable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }
}
