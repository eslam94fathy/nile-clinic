<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Doctors\Exceptions\SpecialtyNotFoundException;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Repositories\Contracts\SpecialtyRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintaining the specialty catalog (US1, research R5).
 */
class SpecialtyService
{
    public function __construct(
        private readonly SpecialtyRepository $specialties,
        private readonly AuditTrail $audit,
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
        return $this->guardUniqueNames($attributes, fn () => DB::transaction(function () use ($attributes) {
            $specialty = $this->specialties->create([...$attributes, 'is_active' => true]);

            $this->audit->recordChanges(AuditAction::SpecialtyCreated, $specialty, AuditSubject::Specialty);

            return $specialty;
        }));
    }

    /**
     * @param  array{name_en?: string, name_ar?: string}  $attributes
     */
    public function rename(Specialty $specialty, array $attributes): Specialty
    {
        return $this->guardUniqueNames($attributes, fn () => $this->change(AuditAction::SpecialtyUpdated, $specialty, $attributes));
    }

    /**
     * Stop new assignments; doctors already assigned keep it (FR-008). Idempotent.
     */
    public function deactivate(Specialty $specialty): Specialty
    {
        return $specialty->is_active ? $this->change(AuditAction::SpecialtyDeactivated, $specialty, ['is_active' => false]) : $specialty;
    }

    /**
     * Idempotent.
     */
    public function reactivate(Specialty $specialty): Specialty
    {
        return $specialty->is_active ? $specialty : $this->change(AuditAction::SpecialtyReactivated, $specialty, ['is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(AuditAction $action, Specialty $specialty, array $attributes): Specialty
    {
        return DB::transaction(function () use ($action, $specialty, $attributes) {
            $this->specialties->update($specialty, $attributes);

            $this->audit->recordChanges($action, $specialty, AuditSubject::Specialty);

            return $specialty;
        });
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
