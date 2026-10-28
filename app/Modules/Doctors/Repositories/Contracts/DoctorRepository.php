<?php

namespace App\Modules\Doctors\Repositories\Contracts;

use App\Modules\Doctors\Models\Doctor;

interface DoctorRepository
{
    /**
     * With the account and specialties loaded.
     */
    public function findById(int $id): ?Doctor;

    /**
     * The profile linked to this staff account, with the account and specialties loaded.
     */
    public function findByUserId(int $userId): ?Doctor;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Doctor;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Doctor $doctor, array $attributes): Doctor;

    /**
     * Make these the doctor's only specialties.
     *
     * @param  list<int>  $specialtyIds
     */
    public function syncSpecialties(Doctor $doctor, array $specialtyIds): void;

    public function registrationNumberTaken(string $number): bool;
}
