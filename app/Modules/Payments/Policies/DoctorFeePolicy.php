<?php

namespace App\Modules\Payments\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Administrators and receptionists read fees; only administrators set them; doctors do neither
 * (research R9). Class-level, so a refused actor gets 403 before any lookup.
 */
class DoctorFeePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [Role::Administrator, Role::Receptionist], true);
    }

    public function set(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Administrator;
    }
}
