<?php

namespace App\Modules\Audit\Repositories\Contracts;

use App\Modules\Audit\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * The trail is append-only: this contract has no update or delete, and must never gain one (FR-014).
 */
interface AuditEntryRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): AuditEntry;

    /**
     * Newest first, with each actor's id and name. `staff_id` matches what a staff member did and what
     * was done to their account.
     *
     * @param  array{staff_id?: int, patient_id?: int, subject_type?: string, subject_id?: int, action?: string, outcome?: string}  $filters
     * @return CursorPaginator<int, AuditEntry>
     */
    public function search(array $filters, ?CarbonImmutable $start, ?CarbonImmutable $end, int $perPage): CursorPaginator;
}
