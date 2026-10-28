<?php

namespace App\Modules\Doctors\Repositories\Contracts;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    /**
     * One page of doctors ordered by account name, then id, with account and specialties loaded
     * (research R10). Optional filter keys, combined with AND; absent keys are not applied:
     * - name: the normalized account name contains this text (Arabic letter variants ignored, R4)
     * - specialty_id: the doctor has this specialty
     * - is_active: the account is (true) or is not (false) active with the doctor role (R2)
     *
     * @param  array{name?: string, specialty_id?: int, is_active?: bool}  $filters
     * @return LengthAwarePaginator<int, Doctor>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
