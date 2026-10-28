<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Every active staff member may read a doctor's weekly schedule and slots; only active
 * administrators may set or clear it; only active doctors may read their own (research R8,
 * FR-006, FR-007, FR-012, FR-018).
 *
 * Every ability is class-level (checked against DoctorSchedule::class, not a loaded record), so a
 * refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class SchedulePolicy
{
    public function view(User $actor): bool
    {
        return $actor->is_active;
    }

    public function update(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function viewOwn(User $actor): bool
    {
        return $this->isActiveDoctor($actor);
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->isAdministrator();
    }

    private function isActiveDoctor(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Doctor;
    }
}
