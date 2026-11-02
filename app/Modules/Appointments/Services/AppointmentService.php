<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Finding appointments (US2) and changing their status (US3, US4).
 */
class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
    ) {}

    /**
     * @param  array{from: string, to: string, doctor_id?: int, patient_id?: int, status?: string, outside_availability?: bool}  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->appointments->paginate($filters, ClinicClock::now(), $perPage);
    }

    /**
     * @throws AppointmentNotFoundException
     */
    public function find(int $id): Appointment
    {
        return $this->appointments->find($id, ClinicClock::now()) ?? throw new AppointmentNotFoundException;
    }
}
