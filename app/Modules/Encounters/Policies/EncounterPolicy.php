<?php

namespace App\Modules\Encounters\Policies;

use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/**
 * Who uses medical records and which ones they read: the approved access rule, in one place
 * (FR-012–FR-014, research R4).
 *
 * `access` is class-level and checked first, so administrators and receptionists get 403 before any
 * lookup. `view` and `write` are record-level because they depend on the record, not only the role.
 * A false `view` becomes the same 404 as an unknown id, so a doctor cannot learn whether a record
 * exists.
 */
class EncounterPolicy
{
    public function __construct(
        private readonly DoctorRepository $doctors,
        private readonly QueueRepository $queue,
    ) {}

    public function access(User $actor): bool
    {
        return $actor->is_active && $actor->role === Role::Doctor;
    }

    /**
     * Own records at any time; another doctor's only when finalized and while the patient is on this
     * doctor's list today as waiting, with the doctor, or seen.
     */
    public function view(User $actor, Encounter $encounter): bool
    {
        if (! $this->access($actor)) {
            return false;
        }

        $doctor = $this->doctors->findByUserId($actor->id);

        if ($doctor === null) {
            return false;
        }

        if ($encounter->doctor_id === $doctor->id) {
            return true;
        }

        return $encounter->status === EncounterStatus::Finalized
            && $this->queue->patientOnList($doctor->id, $encounter->patient_id, ClinicClock::today());
    }

    /**
     * Save, finalize, and add addenda: the author only.
     */
    public function write(User $actor, Encounter $encounter): bool
    {
        return $this->access($actor) && $encounter->doctor->user_id === $actor->id;
    }
}
