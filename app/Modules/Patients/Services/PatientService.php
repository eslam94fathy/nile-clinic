<?php

namespace App\Modules\Patients\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Patients\Exceptions\PatientNotFoundException;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registering, finding, and updating patients (US1–US3, research R5, R9).
 */
class PatientService
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{name?: string, phone?: string, national_id?: string, id?: int}  $filters
     * @return LengthAwarePaginator<int, Patient>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->patients->paginate($filters, $perPage);
    }

    /**
     * @throws PatientNotFoundException
     */
    public function find(int $id): Patient
    {
        return $this->patients->findById($id) ?? throw new PatientNotFoundException;
    }

    /**
     * Find a patient for a staff member to read, leaving an entry. If the entry cannot be saved, the
     * patient is not returned (FR-004, FR-007). `find` stays free of this, because writes and other
     * modules call it.
     *
     * @throws PatientNotFoundException
     */
    public function open(int $id): Patient
    {
        $patient = $this->find($id);

        $this->audit->record(AuditAction::PatientOpened, AuditSubject::Patient, $id, $id);

        return $patient;
    }

    /**
     * @param  array<string, mixed>  $attributes  validated input; emergency_contact is a nested object
     */
    public function register(array $attributes): Patient
    {
        return $this->guardUniqueNationalId(fn () => DB::transaction(function () use ($attributes) {
            $patient = $this->patients->create($this->toColumns($attributes));

            $this->audit->recordChanges(AuditAction::PatientRegistered, $patient, AuditSubject::Patient, patientId: $patient->id);

            return $patient;
        }));
    }

    /**
     * Change only the fields sent; the patient number never changes (FR-010).
     *
     * @param  array<string, mixed>  $attributes  validated input; emergency_contact is a nested object or null
     */
    public function update(Patient $patient, array $attributes): Patient
    {
        return $this->guardUniqueNationalId(fn () => DB::transaction(function () use ($patient, $attributes) {
            $this->patients->update($patient, $this->toColumns($attributes));

            $this->audit->recordChanges(AuditAction::PatientUpdated, $patient, AuditSubject::Patient, patientId: $patient->id);

            return $patient;
        }));
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
