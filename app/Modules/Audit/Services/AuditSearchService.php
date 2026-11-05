<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Arr;

/**
 * Searching the trail (FR-009, FR-010). Dates are clinic days, turned here into UTC instants, including
 * across daylight-saving changes (FR-018).
 */
class AuditSearchService
{
    public function __construct(
        private readonly AuditEntryRepository $entries,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{from?: string, to?: string, staff_id?: int, patient_id?: int, subject_type?: string, subject_id?: int, action?: string, outcome?: string}  $filters
     * @return CursorPaginator<int, AuditEntry>
     */
    public function search(array $filters, int $perPage): CursorPaginator
    {
        $page = $this->entries->search(
            Arr::except($filters, ['from', 'to']),
            isset($filters['from']) ? $this->startOfClinicDay($filters['from']) : null,
            isset($filters['to']) ? $this->startOfClinicDay($filters['to'], addDays: 1) : null,
            $perPage,
        );

        // Read first, then record, so that a search does not list itself (FR-011). Every page is recorded,
        // because a cursor could otherwise be used to skip the entry. If it cannot be saved, nothing is
        // returned (FR-007).
        $this->audit->record(AuditAction::AuditSearched, details: ['filters' => $filters]);

        return $page;
    }

    private function startOfClinicDay(string $date, int $addDays = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($date, ClinicClock::timezone())->addDays($addDays)->startOfDay()->utc();
    }
}
