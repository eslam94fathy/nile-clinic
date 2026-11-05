<?php

namespace App\Modules\Encounters\Repositories\Contracts;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EncounterRepository
{
    /**
     * With doctor and its account, patient, and addenda loaded.
     */
    public function find(int $id): ?Encounter;

    /**
     * The record locked `FOR UPDATE` until the caller's transaction ends, or null. No relations are
     * loaded.
     */
    public function lockById(int $id): ?Encounter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Encounter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Encounter $encounter, array $attributes): Encounter;

    /**
     * Whether the visit already has a record. A plain read: call only with the queue entry locked
     * (research R3).
     */
    public function existsForVisit(int $queueEntryId): bool;

    /**
     * The record's patient, or null if there is no such record. Selects no clinical column.
     */
    public function patientIdOfEncounter(int $id): ?int;

    /**
     * Whether the patient has a medical history to be refused. Selects no clinical column.
     */
    public function patientHasFinalizedRecord(int $patientId): bool;

    /**
     * Addenda are only ever added: no method changes or deletes one (FR-011).
     */
    public function addAddendum(Encounter $encounter, string $text): EncounterAddendum;

    /**
     * One page of the doctor's visits that are with the doctor or seen, by date, then queue order,
     * with the patient loaded and `encounter_id` and `encounter_status` selected (research R9). No
     * clinical column is selected, so a listing cannot return clinical content (FR-018).
     *
     * @param  array{from: string, to: string, patient_id?: int, record_status?: string}  $filters
     * @return LengthAwarePaginator<int, QueueEntry>
     */
    public function paginateVisits(int $doctorId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * One page of the patient's finalized records, newest first, optionally only one doctor's, with
     * the doctor, its account, and specialties loaded (research R5). No clinical column is selected,
     * so a listing cannot return clinical content (FR-018).
     *
     * @return LengthAwarePaginator<int, Encounter>
     */
    public function paginateHistory(int $patientId, ?int $onlyDoctorId, int $perPage): LengthAwarePaginator;
}
