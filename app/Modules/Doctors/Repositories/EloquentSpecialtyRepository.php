<?php

namespace App\Modules\Doctors\Repositories;

use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Repositories\Contracts\SpecialtyRepository;
use App\Support\ArabicName;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EloquentSpecialtyRepository implements SpecialtyRepository
{
    public function all(?bool $isActive): Collection
    {
        return Specialty::query()
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderBy('name_en')
            ->orderBy('id')
            ->get();
    }

    public function findById(int $id): ?Specialty
    {
        return Specialty::query()->find($id);
    }

    public function lockByIds(array $ids): Collection
    {
        return Specialty::query()->whereKey($ids)->sharedLock()->get();
    }

    public function create(array $attributes): Specialty
    {
        return Specialty::query()->create($attributes);
    }

    public function update(Specialty $specialty, array $attributes): Specialty
    {
        $specialty->fill($attributes)->save();

        return $specialty;
    }

    public function nameTaken(string $column, string $name): bool
    {
        if (! in_array($column, ['name_en', 'name_ar'], true)) {
            throw new InvalidArgumentException("Unknown specialty name column [{$column}].");
        }

        return Specialty::query()->where($column, ArabicName::clean($name))->exists();
    }
}
