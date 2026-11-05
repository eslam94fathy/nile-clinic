<?php

namespace App\Modules\Payments\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Administrators and receptionists charge visits, take payments, and cancel charges with no money
 * held; only administrators discount and refund; doctors do none of it (research R9). Every ability
 * is class-level, so a refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class ChargePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function pay(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function cancel(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function discount(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function refund(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    private function isActiveStaff(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [Role::Administrator, Role::Receptionist], true);
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Administrator;
    }
}
