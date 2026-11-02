<?php

namespace App\Modules\Appointments\Repositories\Contracts;

use App\Modules\Appointments\Models\Appointment;
use Carbon\CarbonImmutable;

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
}
