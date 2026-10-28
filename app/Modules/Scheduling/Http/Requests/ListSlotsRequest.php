<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\SlotCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', DoctorSchedule::class);
    }

    /**
     * "Today" is the clinic's today, not the server's (research R2).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.ClinicClock::today()],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('scheduling::attributes.from'),
            'to' => __('scheduling::attributes.to'),
        ];
    }

    /**
     * At most 31 days per request, and no further ahead than the booking horizon (FR-010).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = CarbonImmutable::parse($this->input('from'));
                $to = CarbonImmutable::parse($this->input('to'));

                if ($from->diffInDays($to) + 1 > SlotCalculator::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', __('scheduling::messages.range_too_long', ['days' => SlotCalculator::MAX_RANGE_DAYS]));
                } elseif ($to->toDateString() > CarbonImmutable::parse(ClinicClock::today())->addDays(SlotCalculator::HORIZON_DAYS)->toDateString()) {
                    $validator->errors()->add('to', __('scheduling::messages.beyond_horizon', ['days' => SlotCalculator::HORIZON_DAYS]));
                }
            },
        ];
    }
}
