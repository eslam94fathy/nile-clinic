<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListTimeOffRequest extends FormRequest
{
    /**
     * Longest range, in days between the two dates (research R7).
     */
    private const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', DoctorTimeOff::class);
    }

    /**
     * Past dates are allowed: time off is also a record (FR-016).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
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
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', __('scheduling::messages.range_too_long', ['days' => self::MAX_RANGE_DAYS]));
                }
            },
        ];
    }
}
