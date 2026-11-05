<?php

namespace App\Modules\Audit\Repositories;

use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;

class EloquentAuditEntryRepository implements AuditEntryRepository
{
    public function append(array $attributes): AuditEntry
    {
        return AuditEntry::query()->create($attributes);
    }

    public function search(array $filters, ?CarbonImmutable $start, ?CarbonImmutable $end, int $perPage): CursorPaginator
    {
        return AuditEntry::query()
            ->with('actor:id,name')
            ->when(isset($filters['staff_id']), fn ($query) => $query->where(fn ($query) => $query
                ->where('actor_id', $filters['staff_id'])
                ->orWhere(fn ($query) => $query
                    ->where('subject_type', AuditSubject::StaffMember)
                    ->where('subject_id', $filters['staff_id']))))
            ->when(isset($filters['patient_id']), fn ($query) => $query->where('patient_id', $filters['patient_id']))
            ->when(isset($filters['subject_type']), fn ($query) => $query
                ->where('subject_type', $filters['subject_type'])
                ->where('subject_id', $filters['subject_id']))
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['outcome']), fn ($query) => $query->where('outcome', $filters['outcome']))
            ->when($start !== null, fn ($query) => $query->where('created_at', '>=', $start))
            ->when($end !== null, fn ($query) => $query->where('created_at', '<', $end))
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }
}
