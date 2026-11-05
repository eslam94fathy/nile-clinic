<?php

namespace App\Modules\Encounters\Repositories;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;

class EloquentEncounterRepository implements EncounterRepository
{
    public function find(int $id): ?Encounter
    {
        return Encounter::query()->with(['doctor.user', 'patient', 'addenda'])->find($id);
    }

    public function lockById(int $id): ?Encounter
    {
        return Encounter::query()->lockForUpdate()->find($id);
    }

    public function create(array $attributes): Encounter
    {
        return Encounter::query()->create($attributes);
    }

    public function update(Encounter $encounter, array $attributes): Encounter
    {
        $encounter->update($attributes);

        return $encounter;
    }

    public function existsForVisit(int $queueEntryId): bool
    {
        return Encounter::query()->where('queue_entry_id', $queueEntryId)->exists();
    }
}
