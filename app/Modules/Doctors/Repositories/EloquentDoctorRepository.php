<?php

namespace App\Modules\Doctors\Repositories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;

class EloquentDoctorRepository implements DoctorRepository
{
    public function findById(int $id): ?Doctor
    {
        return Doctor::query()->with(['user', 'specialties'])->find($id);
    }

    public function findByUserId(int $userId): ?Doctor
    {
        return Doctor::query()->with(['user', 'specialties'])->where('user_id', $userId)->first();
    }

    public function create(array $attributes): Doctor
    {
        return Doctor::query()->create($attributes);
    }

    public function update(Doctor $doctor, array $attributes): Doctor
    {
        $doctor->fill($attributes)->save();

        return $doctor;
    }

    public function syncSpecialties(Doctor $doctor, array $specialtyIds): void
    {
        $doctor->specialties()->sync($specialtyIds);
    }

    public function registrationNumberTaken(string $number): bool
    {
        return Doctor::query()->where('registration_number', $number)->exists();
    }
}
