<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;

class ListDoctorsRequest extends FormRequest
{
    private const FILTERS = ['name', 'specialty_id', 'is_active'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Doctor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
            'specialty_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
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
            array_map(fn (string $key) => __("doctors::attributes.{$key}"), self::FILTERS),
        );
    }

    /**
     * The filters that were sent (R10).
     *
     * @return array{name?: string, specialty_id?: int, is_active?: bool}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FILTERS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        if (isset($filters['specialty_id'])) {
            $filters['specialty_id'] = (int) $filters['specialty_id'];
        }

        if (isset($filters['is_active'])) {
            $filters['is_active'] = $this->boolean('is_active');
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
