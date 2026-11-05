<?php

namespace App\Modules\Reports\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Support\Money;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Reports\Repositories\Contracts\ReportRepository;
use App\Modules\Reports\Support\ReportMath;
use App\Modules\Scheduling\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Turns the repository's aggregates into reports. Read only, so there is no transaction: every total is
 * added up from the same rows as its breakdowns, in whole piastres, so the two cannot disagree (research R6).
 */
class ReportService
{
    private const NO_MONEY = ['received' => 0, 'refunded' => 0];

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly DoctorService $doctors,
    ) {}

    /**
     * Money received and refunded, by method, staff member, and day (US1). Money counts on the clinic day
     * it was recorded (FR-008).
     *
     * @return array<string, mixed>
     */
    public function moneyCollected(string $from, string $to, ?int $doctorId): array
    {
        $this->assertDoctorExists($doctorId);

        [$start, $end] = $this->period($from, $to);

        $totals = self::NO_MONEY;
        $methods = [];
        $days = [];

        $rows = [
            'received' => $this->reports->moneyReceivedByHour($start, $end, $doctorId),
            'refunded' => $this->reports->moneyRefundedByHour($start, $end, $doctorId),
        ];

        foreach ($rows as $figure => $figureRows) {
            foreach ($figureRows as $row) {
                $piastres = Money::toPiastres($row->amount);
                $day = $this->clinicDate($row->hour);

                $totals[$figure] += $piastres;
                $days[$day][$figure] = ($days[$day][$figure] ?? 0) + $piastres;
                $methods[$row->method]['figures'][$figure] = ($methods[$row->method]['figures'][$figure] ?? 0) + $piastres;
                $methods[$row->method]['staff'][$row->staff_id]['name'] = $row->staff_name;
                $methods[$row->method]['staff'][$row->staff_id]['figures'][$figure] = ($methods[$row->method]['staff'][$row->staff_id]['figures'][$figure] ?? 0) + $piastres;
            }
        }

        ksort($days);

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $this->moneyFigures($totals),
            'by_method' => $this->byMethod($methods),
            'by_day' => array_map(
                fn (string $date, array $figures) => ['date' => $date, ...$this->moneyFigures($figures)],
                array_keys($days),
                $days,
            ),
        ];
    }

    /**
     * What each doctor's visits were charged and what is owed, as charges stand now (US2).
     *
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function billing(string $from, string $to, ?int $doctorId, int $perPage): array
    {
        $this->assertDoctorExists($doctorId);

        $empty = ['active_charges' => 0, 'cancelled_charges' => 0, 'fees' => 0, 'discounts' => 0, 'amount_paid' => 0, 'uncharged_seen_visits' => 0];
        $byDoctor = [];

        foreach ($this->reports->billingByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] = [
                ...$empty,
                'active_charges' => (int) $row->active_charges,
                'cancelled_charges' => (int) $row->cancelled_charges,
                'fees' => Money::toPiastres($row->fees),
                'discounts' => Money::toPiastres($row->discounts),
                'amount_paid' => Money::toPiastres($row->amount_paid),
            ];
        }

        foreach ($this->reports->unchargedSeenVisitsByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] = [...($byDoctor[$row->doctor_id] ?? $empty), 'uncharged_seen_visits' => (int) $row->visits];
        }

        $totals = $empty;

        foreach ($byDoctor as $figures) {
            foreach ($figures as $name => $value) {
                $totals[$name] += $value;
            }
        }

        return [
            'rows' => $this->pageByDoctorName(array_map($this->billingFigures(...), $byDoctor), $perPage),
            'totals' => $this->billingFigures($totals),
        ];
    }

    /**
     * @param  array<string, int>  $figures  Counts, and money in piastres.
     * @return array<string, int|string>
     */
    private function billingFigures(array $figures): array
    {
        $due = $figures['fees'] - $figures['discounts'];

        return [
            'active_charges' => $figures['active_charges'],
            'fees' => Money::fromPiastres($figures['fees']),
            'discounts' => Money::fromPiastres($figures['discounts']),
            'amount_due' => Money::fromPiastres($due),
            'amount_paid' => Money::fromPiastres($figures['amount_paid']),
            'balance' => Money::fromPiastres($due - $figures['amount_paid']),
            'cancelled_charges' => $figures['cancelled_charges'],
            'uncharged_seen_visits' => $figures['uncharged_seen_visits'],
        ];
    }

    /**
     * Appointments, the no-show rate, visits, and new patients (US3). New patients are overall only, so they
     * are left out when a doctor filter is sent (FR-014).
     *
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function activity(string $from, string $to, ?int $doctorId, int $perPage): array
    {
        $this->assertDoctorExists($doctorId);

        $empty = [
            'appointments' => array_fill_keys(AppointmentStatus::values(), 0),
            'visits' => array_fill_keys(QueueStatus::values(), 0),
            'booked' => 0,
        ];
        $byDoctor = [];
        $totals = $empty;

        foreach ($this->reports->appointmentsByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] ??= $empty;
            $byDoctor[$row->doctor_id]['appointments'][$row->status] = (int) $row->count;
            $totals['appointments'][$row->status] += (int) $row->count;
        }

        foreach ($this->reports->visitsByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] ??= $empty;
            $byDoctor[$row->doctor_id]['visits'][$row->status] = (int) $row->count;
            $byDoctor[$row->doctor_id]['booked'] += (int) $row->booked;
            $totals['visits'][$row->status] += (int) $row->count;
            $totals['booked'] += (int) $row->booked;
        }

        $totals = $this->activityFigures($totals);

        if ($doctorId === null) {
            [$start, $end] = $this->period($from, $to);
            $totals['new_patients'] = $this->reports->patientsRegistered($start, $end);
        }

        return [
            'rows' => $this->pageByDoctorName(array_map($this->activityFigures(...), $byDoctor), $perPage),
            'totals' => $totals,
        ];
    }

    /**
     * @param  array{appointments: array<string, int>, visits: array<string, int>, booked: int}  $counts
     * @return array<string, mixed>
     */
    private function activityFigures(array $counts): array
    {
        $appointments = $counts['appointments'];

        return [
            'appointments' => $appointments,
            'no_show_rate' => ReportMath::noShowRate(
                $appointments[AppointmentStatus::NoShow->value],
                $appointments[AppointmentStatus::CheckedIn->value],
                $appointments[AppointmentStatus::Completed->value],
            ),
            'visits' => [
                ...$counts['visits'],
                'booked' => $counts['booked'],
                'walk_in' => array_sum($counts['visits']) - $counts['booked'],
            ],
        ];
    }

    /**
     * Average wait and consultation, and counts of medical records (US4). Overall averages come from all the
     * seconds and visits, never from the doctors' averages (research R8).
     *
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function times(string $from, string $to, ?int $doctorId, int $perPage): array
    {
        $this->assertDoctorExists($doctorId);

        $empty = [
            'wait_seconds' => 0,
            'wait_visits' => 0,
            'consultation_seconds' => 0,
            'consultation_visits' => 0,
            'finalized' => 0,
            'draft' => 0,
            'none' => 0,
        ];
        $byDoctor = [];

        foreach ($this->reports->visitTimesByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] = [
                ...$empty,
                'wait_seconds' => (int) $row->wait_seconds,
                'wait_visits' => (int) $row->wait_visits,
                'consultation_seconds' => (int) $row->consultation_seconds,
                'consultation_visits' => (int) $row->consultation_visits,
            ];
        }

        foreach ($this->reports->recordsByDoctor($from, $to, $doctorId) as $row) {
            $byDoctor[$row->doctor_id] ??= $empty;
            $byDoctor[$row->doctor_id][$row->record_status ?? 'none'] = (int) $row->count;
        }

        $totals = $empty;

        foreach ($byDoctor as $figures) {
            foreach ($figures as $name => $value) {
                $totals[$name] += $value;
            }
        }

        return [
            'rows' => $this->pageByDoctorName(array_map($this->timesFigures(...), $byDoctor), $perPage),
            'totals' => $this->timesFigures($totals),
        ];
    }

    /**
     * @param  array<string, int>  $figures
     * @return array<string, array<string, int|null>>
     */
    private function timesFigures(array $figures): array
    {
        return [
            'wait' => [
                'average_minutes' => ReportMath::averageMinutes($figures['wait_seconds'], $figures['wait_visits']),
                'visits' => $figures['wait_visits'],
            ],
            'consultation' => [
                'average_minutes' => ReportMath::averageMinutes($figures['consultation_seconds'], $figures['consultation_visits']),
                'visits' => $figures['consultation_visits'],
            ],
            'records' => ['finalized' => $figures['finalized'], 'draft' => $figures['draft'], 'none' => $figures['none']],
        ];
    }

    /**
     * One page of rows, each with its doctor, by account name. Rows are bounded by the number of doctors, and a
     * doctor can come from any of several queries, so paging happens here rather than in SQL (research R7).
     *
     * @param  array<int, array<string, mixed>>  $rowsByDoctor
     */
    private function pageByDoctorName(array $rowsByDoctor, int $perPage): LengthAwarePaginator
    {
        $rows = array_map(
            fn (object $doctor) => [
                'doctor' => ['id' => $doctor->id, 'name' => $doctor->name, 'title' => $doctor->title],
                ...$rowsByDoctor[$doctor->id],
            ],
            $this->reports->doctorsByName(array_keys($rowsByDoctor)),
        );

        $page = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * @param  array<string, array<string, mixed>>  $methods
     * @return list<array<string, mixed>>
     */
    private function byMethod(array $methods): array
    {
        $result = [];

        foreach (PaymentMethod::cases() as $case) {
            if (! isset($methods[$case->value])) {
                continue;
            }

            $method = $methods[$case->value];
            $staff = [];

            foreach ($method['staff'] as $id => $member) {
                $staff[] = ['id' => $id, 'name' => $member['name'], ...$this->moneyFigures($member['figures'])];
            }

            usort($staff, fn (array $a, array $b) => strcmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

            $result[] = ['method' => $case->value, ...$this->moneyFigures($method['figures']), 'staff' => $staff];
        }

        return $result;
    }

    /**
     * @param  array{received?: int, refunded?: int}  $piastres
     * @return array{received: string, refunded: string, net: string}
     */
    private function moneyFigures(array $piastres): array
    {
        $received = $piastres['received'] ?? 0;
        $refunded = $piastres['refunded'] ?? 0;

        return [
            'received' => Money::fromPiastres($received),
            'refunded' => Money::fromPiastres($refunded),
            'net' => Money::fromPiastres($received - $refunded),
        ];
    }

    /**
     * The UTC instants a period of clinic dates covers, the end excluded. The day after $to is worked out on
     * the date alone, so a clock change cannot shift it (research R4).
     *
     * @return array{string, string}
     */
    private function period(string $from, string $to): array
    {
        $nextDay = CarbonImmutable::parse($to, 'UTC')->addDay()->toDateString();

        return [$this->startOfClinicDay($from), $this->startOfClinicDay($nextDay)];
    }

    private function startOfClinicDay(string $date): string
    {
        return CarbonImmutable::parse("{$date} 00:00:00", ClinicClock::timezone())->utc()->format('Y-m-d H:i:s');
    }

    private function clinicDate(string $utcHour): string
    {
        return CarbonImmutable::parse($utcHour, 'UTC')->setTimezone(ClinicClock::timezone())->toDateString();
    }

    private function assertDoctorExists(?int $doctorId): void
    {
        if ($doctorId !== null) {
            $this->doctors->find($doctorId);
        }
    }
}
