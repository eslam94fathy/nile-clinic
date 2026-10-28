<?php

namespace App\Modules\Patients\Repositories;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;

class EloquentPatientRepository implements PatientRepository
{
    public function findById(int $id): ?Patient
    {
        return Patient::query()->find($id);
    }

    public function create(array $attributes): Patient
    {
        return Patient::query()->create($attributes);
    }
}
