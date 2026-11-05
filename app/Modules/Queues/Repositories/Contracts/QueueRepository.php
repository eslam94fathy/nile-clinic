<?php

namespace App\Modules\Queues\Repositories\Contracts;

use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;

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
}
