<?php

namespace App\Modules\Doctors\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Every active staff member may list and view doctors; only active administrators may create and
 * update profiles, including a doctor's own (FR-010, clarification Q3); only active doctors may
 * fetch their own profile (FR-017).
 *
 * Every ability is class-level (checked against Doctor::class, not a loaded record), so a refused
 * actor gets 403 before any lookup and cannot probe which ids exist.
 */
class DoctorPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active;
    }

    public function view(User $actor): bool
    {
        return $actor->is_active;
    }

    public function create(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function update(User $actor): bool
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
