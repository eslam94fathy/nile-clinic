<?php

namespace App\Modules\Queues\Repositories\Contracts;

use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface QueueRepository
{
    /**
     * The doctor's queue day, created if needed, locked `FOR UPDATE` until the caller's transaction
     * ends. Serializes every change to one doctor's queue on one day (research R3).
     */
    public function lockDay(int $doctorId, string $date): QueueDay;

    /**
     * Take the next queue number of this locked day. Numbers are never given twice, even after an
     * undo removes an entry (FR-003).
     */
    public function takeNumber(QueueDay $day): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): QueueEntry;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(QueueEntry $entry, array $attributes): QueueEntry;

    /**
     * With patient, doctor and its account, and appointment loaded.
     */
    public function find(int $id): ?QueueEntry;

    /**
     * Every entry of the doctor that date in queue order (order time, then queue number; research
     * R4), with patient, doctor and its account, and appointment loaded.
     *
     * @return Collection<int, QueueEntry>
     */
    public function forDoctorDay(int $doctorId, string $date): Collection;

    /**
     * One page of that date's entries, ordered by doctor id, then queue order, with patient, doctor
     * and its account, and appointment loaded (research R8). Optional status filter.
     *
     * @return LengthAwarePaginator<int, QueueEntry>
     */
    public function paginateDay(string $date, ?string $status, int $perPage): LengthAwarePaginator;

    /**
     * The doctor's entry that is with the doctor that date, locked. Call only with the queue day
     * locked.
     */
    public function withDoctor(int $doctorId, string $date): ?QueueEntry;

    /**
     * The doctor's waiting entries that date in queue order, locked. Call only with the queue day
     * locked.
     *
     * @return Collection<int, QueueEntry>
     */
    public function waitingInOrder(int $doctorId, string $date): Collection;

    /**
     * Whether the patient is with any doctor that date. A plain read: call only with the queue day
     * and the patient row locked; the unique index on (patient, date, with doctor) is the backstop
     * (research R5).
     */
    public function patientWithDoctor(int $patientId, string $date): bool;

    /**
     * Whether the patient has a waiting or with-doctor entry with this doctor that date. A plain
     * read: call only with the queue day locked, before any other plain read in the transaction.
     */
    public function patientActive(int $doctorId, int $patientId, string $date): bool;

    /**
     * The entry locked `FOR UPDATE` until the caller's transaction ends, or null. No relations are
     * loaded.
     */
    public function lockById(int $id): ?QueueEntry;

    public function delete(QueueEntry $entry): void;
}
