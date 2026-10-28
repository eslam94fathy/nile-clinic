<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Every active staff member may list a doctor's time off; only active administrators may record
 * or remove it; only active doctors may list their own (research R8, FR-013, FR-016–FR-018).
 *
 * Every ability is class-level (checked against DoctorTimeOff::class, not a loaded record), so a
 * refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class TimeOffPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active;
    }

    public function create(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function delete(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function viewOwn(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Doctor;
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->isAdministrator();
    }
}
