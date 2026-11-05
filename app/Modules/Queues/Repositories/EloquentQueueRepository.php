<?php

namespace App\Modules\Queues\Repositories;

use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentQueueRepository implements QueueRepository
{
    private const RELATIONS = ['patient', 'doctor.user', 'appointment'];

    public function lockDay(int $doctorId, string $date): QueueDay
    {
        $now = now();

        // Not insertOrIgnore: on a duplicate it keeps a shared lock, so simultaneous first changes
        // of a day all wait to upgrade to FOR UPDATE and deadlock. A no-op ON DUPLICATE KEY UPDATE
        // takes the exclusive lock at once (research R3).
        QueueDay::query()->upsert([[
            'doctor_id' => $doctorId,
            'date' => $date,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['doctor_id', 'date'], ['doctor_id']);

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
        return QueueEntry::query()->with(self::RELATIONS)->find($id);
    }

    public function forDoctorDay(int $doctorId, string $date): Collection
    {
        return QueueEntry::query()
            ->with(self::RELATIONS)
            ->where('doctor_id', $doctorId)
            ->where('date', $date)
            ->orderBy('order_minute')
            ->orderBy('queue_number')
            ->get();
    }

    public function paginateDay(string $date, ?string $status, int $perPage): LengthAwarePaginator
    {
        return QueueEntry::query()
            ->with(self::RELATIONS)
            ->where('date', $date)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('doctor_id')
            ->orderBy('order_minute')
            ->orderBy('queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function withDoctor(int $doctorId, string $date): ?QueueEntry
    {
        return QueueEntry::query()
            ->where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('status', QueueStatus::WithDoctor)
            ->lockForUpdate()
            ->first();
    }

    public function waitingInOrder(int $doctorId, string $date): Collection
    {
        return QueueEntry::query()
            ->where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('status', QueueStatus::Waiting)
            ->orderBy('order_minute')
            ->orderBy('queue_number')
            ->lockForUpdate()
            ->get();
    }

    public function patientWithDoctor(int $patientId, string $date): bool
    {
        return QueueEntry::query()
            ->where('patient_id', $patientId)
            ->where('date', $date)
            ->where('status', QueueStatus::WithDoctor)
            ->exists();
    }
}
