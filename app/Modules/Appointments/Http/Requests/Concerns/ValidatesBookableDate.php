<?php

namespace App\Modules\Appointments\Http\Requests\Concerns;

use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Scheduling\Support\SlotCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * The date and start of a booking or reschedule: formats, and a date from the clinic's today to
 * the 90-day horizon (research R4). Whether the start is an offered slot is the service's check.
 */
trait ValidatesBookableDate
{
    /**
     * @return array<string, mixed>
     */
    protected function bookableDateRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'string', 'regex:'.ClockTime::START_PATTERN],
        ];
    }

    /**
     * A date before today or after the horizon is reported like any other unavailable slot.
     */
    protected function validateBookableDate(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $today = ClinicClock::today();
        $horizon = CarbonImmutable::parse($today)->addDays(SlotCalculator::HORIZON_DAYS)->toDateString();
        $date = $this->input('date');

        if ($date < $today || $date > $horizon) {
            $validator->errors()->add('start', __('appointments::messages.slot_unavailable'));
        }
    }
}
