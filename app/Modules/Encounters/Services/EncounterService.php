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
     * Lock a complete draft (FR-007). What it holds at this moment is its final content (FR-008).
     *
     * @throws EncounterNotFoundException
     * @throws ValidationException when already finalized, or naming each missing field
     */
    public function finalize(int $id): Encounter
    {
        DB::transaction(function () use ($id) {
            $encounter = $this->lockDraft($id);
            $missing = $this->missingToFinalize($encounter);

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $this->encounters->update($encounter, [
                'status' => EncounterStatus::Finalized,
                'finalized_at' => now(),
            ]);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * Field => message for what a finalized record must hold (research R8).
     *
     * @return array<string, string>
     */
    private function missingToFinalize(Encounter $encounter): array
    {
        $missing = [];

        foreach (['chief_complaint', 'diagnosis'] as $field) {
            if (blank($encounter->{$field})) {
                $missing[$field] = $this->requiredToFinalize($field);
            }
        }

        foreach ($encounter->prescriptions ?? [] as $index => $line) {
            foreach (['dosage', 'frequency', 'duration'] as $field) {
                if (blank($line[$field] ?? null)) {
                    $missing["prescriptions.{$index}.{$field}"] = $this->requiredToFinalize($field);
                }
            }
        }

        return $missing;
    }

    private function requiredToFinalize(string $field): string
    {
        return __('encounters::messages.required_to_finalize', ['attribute' => __("encounters::attributes.{$field}")]);
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
