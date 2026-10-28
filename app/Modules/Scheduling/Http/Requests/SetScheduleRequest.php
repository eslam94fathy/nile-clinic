<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Scheduling\Support\Weekday;
use App\Modules\Scheduling\Support\WeeklyScheduleRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', DoctorSchedule::class);
    }

    /**
     * Formats only; the rules between periods run in after() (research R5).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointment_minutes' => ['required', 'integer', 'min:5', 'max:240', 'multiple_of:5'],
            'periods' => ['present', 'array', 'max:28'],
            'periods.*.day' => ['required', Rule::in(Weekday::names())],
            'periods.*.start' => ['required', 'string', 'regex:'.ClockTime::START_PATTERN],
            'periods.*.end' => ['required', 'string', 'regex:'.ClockTime::END_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'appointment_minutes' => __('scheduling::attributes.appointment_minutes'),
            'periods' => __('scheduling::attributes.periods'),
            'periods.*.day' => __('scheduling::attributes.day'),
            'periods.*.start' => __('scheduling::attributes.start'),
            'periods.*.end' => __('scheduling::attributes.end'),
        ];
    }

    /**
     * Once every field is well formed, check the periods against each other (FR-003, FR-004).
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

                $periods = array_map(fn (array $period) => [
                    'day' => $period['day'],
                    'start' => ClockTime::toMinutes($period['start']),
                    'end' => ClockTime::toMinutes($period['end']),
                ], $this->input('periods'));

                foreach (WeeklyScheduleRules::problems($periods, (int) $this->input('appointment_minutes')) as $problem) {
                    $validator->errors()->add("periods.{$problem['index']}", __("scheduling::messages.{$problem['rule']}", [
                        'day' => __("scheduling::days.{$problem['day']}"),
                        'max' => WeeklyScheduleRules::MAX_PERIODS_PER_DAY,
                    ]));
                }
            },
        ];
    }

    /**
     * The validated periods as stored: weekday number and minutes since midnight (D3).
     *
     * @return list<array{weekday: int, start_minute: int, end_minute: int}>
     */
    public function periodsInMinutes(): array
    {
        return array_map(fn (array $period) => [
            'weekday' => Weekday::fromName($period['day'])->value,
            'start_minute' => ClockTime::toMinutes($period['start']),
            'end_minute' => ClockTime::toMinutes($period['end']),
        ], $this->validated('periods'));
    }
}
