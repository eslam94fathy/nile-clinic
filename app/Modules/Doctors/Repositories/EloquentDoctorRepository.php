<?php

namespace App\Modules\Doctors\Repositories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Staff\Enums\Role;
use App\Support\ArabicName;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Doctor::query()
            ->select('doctors.*')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->when(isset($filters['name']), fn ($query) => $this->whereNameContains($query, $filters['name']))
            ->when(isset($filters['specialty_id']), fn ($query) => $query->whereHas(
                'specialties',
                fn ($specialties) => $specialties->whereKey($filters['specialty_id']),
            ))
            ->when(isset($filters['is_active']), fn ($query) => $filters['is_active']
                ? $query->where('users.is_active', true)->where('users.role', Role::Doctor->value)
                : $query->where(fn ($inactive) => $inactive
                    ->where('users.is_active', false)
                    ->orWhere('users.role', '!=', Role::Doctor->value)))
            ->with(['user', 'specialties'])
            ->orderBy('users.name')
            ->orderBy('doctors.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The account name has no normalized copy, so it is normalized in SQL the same way
     * ArabicName::normalize() does it in PHP: lower-cased, whitespace collapsed and trimmed, and
     * each Arabic letter variant replaced (research R4, approved D2).
     *
     * @param  Builder<Doctor>  $query
     */
    private function whereNameContains(Builder $query, string $name): void
    {
        $expression = "TRIM(REGEXP_REPLACE(LOWER(users.name), '[[:space:]]+', ' '))";
        $bindings = [];

        foreach (ArabicName::VARIANTS as $from => $to) {
            $expression = "REPLACE({$expression}, ?, ?)";
            array_push($bindings, $from, $to);
        }

        $query->whereRaw("{$expression} LIKE ?", [
            ...$bindings,
            '%'.$this->escapeLike(ArabicName::normalize($name)).'%',
        ]);
    }

    /**
     * Make %, _, and the escape character itself match literally in a LIKE pattern.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
