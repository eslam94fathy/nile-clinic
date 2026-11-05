<?php

namespace App\Modules\Encounters\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Exceptions\EncounterNotFoundException;
use App\Modules\Encounters\Exceptions\HistoryNotFoundException;
use App\Modules\Encounters\Exceptions\VisitNotFoundException;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        private readonly AuditTrail $audit,
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

            $encounter = $this->encounters->create([
                'queue_entry_id' => $entry->id,
                'doctor_id' => $entry->doctor_id,
                'patient_id' => $entry->patient_id,
                'date' => $entry->date->toDateString(),
                'status' => EncounterStatus::Draft,
            ]);

            $this->audit->recordChanges(AuditAction::EncounterStarted, $encounter, AuditSubject::Encounter, patientId: $encounter->patient_id);

            return $encounter;
        }, attempts: 3);

        return $this->find($encounter->id);
    }

    /**
     * The doctor's visits with their record status (FR-016).
     *
     * @param  array{from: string, to: string, patient_id?: int, record_status?: string}  $filters
     * @return LengthAwarePaginator<int, QueueEntry>
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     */
    public function listVisits(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->encounters->paginateVisits($this->doctors->findOwn($user)->id, $filters, $perPage);
    }

    /**
     * A patient's finalized records the doctor may read (FR-017, research R5). On the doctor's list
     * today: all of them. Otherwise only the doctor's own, and none is the same 404 as an unknown
     * patient, so no separate patient lookup exists (FR-013).
     *
     * @return LengthAwarePaginator<int, Encounter>
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     * @throws HistoryNotFoundException when nothing is readable
     */
    public function history(User $user, int $patientId, int $perPage): LengthAwarePaginator
    {
        $doctorId = $this->doctors->findOwn($user)->id;
        $onList = $this->queue->patientOnList($doctorId, $patientId, ClinicClock::today());

        $page = $this->encounters->paginateHistory($patientId, $onList ? null : $doctorId, $perPage);

        if (! $onList && $page->total() === 0) {
            throw new HistoryNotFoundException;
        }

        // If the entry cannot be saved, the history is not returned (FR-007).
        $this->audit->record(AuditAction::EncounterHistoryViewed, AuditSubject::Patient, $patientId, $patientId);

        return $page;
    }

    /**
     * A record a doctor is about to read, leaving an entry. If the entry cannot be saved, the record is
     * not returned (FR-004, FR-007). `find` stays free of this, because the write paths call it.
     */
    public function open(Encounter $encounter): Encounter
    {
        $this->audit->record(AuditAction::EncounterOpened, AuditSubject::Encounter, $encounter->id, $encounter->patient_id);

        return $encounter;
    }

    /**
     * A refused attempt on a record that exists (FR-005). An unknown id is not an attempt on anything.
     */
    public function recordRefusedOpening(int $id): void
    {
        $patientId = $this->encounters->patientIdOfEncounter($id);

        if ($patientId !== null) {
            $this->audit->recordRefusal(AuditAction::EncounterOpened, AuditSubject::Encounter, $id, $patientId);
        }
    }

    /**
     * A refused attempt on a medical history that exists (FR-005).
     */
    public function recordRefusedHistory(int $patientId): void
    {
        if ($this->encounters->patientHasFinalizedRecord($patientId)) {
            $this->audit->recordRefusal(AuditAction::EncounterHistoryViewed, AuditSubject::Patient, $patientId, $patientId);
        }
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
            $encounter = $this->lockDraft($id);

            $this->encounters->update($encounter, $content);

            $this->audit->recordChanges(AuditAction::EncounterDraftSaved, $encounter, AuditSubject::Encounter, patientId: $encounter->patient_id);
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

            $this->audit->recordChanges(AuditAction::EncounterFinalized, $encounter, AuditSubject::Encounter, patientId: $encounter->patient_id);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * Correct a finalized record (FR-011). A plain insert: finalized never reverts, so no lock is
     * needed, and simultaneous addenda are all kept (research R3).
     *
     * @throws ValidationException when the record is a draft
     */
    public function addAddendum(Encounter $encounter, string $text): EncounterAddendum
    {
        if ($encounter->status !== EncounterStatus::Finalized) {
            throw $this->refusal('encounter', 'not_finalized');
        }

        return DB::transaction(function () use ($encounter, $text) {
            $addendum = $this->encounters->addAddendum($encounter, $text);

            $this->audit->record(AuditAction::EncounterAddendumAdded, AuditSubject::Encounter, $encounter->id, $encounter->patient_id, ['fields' => ['text']]);

            return $addendum;
        });
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
