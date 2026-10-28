<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DoctorTimeOff::class);
    }

    /**
     * Whole days (no times) or part of one day (both times). "Today" is the clinic's today
     * (research R2, R7).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.ClinicClock::today()],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'required_with:end_time', 'string', 'regex:'.ClockTime::START_PATTERN],
            'end_time' => ['nullable', 'required_with:start_time', 'string', 'regex:'.ClockTime::END_PATTERN],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(
            ['start_date', 'end_date', 'start_time', 'end_time', 'reason'],
            array_map(fn (string $key) => __("scheduling::attributes.{$key}"), ['start_date', 'end_date', 'start_time', 'end_time', 'reason']),
        );
    }

    /**
     * Times only for a single day, and the end after the start (FR-013, FR-014).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('start_time')) {
                    return;
                }

                if ($this->input('start_date') !== $this->input('end_date')) {
                    $validator->errors()->add('start_time', __('scheduling::messages.time_off_times_single_day'));
                } elseif (ClockTime::toMinutes($this->input('end_time')) <= ClockTime::toMinutes($this->input('start_time'))) {
                    $validator->errors()->add('end_time', __('validation.after', [
                        'attribute' => __('scheduling::attributes.end_time'),
                        'date' => __('scheduling::attributes.start_time'),
                    ]));
                }
            },
        ];
    }
}
