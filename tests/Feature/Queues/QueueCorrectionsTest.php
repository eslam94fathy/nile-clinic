<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception marks a waiting patient as left or undoes a check-in (US5, FR-014, FR-015). The clock
 * is fixed at Sunday 2026-11-01 10:00, clinic time, so the appointments (09:00, 09:20) come before
 * the walk-in in queue order.
 */
class QueueCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Doctor $doctor;

    private Appointment $appointment1;

    private Appointment $appointment2;

    private int $entry1;

    private int $entry2;

    private int $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);

        $this->doctor = Doctor::factory()->create();
        $this->appointment1 = Appointment::factory()->at('2026-11-01', '09:00')->create(['doctor_id' => $this->doctor->id]);
        $this->appointment2 = Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id]);

        $this->entry1 = $this->checkIn($this->appointment1)->assertCreated()->json('data.id');
        $this->entry2 = $this->checkIn($this->appointment2)->assertCreated()->json('data.id');
        $this->walkIn = $this->addWalkIn()->assertCreated()->json('data.id');
    }

    public function test_leaving_records_when_and_who_and_keeps_the_entry(): void
    {
        $this->leave($this->entry1)
            ->assertOk()
            ->assertJsonPath('data.id', $this->entry1)
            ->assertJsonPath('data.status', 'left')
            ->assertJsonPath('data.left_at', now()->toISOString());

        $this->assertSame($this->receptionist->id, QueueEntry::query()->findOrFail($this->entry1)->left_by);
    }

    public function test_undo_removes_the_entry_and_schedules_the_appointment_again(): void
    {
        $this->undo($this->entry2)->assertNoContent();

        $this->assertNull(QueueEntry::query()->find($this->entry2));
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment2->refresh()->status);

        // Every feature 006 rule applies again: it has started, so it can be marked no-show.
        $this->postJson("/api/v1/appointments/{$this->appointment2->id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
    }

    public function test_a_queue_number_is_not_reused_after_undo(): void
    {
        $this->undo($this->entry2)->assertNoContent();

        $this->checkIn($this->appointment2)->assertCreated()->assertJsonPath('data.queue_number', 4);
    }

    public function test_a_walk_in_cannot_be_undone(): void
    {
        $this->assertNotChangeable($this->undo($this->walkIn));

        $this->assertNotNull(QueueEntry::query()->find($this->walkIn));
    }

    public function test_entries_past_waiting_cannot_be_changed_and_left_entries_are_never_called(): void
    {
        $this->leave($this->entry1)->assertOk();
        $this->undo($this->entry2)->assertNoContent();
        $entry4 = $this->checkIn($this->appointment2)->assertCreated()->json('data.id');

        // In queue order: entry 4 (09:20), then the walk-in (10:00); entry 1 (left) is skipped.
        Sanctum::actingAs($this->doctor->user);
        $this->callNext()->assertOk()->assertJsonPath('data.id', $entry4);
        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk();
        $this->callNext()->assertOk()->assertJsonPath('data.id', $this->walkIn);
        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk();
        $this->callNext()->assertUnprocessable();

        Sanctum::actingAs($this->receptionist);

        foreach ([$this->entry1, $entry4] as $final) {
            $this->assertNotChangeable($this->leave($final));
            $this->assertNotChangeable($this->undo($final));
        }

        // Number 5 is with the doctor.
        $number5 = $this->addWalkIn()->assertCreated()->assertJsonPath('data.queue_number', 5)->json('data.id');
        Sanctum::actingAs($this->doctor->user);
        $this->callNext()->assertOk()->assertJsonPath('data.id', $number5);
        Sanctum::actingAs($this->receptionist);
        $this->assertNotChangeable($this->leave($number5));
        $this->assertNotChangeable($this->undo($number5));
        $this->assertSame(QueueStatus::WithDoctor, QueueEntry::query()->findOrFail($number5)->status);

        // Number 6 is still waiting.
        $number6 = $this->addWalkIn()->assertCreated()->assertJsonPath('data.queue_number', 6)->json('data.id');
        $this->leave($number6)->assertOk()->assertJsonPath('data.status', 'left');
    }

    public function test_an_unknown_entry_is_not_found(): void
    {
        foreach ([$this->leave(999999), $this->undo(999999)] as $response) {
            $response->assertNotFound()->assertExactJson(['message' => __('queues::messages.entry_not_found')]);
        }
    }

    public function test_a_doctor_is_forbidden_and_nothing_changes(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->leave($this->entry1)->assertForbidden();
        $this->undo($this->entry2)->assertForbidden();

        $this->assertSame(QueueStatus::Waiting, QueueEntry::query()->findOrFail($this->entry1)->status);
        $this->assertSame(QueueStatus::Waiting, QueueEntry::query()->findOrFail($this->entry2)->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $this->appointment2->refresh()->status);
    }

    private function checkIn(Appointment $appointment): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$appointment->id}/check-in");
    }

    private function addWalkIn(): TestResponse
    {
        return $this->postJson('/api/v1/queue/walk-ins', [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $this->doctor->id,
        ]);
    }

    private function callNext(): TestResponse
    {
        return $this->postJson('/api/v1/doctors/me/queue/call-next');
    }

    private function leave(int $id): TestResponse
    {
        return $this->postJson("/api/v1/queue/{$id}/leave");
    }

    private function undo(int $id): TestResponse
    {
        return $this->deleteJson("/api/v1/queue/{$id}");
    }

    private function assertNotChangeable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.entry.0', __('queues::messages.not_changeable'));
    }
}
