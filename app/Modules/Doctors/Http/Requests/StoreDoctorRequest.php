<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Doctor;
use App\Support\ArabicName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDoctorRequest extends FormRequest
{
    public const REGISTRATION_NUMBER_PATTERN = '/^[A-Za-z0-9]{1,30}$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Doctor::class);
    }

    /**
     * Clean the title first, so its length is checked on the value that is stored.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => ArabicName::clean($this->input('title'))]);
        }
    }

    /**
     * Whether the account is active with the doctor role is checked by DoctorService (R7).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::unique('doctors', 'user_id')],
            'title' => ['required', 'string', 'min:1', 'max:100'],
            'registration_number' => ['nullable', 'string', 'regex:'.self::REGISTRATION_NUMBER_PATTERN, Rule::unique('doctors', 'registration_number')],
            'specialty_ids' => ['required', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::attributeNames();
    }

    /**
     * @return array<string, string>
     */
    public static function attributeNames(): array
    {
        return [
            'user_id' => __('doctors::attributes.user_id'),
            'title' => __('doctors::attributes.title'),
            'registration_number' => __('doctors::attributes.registration_number'),
            'specialty_ids' => __('doctors::attributes.specialty_ids'),
            'specialty_ids.*' => __('doctors::attributes.specialty_id'),
        ];
    }
}
