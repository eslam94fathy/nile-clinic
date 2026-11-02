<?php

namespace App\Modules\Appointments\Repositories\Contracts;

use App\Modules\Appointments\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AppointmentRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Appointment;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Appointment $appointment, array $attributes): Appointment;

    /**
     * The appointment row locked `FOR UPDATE` until the caller's transaction ends, or null. No
     * relations are loaded (research R3, R5).
     */
    public function lockById(int $id): ?Appointment;

    /**
     * Whether the doctor has a scheduled appointment on this date overlapping [start, end),
     * other than $exceptId (research R4).
     */
    public function doctorOverlaps(int $doctorId, string $date, int $start, int $end, ?int $exceptId = null): bool;

    /**
     * Whether the patient has a scheduled appointment, with any doctor, on this date overlapping
     * [start, end), other than $exceptId (research R4).
     */
    public function patientOverlaps(int $patientId, string $date, int $start, int $end, ?int $exceptId = null): bool;

    /**
     * With patient, doctor and its account, and the staff who booked, rescheduled, or changed its
     * status loaded, and the computed outside_availability column as of $now (research R7).
     */
    public function find(int $id, CarbonImmutable $now): ?Appointment;

    /**
     * One page of appointments with date from..to (inclusive), ordered by date, start, then id,
     * with patient and doctor (and its account) loaded and outside_availability computed as of
     * $now (research R7, R10). Optional filter keys, combined with AND; absent keys are not
     * applied.
     *
     * @param  array{from: string, to: string, doctor_id?: int, patient_id?: int, status?: string, outside_availability?: bool}  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(array $filters, CarbonImmutable $now, int $perPage): LengthAwarePaginator;

    /**
     * One page of this doctor's appointments with date from..to (inclusive), ordered by date,
     * start, then id, with the patient loaded and outside_availability computed as of $now
     * (FR-015). Optional status filter.
     *
     * @param  array{from: string, to: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginateForDoctor(int $doctorId, array $filters, CarbonImmutable $now, int $perPage): LengthAwarePaginator;

    /**
     * The appointment with the patient loaded and outside_availability computed, or null when
     * there is none or it belongs to another doctor (research R8).
     */
    public function findForDoctor(int $doctorId, int $id, CarbonImmutable $now): ?Appointment;
}
