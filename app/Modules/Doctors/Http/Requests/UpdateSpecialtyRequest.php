<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Specialty;
use App\Support\ArabicName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Specialty::class);
    }

    /**
     * Clean the names first, so length and uniqueness are checked on the value that is stored (R5).
     */
    protected function prepareForValidation(): void
    {
        foreach (StoreSpecialtyRequest::NAMES as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => ArabicName::clean($this->input($field))]);
            }
        }
    }

    /**
     * Partial update: each name is validated only when sent; neither can be cleared.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $specialty = (int) $this->route('specialty');

        return [
            'name_en' => ['sometimes', 'required', 'string', 'min:1', 'max:100', Rule::unique('specialties', 'name_en')->ignore($specialty)],
            'name_ar' => ['sometimes', 'required', 'string', 'min:1', 'max:100', Rule::unique('specialties', 'name_ar')->ignore($specialty)],
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

    /**
     * At least one name must be sent (contract: minProperties 1).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(StoreSpecialtyRequest::NAMES)) {
                    $validator->errors()->add('fields', __('doctors::messages.nothing_to_update'));
                }
            },
        ];
    }
}
