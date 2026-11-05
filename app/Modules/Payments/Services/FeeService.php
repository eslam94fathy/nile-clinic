<?php

namespace App\Modules\Payments\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Repositories\Contracts\DoctorFeeRepository;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Doctors' current consultation fees (FR-001, FR-002). A new fee applies to later charges only;
 * charges keep the fee they were created with (FR-005).
 */
class FeeService
{
    public function __construct(
        private readonly DoctorFeeRepository $fees,
        private readonly DoctorService $doctors,
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

        $this->fees->set($doctorId, $amount, $actor->id);

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
