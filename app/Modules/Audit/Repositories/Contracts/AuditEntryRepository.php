<?php

namespace App\Modules\Audit\Repositories\Contracts;

use App\Modules\Audit\Models\AuditEntry;

/**
 * The trail is append-only: this contract has no update or delete, and must never gain one (FR-014).
 */
interface AuditEntryRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): AuditEntry;
}
