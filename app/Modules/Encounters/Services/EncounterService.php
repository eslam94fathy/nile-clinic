<?php

namespace App\Modules\Encounters\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Exceptions\EncounterNotFoundException;
use App\Modules\Encounters\Exceptions\VisitNotFoundException;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Medical records: starting, saving, finalizing, correcting, and finding them (FR-001–FR-018).
 * Authorization is EncounterPolicy's. Every change decides from a locked row, so no decision is made
 * from a plain read (research R3). Clinical text is never logged or placed in a message.
 */
class EncounterService
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly QueueRepository $queue,
        private readonly DoctorService $doctors,
    ) {}

    /**
     * Start the record of one of the doctor's visits. The doctor is read before the transaction, so
     * the locked entry is the first read inside it (research R3).
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     * @throws VisitNotFoundException unknown or another doctor's visit
     * @throws ValidationException the visit has not started, or already has a record
     */
    public function start(User $user, int $visitId): Encounter
    {
        $doctorId = $this->doctors->findOwn($user)->id;

        $encounter = DB::transaction(function () use ($doctorId, $visitId) {
            $entry = $this->queue->lockById($visitId);

            if ($entry === null || $entry->doctor_id !== $doctorId) {
                throw new VisitNotFoundException;
            }

            if (! in_array($entry->status, [QueueStatus::WithDoctor, QueueStatus::Seen], true)) {
                throw $this->refusal('visit', 'visit_not_started');
            }

            if ($this->encounters->existsForVisit($entry->id)) {
                throw $this->refusal('visit', 'already_started');
            }

            return $this->encounters->create([
                'queue_entry_id' => $entry->id,
                'doctor_id' => $entry->doctor_id,
                'patient_id' => $entry->patient_id,
                'date' => $entry->date->toDateString(),
                'status' => EncounterStatus::Draft,
            ]);
        }, attempts: 3);

        return $this->find($encounter->id);
    }

    /**
     * @throws EncounterNotFoundException
     */
    public function find(int $id): Encounter
    {
        return $this->encounters->find($id) ?? throw new EncounterNotFoundException;
    }

    /**
     * Replace all of a draft's content (FR-006).
     *
     * @param  array<string, mixed>  $content
     *
     * @throws EncounterNotFoundException
     * @throws ValidationException when the record is finalized
     */
    public function save(int $id, array $content): Encounter
    {
        DB::transaction(function () use ($id, $content) {
            $this->encounters->update($this->lockDraft($id), $content);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * @throws EncounterNotFoundException
     * @throws ValidationException when the record is finalized
     */
    private function lockDraft(int $id): Encounter
    {
        $encounter = $this->encounters->lockById($id) ?? throw new EncounterNotFoundException;

        if ($encounter->status !== EncounterStatus::Draft) {
            throw $this->refusal('encounter', 'not_editable');
        }

        return $encounter;
    }

    private function refusal(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => __("encounters::messages.{$message}")]);
    }
}
