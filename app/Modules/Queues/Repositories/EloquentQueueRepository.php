<?php

namespace App\Modules\Queues\Repositories;

use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;

class EloquentQueueRepository implements QueueRepository
{
    public function lockDay(int $doctorId, string $date): QueueDay
    {
        $now = now();

        QueueDay::query()->insertOrIgnore([
            'doctor_id' => $doctorId,
            'date' => $date,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return QueueDay::query()->where('doctor_id', $doctorId)->where('date', $date)->lockForUpdate()->firstOrFail();
    }

    public function takeNumber(QueueDay $day): int
    {
        $day->last_number++;
        $day->save();

        return $day->last_number;
    }

    public function create(array $attributes): QueueEntry
    {
        return QueueEntry::query()->create($attributes);
    }

    public function update(QueueEntry $entry, array $attributes): QueueEntry
    {
        $entry->fill($attributes)->save();

        return $entry;
    }

    public function find(int $id): ?QueueEntry
    {
        return QueueEntry::query()->with(['patient', 'doctor.user', 'appointment'])->find($id);
    }
}
