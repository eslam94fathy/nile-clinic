<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Specialty;
use Illuminate\Foundation\Http\FormRequest;

class ListSpecialtiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Specialty::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => __('doctors::attributes.is_active')];
    }

    /**
     * The status filter, or null to list every specialty.
     */
    public function isActive(): ?bool
    {
        return $this->filled('is_active') ? $this->boolean('is_active') : null;
    }
}
