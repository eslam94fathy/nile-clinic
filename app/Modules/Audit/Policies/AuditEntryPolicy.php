<?php

namespace App\Modules\Audit\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Who may search the trail: active administrators only (FR-013). There are deliberately no abilities
 * to change or delete an entry (FR-014).
 */
class AuditEntryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Administrator;
    }
}
