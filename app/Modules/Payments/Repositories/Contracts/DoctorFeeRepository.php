<?php

namespace App\Modules\Payments\Repositories\Contracts;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\DoctorFee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface DoctorFeeRepository
{
    /**
     * The doctor's current fee with who set it, or null.
     */
    public function findForDoctor(int $doctorId): ?DoctorFee;

    /**
     * Set or replace the doctor's fee. An upsert, so simultaneous first settings do not collide
     * (research R7).
     */
    public function set(int $doctorId, string $amount, int $userId): void;

    /**
     * One page of doctors by account name, then id, with their accounts loaded.
     *
     * @return LengthAwarePaginator<int, Doctor>
     */
    public function paginateDoctors(int $perPage): LengthAwarePaginator;

    /**
     * The current fees of these doctors with who set them, keyed by doctor id.
     *
     * @param  list<int>  $doctorIds
     * @return Collection<int, DoctorFee>
     */
    public function forDoctors(array $doctorIds): Collection;
}
