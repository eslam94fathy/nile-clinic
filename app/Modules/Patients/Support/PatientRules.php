<?php

namespace App\Modules\Patients\Support;

use App\Modules\Patients\Enums\Sex;
use Illuminate\Validation\Rule;

/**
 * The one definition of the patient field rules (data-model.md), shared by the store and update
 * requests. The national ID uniqueness rule differs between them and is added by each request.
 */
class PatientRules
{
    public const NATIONAL_ID_PATTERN = '/^[23][0-9]{13}$/';

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fields(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:'.now()->subYears(130)->toDateString(),
            ],
            'sex' => ['required', Rule::enum(Sex::class)],
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
            'national_id' => ['nullable', 'string', 'regex:'.self::NATIONAL_ID_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => [
                'nullable',
                'array:name,relationship,phone',
                'required_array_keys:name,relationship,phone',
            ],
            'emergency_contact.name' => ['required_with:emergency_contact', 'string', 'max:255'],
            'emergency_contact.relationship' => ['required_with:emergency_contact', 'string', 'max:50'],
            'emergency_contact.phone' => ['required_with:emergency_contact', 'string', 'regex:'.PhoneNumber::PATTERN],
        ];
    }

    /**
     * Translated field names for validation messages (research R11).
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $keys = ['full_name', 'date_of_birth', 'sex', 'phone', 'national_id', 'address', 'emergency_contact'];

        return [
            ...array_combine($keys, array_map(fn (string $key) => __("patients::attributes.{$key}"), $keys)),
            'emergency_contact.name' => __('patients::attributes.emergency_contact_name'),
            'emergency_contact.relationship' => __('patients::attributes.emergency_contact_relationship'),
            'emergency_contact.phone' => __('patients::attributes.emergency_contact_phone'),
        ];
    }

    /**
     * The phone fields with the separators staff may type removed (R4).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizePhones(array $input): array
    {
        $normalized = [];

        if (array_key_exists('phone', $input)) {
            $normalized['phone'] = PhoneNumber::normalize($input['phone']);
        }

        if (is_array($input['emergency_contact'] ?? null) && array_key_exists('phone', $input['emergency_contact'])) {
            $normalized['emergency_contact'] = [
                ...$input['emergency_contact'],
                'phone' => PhoneNumber::normalize($input['emergency_contact']['phone']),
            ];
        }

        return $normalized;
    }
}
