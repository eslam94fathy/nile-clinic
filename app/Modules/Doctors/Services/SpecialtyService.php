<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Doctors\Exceptions\SpecialtyNotFoundException;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Repositories\Contracts\SpecialtyRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Maintaining the specialty catalog (US1, research R5).
 */
class SpecialtyService
{
    public function __construct(
        private readonly SpecialtyRepository $specialties,
    ) {}

    /**
     * @return Collection<int, Specialty>
     */
    public function list(?bool $isActive): Collection
    {
        return $this->specialties->all($isActive);
    }

    /**
     * @throws SpecialtyNotFoundException
     */
    public function find(int $id): Specialty
    {
        return $this->specialties->findById($id) ?? throw new SpecialtyNotFoundException;
    }

    /**
     * @param  array{name_en: string, name_ar: string}  $attributes
     */
    public function create(array $attributes): Specialty
    {
        return $this->guardUniqueNames($attributes, fn () => $this->specialties->create([
            ...$attributes,
            'is_active' => true,
        ]));
    }

    /**
     * @param  array{name_en?: string, name_ar?: string}  $attributes
     */
    public function rename(Specialty $specialty, array $attributes): Specialty
    {
        return $this->guardUniqueNames($attributes, fn () => $this->specialties->update($specialty, $attributes));
    }

    /**
     * Stop new assignments; doctors already assigned keep it (FR-008). Idempotent.
     */
    public function deactivate(Specialty $specialty): Specialty
    {
        return $specialty->is_active ? $this->specialties->update($specialty, ['is_active' => false]) : $specialty;
    }

    /**
     * Idempotent.
     */
    public function reactivate(Specialty $specialty): Specialty
    {
        return $specialty->is_active ? $specialty : $this->specialties->update($specialty, ['is_active' => true]);
    }

    /**
     * Two simultaneous writes with the same name both pass validation; the unique index rejects
     * the second, which is reported like any other taken name, on the field that collided (R5).
     *
     * @template T
     *
     * @param  array<string, string>  $names
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardUniqueNames(array $names, Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            foreach (['name_en', 'name_ar'] as $field) {
                if (isset($names[$field]) && $this->specialties->nameTaken($field, $names[$field])) {
                    throw ValidationException::withMessages([
                        $field => __('validation.unique', ['attribute' => __("doctors::attributes.{$field}")]),
                    ]);
                }
            }

            throw $e;
        }
    }
}
