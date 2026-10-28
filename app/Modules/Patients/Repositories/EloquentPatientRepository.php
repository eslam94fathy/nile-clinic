<?php

namespace App\Modules\Patients\Repositories;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use App\Support\ArabicName;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function update(Patient $patient, array $attributes): Patient
    {
        $patient->fill($attributes)->save();

        return $patient;
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Patient::query()
            ->when(isset($filters['name']), fn ($query) => $query->where(
                'name_search',
                'like',
                '%'.$this->escapeLike(ArabicName::normalize($filters['name'])).'%',
            ))
            ->when(isset($filters['phone']), fn ($query) => $query->where('phone', $filters['phone']))
            ->when(isset($filters['national_id']), fn ($query) => $query->where('national_id', $filters['national_id']))
            ->when(isset($filters['id']), fn ($query) => $query->whereKey($filters['id']))
            ->orderBy('name_search')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Make %, _, and the escape character itself match literally in a LIKE pattern.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
