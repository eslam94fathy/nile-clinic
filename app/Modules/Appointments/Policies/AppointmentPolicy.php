<?php

namespace App\Modules\Appointments\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Active administrators and receptionists list, view, book, and change appointments; active
 * doctors read only their own, through /doctors/me/appointments (research R8).
 *
 * Every ability is class-level (checked against Appointment::class, not a loaded record), so a
 * refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class AppointmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function view(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    /**
     * Reschedule, cancel, and mark as no-show.
     */
    public function update(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function viewOwn(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Doctor;
    }

    private function isActiveStaff(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [Role::Administrator, Role::Receptionist], true);
    }
}
