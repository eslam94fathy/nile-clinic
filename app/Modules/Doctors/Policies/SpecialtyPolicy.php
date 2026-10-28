<?php

namespace App\Modules\Doctors\Policies;

use App\Modules\Staff\Models\User;

/**
 * Every active staff member may read the specialty catalog; only active administrators may change
 * it (FR-003, FR-004).
 *
 * Every ability is class-level (checked against Specialty::class, not a loaded record), so a
 * refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class SpecialtyPolicy
{
    public function viewAny(User $actor): bool
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

    public function deactivate(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function reactivate(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->isAdministrator();
    }
}
