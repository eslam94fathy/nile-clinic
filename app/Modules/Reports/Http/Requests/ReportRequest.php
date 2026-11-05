<?php

namespace App\Modules\Reports\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The period and doctor filter shared by every report (FR-002, FR-003). Refuses anyone but an active
 * administrator before validating (research R9, R10).
 */
class ReportRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 366;

    private const FIELDS = ['from', 'to', 'doctor_id'];

    public function authorize(): bool
    {
        return $this->user()->can('view-reports');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(
            self::FIELDS,
            array_map(fn (string $key) => __("reports::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * At most MAX_RANGE_DAYS days, including both ends.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $days = CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) + 1;

            if ($days > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', __('reports::messages.range_too_long', ['days' => self::MAX_RANGE_DAYS]));
            }
        }];
    }

    public function from(): string
    {
        return $this->validated('from');
    }

    public function to(): string
    {
        return $this->validated('to');
    }

    public function doctorId(): ?int
    {
        $doctorId = $this->validated('doctor_id');

        return $doctorId === null ? null : (int) $doctorId;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
