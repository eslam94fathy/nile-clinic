<?php

namespace App\Modules\Patients\Repositories\Contracts;

use App\Modules\Patients\Models\Patient;

interface PatientRepository
{
    public function findById(int $id): ?Patient;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Patient;
}
