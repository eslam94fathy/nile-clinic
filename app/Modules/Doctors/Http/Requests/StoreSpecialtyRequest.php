<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Specialty;
use App\Support\ArabicName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpecialtyRequest extends FormRequest
{
    public const NAMES = ['name_en', 'name_ar'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Specialty::class);
    }

    /**
     * Clean the names first, so length and uniqueness are checked on the value that is stored (R5).
     */
    protected function prepareForValidation(): void
    {
        foreach (self::NAMES as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => ArabicName::clean($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'min:1', 'max:100', Rule::unique('specialties', 'name_en')],
            'name_ar' => ['required', 'string', 'min:1', 'max:100', Rule::unique('specialties', 'name_ar')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name_en' => __('doctors::attributes.name_en'),
            'name_ar' => __('doctors::attributes.name_ar'),
        ];
    }
}
