<?php

namespace App\Modules\Staff\Policies;

use App\Modules\Staff\Models\User;

/**
 * Staff management is for active administrators only (FR-014).
 *
 * Every ability is class-level (checked against User::class, not a loaded account), so a
 * non-administrator is refused before any lookup and cannot probe which ids exist.
 */
class StaffMemberPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function view(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
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

    public function setPassword(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->isAdministrator();
    }
}
