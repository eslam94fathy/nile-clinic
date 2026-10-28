<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Exceptions\PatientNotFoundException;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Registering, finding, and updating patients (US1–US3, research R5, R9).
 */
class PatientService
{
    public function __construct(
        private readonly PatientRepository $patients,
    ) {}

    /**
     * @throws PatientNotFoundException
     */
    public function find(int $id): Patient
    {
        return $this->patients->findById($id) ?? throw new PatientNotFoundException;
    }

    /**
     * @param  array<string, mixed>  $attributes  validated input; emergency_contact is a nested object
     */
    public function register(array $attributes): Patient
    {
        return $this->guardUniqueNationalId(fn () => $this->patients->create($this->toColumns($attributes)));
    }

    /**
     * Map the API's nested emergency_contact object to its three columns (R9); null clears all three.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toColumns(array $attributes): array
    {
        if (! array_key_exists('emergency_contact', $attributes)) {
            return $attributes;
        }

        $contact = $attributes['emergency_contact'];
        unset($attributes['emergency_contact']);

        return [
            ...$attributes,
            'emergency_contact_name' => $contact['name'] ?? null,
            'emergency_contact_relationship' => $contact['relationship'] ?? null,
            'emergency_contact_phone' => $contact['phone'] ?? null,
        ];
    }

    /**
     * Two simultaneous writes with the same national ID both pass validation; the unique index
     * rejects the second, which is reported like any other taken national ID (R5).
     */
    private function guardUniqueNationalId(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'national_id' => __('validation.unique', ['attribute' => __('patients::attributes.national_id')]),
            ]);
        }
    }
}
