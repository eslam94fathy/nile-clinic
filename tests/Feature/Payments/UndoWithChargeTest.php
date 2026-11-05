<?php

namespace Tests\Feature\Payments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Undoing a check-in removes the visit, so it is refused while the visit has an active charge; a
 * cancelled charge stays on record after the visit is gone (US5, FR-014, research R4).
 */
class UndoWithChargeTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    private QueueEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $doctor = Doctor::factory()->create();
        $this->appointment = Appointment::factory()->at('2026-11-01', '09:20')->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CheckedIn,
        ]);
        $this->entry = QueueEntry::factory()->forAppointment($this->appointment)->create();
        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_with_no_charge_the_undo_works_as_before(): void
    {
        $this->undo()->assertNoContent();

        $this->assertNull($this->entry->fresh());
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->fresh()->status);
    }

    public function test_an_active_charge_blocks_the_undo_and_nothing_changes(): void
    {
        Charge::factory()->forEntry($this->entry)->create();

        $this->undo()
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', __('queues::messages.has_active_charge'));

        $this->assertNotNull($this->entry->fresh());
        $this->assertSame(AppointmentStatus::CheckedIn, $this->appointment->fresh()->status);
    }

    public function test_a_paid_charge_blocks_the_undo(): void
    {
        Charge::factory()->forEntry($this->entry)->paid()->create();

        $this->undo()->assertUnprocessable()->assertJsonPath('errors.entry.0', __('queues::messages.has_active_charge'));
    }

    public function test_after_cancelling_the_undo_works_and_the_charge_stays_on_record(): void
    {
        $charge = Charge::factory()->forEntry($this->entry)->create();
        $patientId = $charge->patient_id;
        $doctorId = $charge->doctor_id;

        $this->undo()->assertUnprocessable();
        $this->postJson("/api/v1/charges/{$charge->id}/cancel", ['reason' => 'Synthetic wrong visit'])->assertOk();
        $this->undo()->assertNoContent();

        $this->assertNull($this->entry->fresh());
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->fresh()->status);

        $this->getJson("/api/v1/charges/{$charge->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.visit_id', null)
            ->assertJsonPath('data.patient.id', $patientId)
            ->assertJsonPath('data.doctor.id', $doctorId)
            ->assertJsonPath('data.date', '2026-11-01');
    }

    public function test_a_cancelled_charge_from_before_does_not_block_the_undo(): void
    {
        Charge::factory()->forEntry($this->entry)->cancelled()->create();

        $this->undo()->assertNoContent();
    }

    private function undo(): TestResponse
    {
        return $this->deleteJson("/api/v1/queue/{$this->entry->id}");
    }
}
