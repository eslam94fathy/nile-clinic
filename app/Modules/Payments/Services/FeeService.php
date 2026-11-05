<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Repositories\Contracts\DoctorFeeRepository;
use App\Modules\Payments\Support\Money;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Doctors' current consultation fees (FR-001, FR-002). A new fee applies to later charges only;
 * charges keep the fee they were created with (FR-005).
 */
class FeeService
{
    public function __construct(
        private readonly DoctorFeeRepository $fees,
        private readonly DoctorService $doctors,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @return array{doctor: Doctor, fee: ?DoctorFee}
     *
     * @throws DoctorNotFoundException
     */
    public function find(int $doctorId): array
    {
        return ['doctor' => $this->doctors->find($doctorId), 'fee' => $this->fees->findForDoctor($doctorId)];
    }

    /**
     * @return array{doctor: Doctor, fee: ?DoctorFee}
     *
     * @throws DoctorNotFoundException
     */
    public function set(User $actor, int $doctorId, string $amount): array
    {
        $this->doctors->find($doctorId);

        DB::transaction(function () use ($actor, $doctorId, $amount) {
            // An upsert leaves no model changes to read, so the old amount is read first (research R4).
            $old = $this->fees->findForDoctor($doctorId)?->amount;

            $this->fees->set($doctorId, $amount, $actor->id);

            $new = Money::fromPiastres(Money::toPiastres($amount));

            if ($old !== $new) {
                $this->audit->record(AuditAction::FeeSet, AuditSubject::Doctor, $doctorId, details: [
                    'fields' => ['fee'],
                    'values' => ['fee' => ['from' => $old, 'to' => $new]],
                ]);
            }
        });

        return $this->find($doctorId);
    }

    /**
     * One page of doctors, each with their fee or null.
     *
     * @return LengthAwarePaginator<int, array{doctor: Doctor, fee: ?DoctorFee}>
     */
    public function list(int $perPage): LengthAwarePaginator
    {
        $page = $this->fees->paginateDoctors($perPage);
        $fees = $this->fees->forDoctors($page->getCollection()->modelKeys());

        return $page->through(fn (Doctor $doctor) => ['doctor' => $doctor, 'fee' => $fees->get($doctor->id)]);
    }
}
