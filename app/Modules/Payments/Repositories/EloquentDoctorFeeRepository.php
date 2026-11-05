<?php

namespace App\Modules\Payments\Repositories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Repositories\Contracts\DoctorFeeRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentDoctorFeeRepository implements DoctorFeeRepository
{
    public function findForDoctor(int $doctorId): ?DoctorFee
    {
        return DoctorFee::query()->with('setBy')->where('doctor_id', $doctorId)->first();
    }

    public function set(int $doctorId, string $amount, int $userId): void
    {
        DoctorFee::query()->upsert(
            [['doctor_id' => $doctorId, 'amount' => $amount, 'set_by' => $userId]],
            ['doctor_id'],
            ['amount', 'set_by'],
        );
    }

    public function paginateDoctors(int $perPage): LengthAwarePaginator
    {
        return Doctor::query()
            ->select('doctors.*')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->with('user')
            ->orderBy('users.name')
            ->orderBy('doctors.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function forDoctors(array $doctorIds): Collection
    {
        return DoctorFee::query()->with('setBy')->whereIn('doctor_id', $doctorIds)->get()->keyBy('doctor_id');
    }
}
