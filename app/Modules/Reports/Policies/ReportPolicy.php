<?php

namespace App\Modules\Reports\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Reports are for active administrators only (FR-001). The check depends only on the actor, so a refused
 * actor gets 403 before validation or any lookup, and cannot probe which ids exist (research R9).
 */
class ReportPolicy
{
    public function view(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Administrator;
    }
}
