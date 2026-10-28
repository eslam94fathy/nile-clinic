<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientRules;
use App\Modules\Patients\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ListPatientsRequest extends FormRequest
{
    private const FILTERS = ['name', 'phone', 'national_id', 'id'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.PhoneNumber::PATTERN],
            'national_id' => ['nullable', 'string', 'regex:'.PatientRules::NATIONAL_ID_PATTERN],
            'id' => ['nullable', 'integer', 'min:1'],
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
            self::FILTERS,
            array_map(fn (string $key) => __("patients::attributes.{$key}"), self::FILTERS),
        );
    }

    /**
     * The search filters that were sent (R8).
     *
     * @return array{name?: string, phone?: string, national_id?: string, id?: int}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FILTERS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        if (isset($filters['id'])) {
            $filters['id'] = (int) $filters['id'];
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
