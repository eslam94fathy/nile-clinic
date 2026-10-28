<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Doctors\Repositories\Contracts\SpecialtyRepository;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating, updating, and finding doctor profiles (US2–US3, research R2, R6, R7).
 */
class DoctorService
{
    public function __construct(
        private readonly DoctorRepository $doctors,
        private readonly SpecialtyRepository $specialties,
        private readonly StaffMemberRepository $staffMembers,
    ) {}

    /**
     * @throws DoctorNotFoundException
     */
    public function find(int $id): Doctor
    {
        return $this->doctors->findById($id) ?? throw new DoctorNotFoundException;
    }

    /**
     * Create the profile and its specialties together, for an active doctor-role account (FR-006).
     *
     * @param  array{user_id: int, title: string, registration_number?: string|null, specialty_ids: list<int>}  $attributes
     */
    public function create(array $attributes): Doctor
    {
        $registrationNumber = $attributes['registration_number'] ?? null;

        $doctor = $this->guardUniqueness($attributes['user_id'], $registrationNumber, fn () => DB::transaction(function () use ($attributes, $registrationNumber) {
            $account = $this->staffMembers->findById($attributes['user_id']);

            if ($account === null || ! $account->is_active || $account->role !== Role::Doctor) {
                throw ValidationException::withMessages([
                    'user_id' => __('doctors::messages.not_an_active_doctor'),
                ]);
            }

            $this->ensureAssignable($attributes['specialty_ids'], []);

            $doctor = $this->doctors->create([
                'user_id' => $attributes['user_id'],
                'title' => $attributes['title'],
                'registration_number' => $registrationNumber,
            ]);
            $this->doctors->syncSpecialties($doctor, $attributes['specialty_ids']);

            return $doctor;
        }));

        return $this->find($doctor->id);
    }

    /**
     * Change only the fields sent; specialty_ids replaces the whole set. The linked account never
     * changes (FR-005).
     *
     * @param  array{title?: string, registration_number?: string|null, specialty_ids?: list<int>}  $attributes
     */
    public function update(Doctor $doctor, array $attributes): Doctor
    {
        $this->guardUniqueness(null, $attributes['registration_number'] ?? null, fn () => DB::transaction(function () use ($doctor, $attributes) {
            if (array_key_exists('specialty_ids', $attributes)) {
                $this->ensureAssignable($attributes['specialty_ids'], $doctor->specialties->modelKeys());
                $this->doctors->syncSpecialties($doctor, $attributes['specialty_ids']);
            }

            $fields = array_intersect_key($attributes, array_flip(['title', 'registration_number']));

            if ($fields !== []) {
                $this->doctors->update($doctor, $fields);
            }
        }));

        return $this->find($doctor->id);
    }

    /**
     * Each specialty must be active, or already assigned to this doctor (FR-008). The specialties
     * stay share-locked until the transaction ends, so one cannot be deactivated mid-check (R6).
     *
     * @param  list<int>  $ids
     * @param  list<int>  $currentIds
     */
    private function ensureAssignable(array $ids, array $currentIds): void
    {
        foreach ($this->specialties->lockByIds($ids) as $specialty) {
            if (! $specialty->is_active && ! in_array($specialty->id, $currentIds, true)) {
                throw ValidationException::withMessages([
                    'specialty_ids' => __('doctors::messages.specialty_inactive'),
                ]);
            }
        }
    }

    /**
     * Two simultaneous writes for the same account or registration number both pass validation;
     * the unique index rejects the second, which is reported like the normal validation error on
     * the field that collided (R7).
     *
     * @template T
     *
     * @param  int|null  $userId  the account being linked (create only)
     * @param  string|null  $registrationNumber  the number being written, if any
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardUniqueness(?int $userId, ?string $registrationNumber, Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = match (true) {
                $userId !== null && $this->doctors->findByUserId($userId) !== null => 'user_id',
                $registrationNumber !== null && $this->doctors->registrationNumberTaken($registrationNumber) => 'registration_number',
                default => throw $e,
            };

            throw ValidationException::withMessages([
                $field => __('validation.unique', ['attribute' => __("doctors::attributes.{$field}")]),
            ]);
        }
    }
}
