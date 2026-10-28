<?php

namespace App\Modules\Patients\Support;

/**
 * Phone number rules for patients and emergency contacts (FR-006, research R4).
 */
final class PhoneNumber
{
    /**
     * 8 to 15 digits, optionally starting with "+", after normalize().
     */
    public const PATTERN = '/^\+?[0-9]{8,15}$/';

    /**
     * Remove the spaces, dashes, and parentheses staff may type. Non-strings are returned
     * unchanged so validation can report the wrong type.
     */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/[\s\-()]/u', '', $value) : $value;
    }
}
