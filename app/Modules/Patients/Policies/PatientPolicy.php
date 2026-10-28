<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Patient records are for active administrators and receptionists only (FR-014, FR-015).
 *
 * Doctors are refused every ability in this feature (clarification Q1). Every ability is
 * class-level (checked against Patient::class, not a loaded record), so a refused actor gets
 * 403 before any lookup and cannot probe which patient numbers exist.
 */
class PatientPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->mayWorkWithPatients($actor);
    }

    public function view(User $actor): bool
    {
        return $this->mayWorkWithPatients($actor);
    }

    public function create(User $actor): bool
    {
        return $this->mayWorkWithPatients($actor);
    }

    public function update(User $actor): bool
    {
        return $this->mayWorkWithPatients($actor);
    }

    private function mayWorkWithPatients(User $actor): bool
    {
        return $actor->is_active
            && in_array($actor->role, [Role::Administrator, Role::Receptionist], true);
    }
}
