<?php

namespace App\Modules\Encounters\Repositories;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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

    public function paginateVisits(int $doctorId, array $filters, int $perPage): LengthAwarePaginator
    {
        return QueueEntry::query()
            ->select('queue_entries.*', 'encounters.id as encounter_id', 'encounters.status as encounter_status')
            ->leftJoin('encounters', 'encounters.queue_entry_id', '=', 'queue_entries.id')
            ->where('queue_entries.doctor_id', $doctorId)
            ->whereBetween('queue_entries.date', [$filters['from'], $filters['to']])
            ->whereIn('queue_entries.status', [QueueStatus::WithDoctor, QueueStatus::Seen])
            ->when(isset($filters['patient_id']), fn (Builder $query) => $query->where('queue_entries.patient_id', $filters['patient_id']))
            ->when(isset($filters['record_status']), function (Builder $query) use ($filters) {
                $filters['record_status'] === 'none'
                    ? $query->whereNull('encounters.id')
                    : $query->where('encounters.status', $filters['record_status']);
            })
            ->with('patient')
            ->orderBy('queue_entries.date')
            ->orderBy('queue_entries.order_minute')
            ->orderBy('queue_entries.queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }
}
