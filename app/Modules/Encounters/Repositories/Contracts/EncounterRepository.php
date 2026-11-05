<?php

namespace App\Modules\Encounters\Repositories\Contracts;

use App\Modules\Encounters\Models\Encounter;

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
}
