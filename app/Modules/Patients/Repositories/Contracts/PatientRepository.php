<?php

namespace App\Modules\Patients\Repositories\Contracts;

use App\Modules\Patients\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PatientRepository
{
    public function findById(int $id): ?Patient;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Patient;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Patient $patient, array $attributes): Patient;

    /**
     * One page of patients ordered by normalized name, then id (research R8). Optional filter
     * keys, combined with AND; absent keys are not applied:
     * - name: the normalized name contains this text (Arabic letter variants ignored, R3)
     * - phone, national_id, id: exact match
     *
     * @param  array{name?: string, phone?: string, national_id?: string, id?: int}  $filters
     * @return LengthAwarePaginator<int, Patient>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
