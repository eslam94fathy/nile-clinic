<?php

namespace App\Modules\Audit\Repositories;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;

class EloquentAuditEntryRepository implements AuditEntryRepository
{
    public function append(array $attributes): AuditEntry
    {
        return AuditEntry::query()->create($attributes);
    }
}
