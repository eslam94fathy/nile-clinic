<?php

namespace App\Modules\Doctors\Repositories\Contracts;

use App\Modules\Doctors\Models\Specialty;
use Illuminate\Support\Collection;

interface SpecialtyRepository
{
    /**
     * The whole catalog ordered by English name, then id (research R5); null lists every status.
     *
     * @return Collection<int, Specialty>
     */
    public function all(?bool $isActive): Collection;

    public function findById(int $id): ?Specialty;

    /**
     * Load these specialties with a shared lock, so none can be deactivated until the caller's
     * transaction ends (research R6).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Specialty>
     */
    public function lockByIds(array $ids): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Specialty;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Specialty $specialty, array $attributes): Specialty;

    /**
     * Whether a specialty already uses this name in the given column (name_en or name_ar).
     */
    public function nameTaken(string $column, string $name): bool;
}
