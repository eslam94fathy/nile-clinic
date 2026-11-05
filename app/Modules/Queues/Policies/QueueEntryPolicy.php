<?php

namespace App\Modules\Queues\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Active administrators and receptionists see the lists, check patients in, add walk-ins, mark
 * patients as left, and undo check-ins; active doctors see and move only their own list, through
 * /doctors/me/queue (research R7).
 *
 * Every ability is class-level (checked against QueueEntry::class, not a loaded record), so a
 * refused actor gets 403 before any lookup and cannot probe which ids exist.
 */
class QueueEntryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    /**
     * Check in and add a walk-in.
     */
    public function create(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    /**
     * Mark as left and undo a check-in.
     */
    public function update(User $actor): bool
    {
        return $this->isActiveStaff($actor);
    }

    public function viewOwn(User $actor): bool
    {
        return $this->isActiveDoctor($actor);
    }

    /**
     * Call next and finish.
     */
    public function updateOwn(User $actor): bool
    {
        return $this->isActiveDoctor($actor);
    }

    private function isActiveStaff(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [Role::Administrator, Role::Receptionist], true);
    }

    private function isActiveDoctor(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Doctor;
    }
}
