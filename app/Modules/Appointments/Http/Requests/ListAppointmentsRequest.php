<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAppointmentsRequest extends FormRequest
{
    /**
     * Longest listing range, in days including both ends (FR-008, research R10).
     */
    public const MAX_RANGE_DAYS = 92;

    private const FIELDS = ['from', 'to', 'doctor_id', 'patient_id', 'status', 'outside_availability'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Appointment::class);
    }

    /**
     * Past dates are allowed: listings include history (research R10).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(AppointmentStatus::values())],
            'outside_availability' => ['nullable', 'boolean'],
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
            array_map(fn (string $key) => __("appointments::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::validateRange($validator, $this->input('from'), $this->input('to'))];
    }

    /**
     * At most MAX_RANGE_DAYS days including both ends; shared with the doctor's own list.
     */
    public static function validateRange(Validator $validator, mixed $from, mixed $to): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_RANGE_DAYS) {
            $validator->errors()->add('to', __('appointments::messages.range_too_long', ['days' => self::MAX_RANGE_DAYS]));
        }
    }

    /**
     * The range and the filters that were sent, typed (research R10).
     *
     * @return array{from: string, to: string, doctor_id?: int, patient_id?: int, status?: string, outside_availability?: bool}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FIELDS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        foreach (['doctor_id', 'patient_id'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        if (isset($filters['outside_availability'])) {
            $filters['outside_availability'] = $this->boolean('outside_availability');
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
