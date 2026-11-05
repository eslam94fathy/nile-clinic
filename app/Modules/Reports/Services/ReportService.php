<?php

namespace App\Modules\Reports\Services;

use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Support\Money;
use App\Modules\Reports\Repositories\Contracts\ReportRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use Carbon\CarbonImmutable;

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
